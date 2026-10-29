<?php

namespace App\Domain\Access;

use App\Domain\Retail\Chain;
use App\Domain\Retail\RetailGroup;
use Illuminate\Database\Eloquent\Model;

/**
 * Remembers which admin user created a record ("own" permissions). A store owner who adds a retail group
 * or chain is also given access to it, so its branches and brochures are theirs to manage.
 */
trait RecordsCreator
{
    public function initializeRecordsCreator(): void
    {
        $this->attributes += ['created_by' => null];
    }

    protected static function bootRecordsCreator(): void
    {
        static::creating(function (Model $record) {
            $record->created_by ??= Access::user()?->id;
        });

        static::created(function (Model $record) {
            $user = Access::user();
            $type = match (true) {
                $record instanceof RetailGroup => 'retail_group',
                $record instanceof Chain => 'chain',
                default => null,
            };
            $section = AccessRules::sectionFor($record::class);
            if ($user && $type && $section && Access::level($user, $section, 'view') !== Level::All && ! $user->all_stores) {
                $user->storeAccess()->firstOrCreate(['scope_type' => $type, 'scope_id' => $record->getKey()]);
                Access::forget($user);
            }
        });
    }
}
