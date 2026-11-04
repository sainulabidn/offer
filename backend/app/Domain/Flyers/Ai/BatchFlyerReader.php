<?php

namespace App\Domain\Flyers\Ai;

/**
 * A reader that can also send many pages at once through its provider's batch API: half price, answers
 * within hours instead of seconds. Used for flyers nobody is waiting for (automatic sources, "economy").
 */
interface BatchFlyerReader extends FlyerReader
{
    /** @param  array<string, PageInput>  $inputs  by custom id @return string the provider's batch id */
    public function submitBatch(array $inputs): string;

    /** Has the provider finished the batch (all answered, failed or expired)? */
    public function batchEnded(string $batchId): bool;

    /** @return iterable<string, PageResult|FlyerReadFailed> by custom id, in any order */
    public function batchResults(string $batchId): iterable;
}
