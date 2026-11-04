<?php

namespace App\Domain\Flyers;

use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochurePage;
use App\Domain\Flyers\Ai\FlyerReader;
use App\Domain\Flyers\Ai\TokenUsage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One AI request and what it cost; failed ones too, since tokens may still be billed. */
class AiCall extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['cost_usd' => 'decimal:5', 'created_at' => 'datetime'];
    }

    /** Logs a call about a flyer page, priced from the admins' table (batch calls at the batch share). */
    public static function record(FlyerReader $reader, BrochurePage $page, string $model, TokenUsage $usage, string $status = 'ok', ?string $error = null, ?string $requestId = null, string $mode = 'sync', ?int $ms = null): self
    {
        $batch = $mode === 'batch';

        return self::create([
            'provider' => $reader->provider(),
            'model' => $model,
            'mode' => $mode,
            'brochure_id' => $page->brochure_id,
            'brochure_page_id' => $page->id,
            'user_id' => $page->loadMissing('brochure')->brochure?->ai_requested_by,
            'input_tokens' => $usage->input,
            'cache_write_tokens' => $usage->cacheWrite,
            'cache_read_tokens' => $usage->cacheRead,
            'output_tokens' => $usage->output,
            'cost_usd' => CostCalculator::cost($model, $usage, $batch) ?? CostCalculator::cost($reader->model(), $usage, $batch) ?? 0,
            'status' => $status,
            'error' => $error,
            'request_id' => $requestId,
            'duration_ms' => $ms,
        ]);
    }

    public function brochure(): BelongsTo
    {
        return $this->belongsTo(Brochure::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(BrochurePage::class, 'brochure_page_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
