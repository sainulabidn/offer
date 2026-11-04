<?php

namespace App\Domain\Flyers\Ai;

/**
 * What the AI read off one page, in FlyerSchema's shape. Items without a name or a usable price are
 * dropped here; everything else is checked later (ItemValidator), where a person can see it.
 */
final readonly class PageResult
{
    /**
     * @param  list<array<string, mixed>>  $items  FlyerSchema items
     * @param  list<string>  $locations  stores, areas or emirates printed as where the offers apply
     */
    public function __construct(
        public string $pageType,
        public ?string $validFrom,
        public ?string $validTo,
        public array $locations,
        public array $items,
        public TokenUsage $usage,
        public string $model,
        public ?string $requestId = null,
    ) {}

    /** @throws FlyerReadFailed when the answer isn't the object FlyerSchema asks for */
    public static function fromAnswer(mixed $answer, TokenUsage $usage, string $model, ?string $requestId = null): self
    {
        if (! is_array($answer) || ! is_array($answer['items'] ?? null)) {
            throw new FlyerReadFailed('The AI answer was not the expected JSON.', FlyerReadFailed::INVALID, $usage, $model, $requestId);
        }

        $items = [];
        foreach ($answer['items'] as $item) {
            $name = is_array($item) ? trim((string) ($item['name'] ?? '')) : '';
            if ($name === '' || ! is_numeric($item['price'] ?? null) || (float) $item['price'] <= 0) {
                continue;
            }
            $items[] = [...array_fill_keys(FlyerSchema::ITEM_FIELDS, null), ...array_intersect_key($item, array_flip(FlyerSchema::ITEM_FIELDS)), 'name' => $name];
        }

        return new self(
            pageType: in_array($answer['page'] ?? null, FlyerSchema::PAGE_TYPES, true) ? $answer['page'] : 'offers',
            validFrom: self::date($answer['from'] ?? null),
            validTo: self::date($answer['until'] ?? null),
            locations: array_values(array_filter(array_map(fn ($place) => is_string($place) ? trim($place) : '', (array) ($answer['where'] ?? [])))),
            items: $items,
            usage: $usage,
            model: $model,
            requestId: $requestId,
        );
    }

    /** As stored in brochure_pages.ai_output. */
    public function toArray(): array
    {
        return [
            'page' => $this->pageType,
            'from' => $this->validFrom,
            'until' => $this->validTo,
            'where' => $this->locations,
            'items' => $this->items,
            'model' => $this->model,
        ];
    }

    /** A stored answer, reused for an identical page: costs nothing. */
    public static function fromStored(array $stored): self
    {
        return self::fromAnswer($stored, new TokenUsage, (string) ($stored['model'] ?? ''));
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))
            ? $value
            : null;
    }
}
