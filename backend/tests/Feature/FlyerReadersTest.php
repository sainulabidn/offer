<?php

use Anthropic\Client;
use App\Domain\Catalog\PackSize;
use App\Domain\Flyers\Ai\ClaudeFlyerReader;
use App\Domain\Flyers\Ai\FlyerReadFailed;
use App\Domain\Flyers\Ai\FlyerSchema;
use App\Domain\Flyers\Ai\OpenAiFlyerReader;
use App\Domain\Flyers\Ai\PageInput;
use App\Domain\Flyers\Ai\TokenUsage;
use App\Domain\Flyers\CostCalculator;
use App\Domain\Flyers\FlyerSettings;
use Database\Seeders\UnitSeeder;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

$answer = ['page' => 'offers', 'from' => '2026-11-04', 'until' => '2026-11-10', 'where' => ['Al Qusais'], 'items' => [[
    'name' => 'Almarai Full Fat Fresh Milk', 'ar' => null, 'brand' => 'Almarai', 'size' => '2 L', 'price' => 9.95, 'was' => 11.5,
    'promo' => 'was_now', 'qty' => null, 'free' => null, 'pct' => null, 'limit' => 2, 'card' => false, 'note' => null,
    'barcode' => null, 'box' => [10, 20, 300, 400], 'sure' => 0.9,
]]];

function flyerPage(): PageInput
{
    return new PageInput('jpeg-bytes', 1414, 2000, 'Nesto Hypermarket', '2026-11-04', text: 'Almarai 2L 9.95');
}

/** A Claude client whose HTTP calls go to a mock, recording what was sent. */
function claudeWith(array $responses, array &$sent): Client
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($sent));

    return new Client(apiKey: 'test-key', requestOptions: ['transporter' => new Guzzle(['handler' => $stack])]);
}

it('asks Claude for strict JSON with the page picture, cached instructions and no sampling settings', function () use ($answer) {
    $sent = [];
    $client = claudeWith([new Response(200, ['content-type' => 'application/json', 'request-id' => 'req_1'], json_encode([
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5',
        'content' => [['type' => 'text', 'text' => json_encode($answer)]],
        'stop_reason' => 'end_turn', 'stop_sequence' => null,
        'usage' => ['input_tokens' => 2600, 'output_tokens' => 420, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 900],
    ]))], $sent);

    $result = (new ClaudeFlyerReader($client, 'claude-haiku-5-5', 'low'))->extract(flyerPage());

    $body = json_decode((string) $sent[0]['request']->getBody(), true);
    expect($body['model'])->toBe('claude-haiku-5-5')
        ->and($body['messages'][0]['content'][0])->toMatchArray(['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode('jpeg-bytes')]])
        ->and($body['messages'][0]['content'][1]['text'])->toContain('Nesto Hypermarket', '1414 × 2000', '<pdf_text>')
        ->and($body['system'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($body['output_config']['format']['type'])->toBe('json_schema')
        ->and($body['output_config']['effort'])->toBe('low')
        ->and($body['thinking'])->toBe(['type' => 'disabled'])
        ->and($body['fallbacks'])->toBe('default')
        ->and($body)->not->toHaveKey('temperature');

    expect($result->items[0]['name'])->toBe('Almarai Full Fat Fresh Milk')
        ->and($result->locations)->toBe(['Al Qusais'])
        ->and($result->validTo)->toBe('2026-11-10')
        ->and($result->usage)->toEqual(new TokenUsage(input: 2600, cacheRead: 900, output: 420));
});

it('reports a cut-off Claude answer with its billed tokens, so the page is read in halves', function () {
    $sent = [];
    $client = claudeWith([new Response(200, ['content-type' => 'application/json'], json_encode([
        'id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5',
        'content' => [['type' => 'text', 'text' => '{"page":"offers","items":[{"name":"Al']],
        'stop_reason' => 'max_tokens', 'stop_sequence' => null,
        'usage' => ['input_tokens' => 2600, 'output_tokens' => 16000],
    ]))], $sent);

    try {
        (new ClaudeFlyerReader($client, 'claude-haiku-5-5', 'low'))->extract(flyerPage());
        $this->fail('Expected a failure.');
    } catch (FlyerReadFailed $e) {
        expect($e->reason)->toBe(FlyerReadFailed::TRUNCATED)->and($e->usage->output)->toBe(16000);
    }
});

it('marks a busy Claude API as worth retrying and a bad key as not', function () {
    $sent = [];
    $busy = fn () => (new ClaudeFlyerReader(claudeWith([new Response(529, ['content-type' => 'application/json'], '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}')], $sent), 'claude-haiku-5-5', 'low'))->extract(flyerPage());
    $badKey = fn () => (new ClaudeFlyerReader(claudeWith([new Response(401, ['content-type' => 'application/json'], '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}')], $sent), 'claude-haiku-5-5', 'low'))->extract(flyerPage());

    foreach ([[$busy, true], [$badKey, false]] as [$call, $retryable]) {
        try {
            $call();
            $this->fail('Expected a failure.');
        } catch (FlyerReadFailed $e) {
            expect($e->retryable)->toBe($retryable);
        }
    }
});

it('sends pages through Claude\'s batch API and reads the answers by custom id', function () use ($answer) {
    $sent = [];
    $batch = fn (string $status) => new Response(200, ['content-type' => 'application/json'], json_encode([
        'id' => 'msgbatch_1', 'type' => 'message_batch', 'processing_status' => $status,
        'request_counts' => ['canceled' => 0, 'errored' => 0, 'expired' => 0, 'processing' => 0, 'succeeded' => 2],
        'created_at' => '2026-11-04T09:00:00Z', 'expires_at' => '2026-11-05T09:00:00Z', 'ended_at' => null,
        'archived_at' => null, 'cancel_initiated_at' => null, 'results_url' => 'https://api.anthropic.com/v1/messages/batches/msgbatch_1/results',
    ]));
    $message = fn (string $id) => ['id' => $id, 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5',
        'content' => [['type' => 'text', 'text' => json_encode($answer)]], 'stop_reason' => 'end_turn', 'stop_sequence' => null,
        'usage' => ['input_tokens' => 2500, 'output_tokens' => 400]];
    $results = implode("\n", [
        json_encode(['custom_id' => 'page-2', 'result' => ['type' => 'succeeded', 'message' => $message('msg_b')]]),
        json_encode(['custom_id' => 'page-1', 'result' => ['type' => 'expired']]),
    ]);
    $reader = new ClaudeFlyerReader(claudeWith([$batch('in_progress'), $batch('ended'), new Response(200, ['content-type' => 'application/x-jsonl'], $results)], $sent), 'claude-haiku-5-5', 'low');

    $id = $reader->submitBatch(['page-1' => flyerPage(), 'page-2' => flyerPage()]);
    $ended = $reader->batchEnded($id);
    $answers = iterator_to_array($reader->batchResults($id));

    $created = json_decode((string) $sent[0]['request']->getBody(), true);
    expect($id)->toBe('msgbatch_1')
        ->and($ended)->toBeTrue()
        ->and(array_column($created['requests'], 'custom_id'))->toBe(['page-1', 'page-2'])
        ->and($created['requests'][0]['params']['output_config']['format']['type'])->toBe('json_schema')
        ->and($created['requests'][0]['params'])->not->toHaveKey('fallbacks') // not allowed in batches
        ->and($answers['page-2']->items[0]['name'])->toBe('Almarai Full Fat Fresh Milk')
        ->and($answers['page-1'])->toBeInstanceOf(FlyerReadFailed::class)
        ->and($answers['page-1']->retryable)->toBeTrue();
});

it('asks OpenAI for strict JSON with every key required and nulls allowed', function () use ($answer) {
    Http::fake(['api.openai.com/*' => Http::response([
        'id' => 'chatcmpl-1', 'model' => 'gpt-test',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => json_encode($answer), 'refusal' => null], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 3000, 'completion_tokens' => 500, 'prompt_tokens_details' => ['cached_tokens' => 1000]],
    ])]);

    $result = (new OpenAiFlyerReader('sk-test', 'gpt-test', 'low'))->extract(flyerPage());

    Http::assertSent(function (Request $request) {
        $schema = $request['response_format']['json_schema']['schema'];

        return $request->url() === 'https://api.openai.com/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer sk-test')
            && $request['response_format']['json_schema']['strict'] === true
            && $request['reasoning_effort'] === 'low'
            && $request['max_completion_tokens'] === 16000
            && str_starts_with($request['messages'][1]['content'][1]['image_url']['url'], 'data:image/jpeg;base64,')
            && $schema['properties']['items']['items']['required'] === FlyerSchema::ITEM_FIELDS
            && $schema['properties']['items']['items']['properties']['was']['type'] === ['number', 'null'];
    });
    expect($result->usage)->toEqual(new TokenUsage(input: 2000, cacheRead: 1000, output: 500))
        ->and($result->items)->toHaveCount(1);
});

it('treats an OpenAI refusal as a failed page', function () {
    Http::fake(['api.openai.com/*' => Http::response([
        'model' => 'gpt-test',
        'choices' => [['message' => ['content' => null, 'refusal' => 'I can’t help with that.'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 3000, 'completion_tokens' => 10],
    ])]);

    expect(fn () => (new OpenAiFlyerReader('sk-test', 'gpt-test', 'low'))->extract(flyerPage()))
        ->toThrow(FlyerReadFailed::class, 'declined');
});

it('reads pack sizes the way brochures print them', function () {
    $this->seed(UnitSeeder::class);

    expect(PackSize::parse('6 x 200 ml')?->key())->toBe('6x200ml')
        ->and(PackSize::parse('200ml x 6')?->key())->toBe('6x200ml')
        ->and(PackSize::parse('1,5 Ltr')?->key())->toBe('1x1.5l')
        ->and(PackSize::parse('500gm')?->key())->toBe('1x500g')
        ->and(PackSize::parse("12's")?->key())->toBe('1x12pc')
        ->and(PackSize::parse('per kg')?->byWeight)->toBeTrue()
        ->and(PackSize::parse('2 × 1.5 L')?->netBaseQty())->toBe('3000.000')
        ->and(PackSize::parse('assorted'))->toBeNull();
});

it('prices AI calls from the admins\' table, at half price in batches', function () {
    $usage = new TokenUsage(input: 1_000_000, cacheRead: 1_000_000, output: 1_000_000);

    expect(CostCalculator::cost('claude-haiku-5-5', $usage))->toBe(0.61)
        ->and(CostCalculator::cost('claude-opus-5-5', $usage, batch: true))->toBe(12.1)
        ->and(CostCalculator::cost('unknown-model', $usage))->toBeNull()
        ->and(CostCalculator::hasPrice(FlyerSettings::get('model')))->toBeTrue();
});
