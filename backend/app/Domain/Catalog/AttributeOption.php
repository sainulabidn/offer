<?php

namespace App\Domain\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

class AttributeOption extends Model
{
    use HasTranslations;

    public $timestamps = false;

    protected $guarded = [];

    public array $translatable = ['name'];

    protected function casts(): array
    {
        return ['synonyms' => 'array'];
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }
}
