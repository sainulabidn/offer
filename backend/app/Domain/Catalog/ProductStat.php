<?php

namespace App\Domain\Catalog;

use Illuminate\Database\Eloquent\Model;

/** A product's computed popularity and rank, refreshed by search:sync-rank. */
class ProductStat extends Model
{
    public const CREATED_AT = null;

    protected $primaryKey = 'product_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['popularity' => 'float'];
    }
}
