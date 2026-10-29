<?php

namespace App\Domain\Search;

use App\Domain\Access\Audited;
use App\Domain\Access\RecordsCreator;
use Illuminate\Database\Eloquent\Model;

/**
 * Words shoppers use for the same thing ("atta" = "wheat flour" = "طحين"). Two-way unless `root` is set:
 * then searching the root also finds the terms, but searching a term doesn't find the root.
 * Every change is pushed to Typesense straight away (see Synonyms).
 */
class Synonym extends Model
{
    use Audited, RecordsCreator;

    protected $table = 'search_synonyms';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'terms' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Synonym $synonym) {
            $synonym->terms = self::clean($synonym->terms ?? []);
            $synonym->root = self::clean([$synonym->root])[0] ?? null;
        });
        static::saved(fn () => app(Synonyms::class)->syncQuietly());
        static::deleted(fn () => app(Synonyms::class)->syncQuietly());
    }

    /** Trimmed, lower-case, single-spaced and without duplicates; Typesense matches synonyms on lower-case tokens. */
    public static function clean(array $terms): array
    {
        return collect($terms)
            ->map(fn ($term) => preg_replace('/\s+/u', ' ', mb_strtolower(trim((string) $term))))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** The item in the Typesense synonym set. */
    public function toTypesense(): array
    {
        return array_filter([
            'id' => 'synonym-'.$this->id,
            'root' => $this->root,
            'synonyms' => $this->terms,
        ]);
    }
}
