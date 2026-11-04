<?php

namespace App\Domain\Flyers\Ai;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Messages\Message;

/**
 * Reads flyer pages with Claude (official anthropic-ai/sdk): the page picture plus the PDF's own text,
 * strict JSON output (FlyerSchema), the fixed instructions cached between pages, and no sampling
 * parameters (API version 2026-10-21 rejects them). Thinking follows config('flyers.thinking').
 * Batches go through the Message Batches API at half price.
 */
final class ClaudeFlyerReader implements BatchFlyerReader
{
    /** Opt-in server-side retry on Anthropic's default fallback model when the model declines a page. */
    private const string FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    public function __construct(
        private readonly Client $client,
        private readonly string $model,
        private readonly string $effort,
    ) {}

    public function provider(): string
    {
        return 'claude';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function extract(PageInput $input): PageResult
    {
        // ExtractFlyerPage retries busy or failed requests itself, within its time limit.
        $options = ['timeout' => (float) config('flyers.request_timeout'), 'maxRetries' => 0];
        $message = $this->call(fn () => config('flyers.claude_server_fallback')
            ? $this->client->beta->messages->create(...$this->params($input), fallbacks: 'default', betas: [self::FALLBACK_BETA], requestOptions: $options)
            : $this->client->messages->create(...$this->params($input), requestOptions: $options));

        return $this->result($message);
    }

    public function submitBatch(array $inputs): string
    {
        $requests = [];
        foreach ($inputs as $customId => $input) {
            $requests[] = ['customID' => (string) $customId, 'params' => $this->params($input)];
        }

        return $this->call(fn () => $this->client->messages->batches->create(requests: $requests))->id;
    }

    public function batchEnded(string $batchId): bool
    {
        return $this->call(fn () => $this->client->messages->batches->retrieve($batchId))->processingStatus === 'ended';
    }

    public function batchResults(string $batchId): iterable
    {
        foreach ($this->call(fn () => $this->client->messages->batches->resultsStream($batchId)) as $response) {
            try {
                yield $response->customID => match ($response->result->type) {
                    'succeeded' => $this->result($response->result->message),
                    default => throw new FlyerReadFailed("The batch request {$response->result->type}.", FlyerReadFailed::API, model: $this->model, retryable: true),
                };
            } catch (FlyerReadFailed $e) {
                yield $response->customID => $e;
            }
        }
    }

    /** The request for one page, shared by single calls and batches. */
    private function params(PageInput $input): array
    {
        return array_filter([
            'maxTokens' => (int) config('flyers.max_output_tokens'),
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $input->mimeType, 'data' => base64_encode($input->image)]],
                    ['type' => 'text', 'text' => FlyerPrompt::page($input)],
                ],
            ]],
            'model' => $this->model,
            'system' => [['type' => 'text', 'text' => FlyerPrompt::SYSTEM, 'cacheControl' => ['type' => 'ephemeral']]],
            'outputConfig' => ['effort' => $this->effort, 'format' => ['type' => 'json_schema', 'schema' => FlyerSchema::schema()]],
            'thinking' => config("flyers.thinking.{$this->model}"),
        ], fn ($value) => $value !== null);
    }

    private function result(Message|BetaMessage $message): PageResult
    {
        $usage = new TokenUsage(
            input: $message->usage->inputTokens,
            cacheWrite: (int) $message->usage->cacheCreationInputTokens,
            cacheRead: (int) $message->usage->cacheReadInputTokens,
            output: $message->usage->outputTokens,
        );
        // A fallback model may have answered; price what actually ran.
        $model = $message->model ?: $this->model;

        match ($message->stopReason) {
            'refusal' => throw new FlyerReadFailed('Claude declined to read this page.', FlyerReadFailed::REFUSAL, $usage, $model, $message->id),
            'max_tokens' => throw new FlyerReadFailed('The answer was cut off at the output limit.', FlyerReadFailed::TRUNCATED, $usage, $model, $message->id),
            default => null,
        };

        $text = collect($message->content)->first(fn ($block) => $block->type === 'text')?->text;

        return PageResult::fromAnswer(json_decode((string) $text, true), $usage, $model, $message->id);
    }

    /** Runs an SDK call, turning its errors into FlyerReadFailed (busy or unreachable: worth retrying). */
    private function call(callable $request): mixed
    {
        try {
            return $request();
        } catch (RateLimitException|InternalServerException|APIConnectionException $e) {
            throw new FlyerReadFailed('Claude is busy or unreachable: '.$e->getMessage(), FlyerReadFailed::API, model: $this->model, retryable: true);
        } catch (APIStatusException $e) {
            // 400 (bad request), 401 (key), 403, 404 (model name)…: retrying won't help.
            throw new FlyerReadFailed('Claude refused the request: '.$e->getMessage(), FlyerReadFailed::API, model: $this->model);
        } catch (APIException $e) {
            throw new FlyerReadFailed('Claude request failed: '.$e->getMessage(), FlyerReadFailed::API, model: $this->model, retryable: true);
        }
    }
}
