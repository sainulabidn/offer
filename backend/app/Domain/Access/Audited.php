<?php

namespace App\Domain\Access;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Every create, change and delete is written to the activity log: who, when, and old → new values. */
trait Audited
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logExcept(['updated_at', 'created_at', 'search_vector', 'location'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName(AccessRules::sectionFor(static::class) ?? 'default');
    }
}
