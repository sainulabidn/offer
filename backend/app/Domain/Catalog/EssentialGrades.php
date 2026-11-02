<?php

namespace App\Domain\Catalog;

use Illuminate\Database\Eloquent\Builder;

/**
 * Marks daily essentials as grade 1: the kinds of item the UAE economy ministry monitors, found by
 * category or by name. Only fills groups with no grade, so a grade set in the admin always stays.
 */
final class EssentialGrades
{
    public const int GRADE = 1;

    /** Categories that are essentials as a whole, including their subcategories. */
    private const array CATEGORIES = ['fruits-vegetables', 'meat-poultry', 'fish-seafood', 'dairy-eggs', 'bakery', 'rice-grains', 'oil-ghee', 'pulses-lentils', 'water'];

    /** Essentials that share a category with other things. */
    private const array WORDS = ['bread', 'flour', 'atta', 'wheat', 'sugar', 'rice', 'oil', 'ghee', 'egg', 'milk', 'water'];

    /** @return int how many product groups were graded */
    public function __invoke(): int
    {
        $parents = Category::query()->whereIn('slug', self::CATEGORIES)->get(['id', 'path']);
        $categoryIds = Category::query()
            ->where(function (Builder $query) use ($parents) {
                $query->whereKey($parents->modelKeys());
                foreach ($parents as $parent) {
                    $query->orWhere('path', 'like', "{$parent->path}{$parent->id}/%");
                }
            })
            ->pluck('id');

        return ProductGroup::query()
            ->whereNull('grade')
            ->where(function (Builder $query) use ($categoryIds) {
                $query->whereIn('category_id', $categoryIds);
                foreach (self::WORDS as $word) {
                    $query->orWhereRaw("name->>'en' ~* ?", ['\m'.$word.'s?\M']);
                }
            })
            ->update(['grade' => self::GRADE]);
    }
}
