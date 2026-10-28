<?php

namespace App\Domain\Catalog;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/** Category tree; `path` holds ancestor IDs ("/1/5/") so a parent query can include every child. */
class Category extends Model
{
    use HasTranslations;

    protected $guarded = [];

    public array $translatable = ['name'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (Category $category) {
            $parent = $category->parent_id ? self::find($category->parent_id) : null;
            $category->path = $parent ? $parent->path.$parent->id.'/' : '/';
            $category->depth = substr_count($category->path, '/') - 1;
        });

        // Moving a category moves its whole subtree.
        static::updated(function (Category $category) {
            if (! $category->wasChanged('path')) {
                return;
            }
            $old = $category->getOriginal('path').$category->id.'/';
            $new = $category->path.$category->id.'/';
            self::query()->where('path', 'like', $old.'%')->get()->each(function (Category $child) use ($old, $new) {
                $child->path = $new.substr($child->path, strlen($old));
                $child->depth = substr_count($child->path, '/') - 1;
                $child->saveQuietly();
            });
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort');
    }

    public function attributeDefinitions(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'category_attribute')
            ->withPivot(['role', 'is_required', 'sort'])
            ->orderByPivot('sort');
    }

    /** This category and everything below it. */
    public function scopeWithDescendantsOf(Builder $query, Category $category): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereKey($category->id)
            ->orWhere('path', 'like', $category->path.$category->id.'/%'));
    }
}
