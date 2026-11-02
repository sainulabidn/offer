<?php

namespace App\Domain\Settings;

use App\Domain\Access\Audited;
use Illuminate\Database\Eloquent\Model;

/** One admin setting. Read them through HomeSettings, which fills in the defaults. */
class SiteSetting extends Model
{
    use Audited;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
