<?php

namespace App\Domain\Flyers\Ai;

/** One flyer page (or half of one) for the AI to read. */
final readonly class PageInput
{
    public function __construct(
        /** The picture, as bytes */
        public string $image,
        public int $width,
        public int $height,
        /** The store's name, e.g. "Nesto Hypermarket" */
        public string $store,
        /** Today in the market (Y-m-d), so "valid till 10 Nov" gets the right year */
        public string $today,
        /** The PDF's own text for this page, when it has any */
        public ?string $text = null,
        public bool $arabicNames = false,
        public string $mimeType = 'image/jpeg',
    ) {}
}
