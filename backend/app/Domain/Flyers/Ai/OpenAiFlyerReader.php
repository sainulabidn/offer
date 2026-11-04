<?php

namespace App\Domain\Flyers\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Reads flyer pages with OpenAI's Chat Completions API over plain HTTP: the page picture plus the PDF's
 * own text, and strict JSON-schema output (every key required, null allowed). Effort maps to
 * reasoning_effort; max_completion_tokens covers reasoning and the answer. Batches go through the Batch
 * API (a JSONL file of requests, half price, within 24 h).
 */
final class OpenAiFlyerReader implements BatchFlyerReader
{
    private const string ENDPOINT = '/v1/chat/completions';

    public function __construct(
        private readonly string $key,
        private readonly string $model,
        private readonly string $effort,
        private readonly string $baseUrl = 'https://api.openai.com/v1',
    ) {}

    public function provider(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function extract(PageInput $input): PageResult
    {
        // ExtractFlyerPage retries busy or failed requests itself, within its time limit.
        $response = $this->send(fn (PendingRequest $http) => $http->post($this->url('/chat/completions'), $this->body($input)));

        return $this->result($response->json());
    }

    public function submitBatch(array $inputs): string
    {
        $lines = [];
        foreach ($inputs as $customId => $input) {
            $lines[] = json_encode(['custom_id' => (string) $customId, 'method' => 'POST', 'url' => self::ENDPOINT, 'body' => $this->body($input)]);
        }
        $file = $this->send(fn (PendingRequest $http) => $http->attach('file', implode("\n", $lines), 'flyer-pages.jsonl')
            ->post($this->url('/files'), ['purpose' => 'batch']));

        return (string) $this->send(fn (PendingRequest $http) => $http->post($this->url('/batches'), [
            'input_file_id' => $file->json('id'),
            'endpoint' => self::ENDPOINT,
            'completion_window' => '24h',
        ]))->json('id');
    }

    public function batchEnded(string $batchId): bool
    {
        return in_array($this->batch($batchId)->json('status'), ['completed', 'failed', 'expired', 'cancelled'], true);
    }

    public function batchResults(string $batchId): iterable
    {
        $batch = $this->batch($batchId);
        foreach (array_filter([$batch->json('output_file_id'), $batch->json('error_file_id')]) as $fileId) {
            $content = $this->send(fn (PendingRequest $http) => $http->get($this->url("/files/{$fileId}/content")))->body();
            foreach (preg_split('/\R/', trim($content)) as $line) {
                $row = json_decode($line, true);
                if (! is_array($row) || ! isset($row['custom_id'])) {
                    continue;
                }
                try {
                    if (($row['response']['status_code'] ?? 0) !== 200) {
                        throw new FlyerReadFailed('The batch request failed: '.($row['error']['message'] ?? $row['response']['body']['error']['message'] ?? 'no answer'), FlyerReadFailed::API, model: $this->model, retryable: true);
                    }
                    yield $row['custom_id'] => $this->result($row['response']['body'], $row['response']['request_id'] ?? null);
                } catch (FlyerReadFailed $e) {
                    yield $row['custom_id'] => $e;
                }
            }
        }
    }

    /** The request for one page, shared by single calls and batches. */
    private function body(PageInput $input): array
    {
        return [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => FlyerPrompt::SYSTEM],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => FlyerPrompt::page($input)],
                    ['type' => 'image_url', 'image_url' => ['url' => "data:{$input->mimeType};base64,".base64_encode($input->image), 'detail' => 'high']],
                ]],
            ],
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'flyer_page', 'strict' => true, 'schema' => FlyerSchema::schema(allRequired: true)]],
            'reasoning_effort' => $this->effort,
            'max_completion_tokens' => (int) config('flyers.max_output_tokens'),
        ];
    }

    /** @param  array<string, mixed>  $completion  a chat.completion object */
    private function result(array $completion, ?string $requestId = null): PageResult
    {
        $get = fn (string $key) => data_get($completion, $key);
        $usage = new TokenUsage(
            input: (int) $get('usage.prompt_tokens') - (int) $get('usage.prompt_tokens_details.cached_tokens'),
            cacheRead: (int) $get('usage.prompt_tokens_details.cached_tokens'),
            output: (int) $get('usage.completion_tokens'),
        );
        $model = (string) ($get('model') ?: $this->model);
        $requestId ??= $get('id');

        if (filled($get('choices.0.message.refusal'))) {
            throw new FlyerReadFailed('OpenAI declined to read this page.', FlyerReadFailed::REFUSAL, $usage, $model, $requestId);
        }
        if ($get('choices.0.finish_reason') === 'length') {
            throw new FlyerReadFailed('The answer was cut off at the output limit.', FlyerReadFailed::TRUNCATED, $usage, $model, $requestId);
        }

        return PageResult::fromAnswer(json_decode((string) $get('choices.0.message.content'), true), $usage, $model, $requestId);
    }

    private function batch(string $batchId): Response
    {
        return $this->send(fn (PendingRequest $http) => $http->get($this->url("/batches/{$batchId}")));
    }

    /** @param  callable(PendingRequest): Response  $request */
    private function send(callable $request): Response
    {
        try {
            $response = $request(Http::withToken($this->key)->timeout((int) config('flyers.request_timeout')));
        } catch (ConnectionException $e) {
            throw new FlyerReadFailed('OpenAI is unreachable: '.$e->getMessage(), FlyerReadFailed::API, model: $this->model, retryable: true);
        }
        if ($response->failed()) {
            throw new FlyerReadFailed(
                'OpenAI refused the request: '.($response->json('error.message') ?? $response->status()),
                FlyerReadFailed::API,
                model: $this->model,
                retryable: $response->status() === 429 || $response->serverError(),
            );
        }

        return $response;
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
