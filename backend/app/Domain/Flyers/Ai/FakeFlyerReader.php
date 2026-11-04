<?php

namespace App\Domain\Flyers\Ai;

use Closure;

/**
 * For tests: answers from a script instead of an AI. Bind it with FlyerReaderManager::fake().
 * Each answer is a FlyerSchema array, a FlyerReadFailed to throw, or a closure given the input.
 * Batches answer every page at once, when the test says they have ended ($batchEnded).
 */
final class FakeFlyerReader implements BatchFlyerReader
{
    /** @var list<PageInput> */
    public array $inputs = [];

    /** @var array<string, array<string, PageInput>> batch id => inputs by custom id */
    public array $batches = [];

    public bool $batchEnded = true;

    /** @param  list<array|FlyerReadFailed|Closure>  $answers  used in turn; the last one repeats */
    public function __construct(
        private array $answers,
        private readonly string $model = 'claude-haiku-5-5',
        private readonly TokenUsage $usage = new TokenUsage(input: 3000, cacheRead: 1500, output: 1200),
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
        $this->inputs[] = $input;
        $answer = count($this->answers) > 1 ? array_shift($this->answers) : $this->answers[0];
        $answer = $answer instanceof Closure ? $answer($input) : $answer;
        if ($answer instanceof FlyerReadFailed) {
            throw $answer;
        }

        return PageResult::fromAnswer($answer, $this->usage, $this->model, 'fake-'.count($this->inputs));
    }

    public function submitBatch(array $inputs): string
    {
        $id = 'fake-batch-'.(count($this->batches) + 1);
        $this->batches[$id] = $inputs;

        return $id;
    }

    public function batchEnded(string $batchId): bool
    {
        return $this->batchEnded;
    }

    public function batchResults(string $batchId): iterable
    {
        foreach ($this->batches[$batchId] ?? [] as $customId => $input) {
            try {
                yield $customId => $this->extract($input);
            } catch (FlyerReadFailed $e) {
                yield $customId => $e;
            }
        }
    }
}
