<?php

namespace App\Domain\Flyers\Ai;

/** Tokens one AI call used, split the way providers bill them. `input` excludes cached tokens. */
final readonly class TokenUsage
{
    public function __construct(
        public int $input = 0,
        public int $cacheWrite = 0,
        public int $cacheRead = 0,
        public int $output = 0,
    ) {}

    public function plus(self $other): self
    {
        return new self(
            $this->input + $other->input,
            $this->cacheWrite + $other->cacheWrite,
            $this->cacheRead + $other->cacheRead,
            $this->output + $other->output,
        );
    }
}
