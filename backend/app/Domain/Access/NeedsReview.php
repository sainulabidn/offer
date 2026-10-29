<?php

namespace App\Domain\Access;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared-catalog items (categories, brands, product groups, products). Added by someone without the
 * "approve" permission, they start as pending and stay off the website until staff approve them.
 */
trait NeedsReview
{
    public function initializeNeedsReview(): void
    {
        $this->mergeCasts(['review_status' => ReviewStatus::class, 'reviewed_at' => 'datetime']);
        // New records are approved unless the creating hook says otherwise (records from the database overwrite this).
        $this->attributes += ['review_status' => ReviewStatus::Approved->value];
    }

    protected static function bootNeedsReview(): void
    {
        static::creating(function (Model $record) {
            $user = Access::user();
            $section = AccessRules::sectionFor($record::class);
            if ($user && $section && Access::level($user, $section, 'approve') === null) {
                $record->review_status = ReviewStatus::Pending;
            }
        });
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('review_status'), ReviewStatus::Approved->value);
    }

    public function isApproved(): bool
    {
        return $this->review_status === ReviewStatus::Approved;
    }

    public function review(ReviewStatus $status, ?User $by, ?string $note = null): void
    {
        $this->forceFill(['review_status' => $status, 'reviewed_by' => $by?->id, 'reviewed_at' => now(), 'review_note' => $note])->save();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
