<?php

namespace App\Domain\Shopper;

use Illuminate\Database\Eloquent\Model;

/** One fading score for a shopper and a store or item. Written by Affinities in bulk. */
class ShopperAffinity extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $guarded = [];
}
