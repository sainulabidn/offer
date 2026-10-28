<?php

namespace App\Domain\Retail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/** Operating company that owns one or more chains (banners). */
class RetailGroup extends Model
{
    use HasTranslations;

    protected $guarded = [];

    public array $translatable = ['name'];

    public function chains(): HasMany
    {
        return $this->hasMany(Chain::class);
    }

    /** Branches this group runs as a franchisee/affiliate under another chain's banner. */
    public function operatedBranches(): HasMany
    {
        return $this->hasMany(Branch::class, 'operator_group_id');
    }
}
