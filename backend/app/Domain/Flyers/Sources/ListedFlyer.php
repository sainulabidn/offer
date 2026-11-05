<?php

namespace App\Domain\Flyers\Sources;

/** A flyer a source lists: its PDF, and the title and dates the store gives it, when it gives them. */
final readonly class ListedFlyer
{
    public function __construct(
        public string $url,
        public ?string $title = null,
        public ?string $validFrom = null,
        public ?string $validTo = null,
    ) {}
}
