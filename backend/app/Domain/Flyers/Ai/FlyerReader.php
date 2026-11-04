<?php

namespace App\Domain\Flyers\Ai;

/** An AI provider that reads flyer pages. Pick one with FlyerReaderManager. */
interface FlyerReader
{
    /** @throws FlyerReadFailed */
    public function extract(PageInput $input): PageResult;

    /** The model id this reader calls, for pricing and the usage log. */
    public function model(): string;

    /** "claude" or "openai". */
    public function provider(): string;
}
