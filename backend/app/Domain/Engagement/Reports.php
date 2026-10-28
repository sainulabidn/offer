<?php

namespace App\Domain\Engagement;

use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductVariant;
use App\Domain\Offers\Offer;
use App\Domain\Retail\Branch;
use Illuminate\Database\Eloquent\Model;

/** Shopper reports. Three independent price/expiry reports flag an offer as "being checked" until staff review it. */
final class Reports
{
    public const array TYPES = ['offer' => Offer::class, 'product' => Product::class, 'variant' => ProductVariant::class, 'branch' => Branch::class];

    public const int FLAG_THRESHOLD = 3;

    public function target(string $type, int $id): Model
    {
        return (self::TYPES[$type])::query()->findOrFail($id);
    }

    /** Returns null when this person already has an open report on the same item. */
    public function file(Model $target, ReportReason $reason, ?string $comment, ?int $userId, ?string $guestHash): ?Report
    {
        $existing = Report::query()
            ->where(['reportable_type' => $target->getMorphClass(), 'reportable_id' => $target->getKey(), 'status' => ReportStatus::Open])
            ->when($userId, fn ($q) => $q->where('user_id', $userId), fn ($q) => $q->where('guest_hash', $guestHash))
            ->exists();
        if ($existing) {
            return null;
        }

        $report = Report::create([
            'reportable_type' => $target->getMorphClass(),
            'reportable_id' => $target->getKey(),
            'reason' => $reason,
            'comment' => $comment,
            'user_id' => $userId,
            'guest_hash' => $userId ? null : $guestHash,
        ]);

        if ($target instanceof Offer && in_array($reason, [ReportReason::WrongPrice, ReportReason::Expired], true)) {
            $this->flagIfNeeded($target);
        }

        return $report;
    }

    private function flagIfNeeded(Offer $offer): void
    {
        $reporters = Report::query()
            ->where(['reportable_type' => 'offer', 'reportable_id' => $offer->id, 'status' => ReportStatus::Open])
            ->whereIn('reason', [ReportReason::WrongPrice, ReportReason::Expired])
            ->selectRaw('count(distinct coalesce(user_id::text, guest_hash)) as n')
            ->value('n');

        if ($reporters >= self::FLAG_THRESHOLD && ! ($offer->conditions['under_review'] ?? false)) {
            $offer->update(['conditions' => [...($offer->conditions ?? []), 'under_review' => true]]);
        }
    }

    /** Staff resolution from the reports queue; clears the flag once no open price/expiry reports remain. */
    public function resolve(Report $report, ReportStatus $status, ?string $resolution, int $staffId): void
    {
        $report->update(['status' => $status, 'resolution' => $resolution, 'handled_by' => $staffId, 'handled_at' => now()]);

        $offer = $report->loadMissing('reportable')->reportable;
        if ($offer instanceof Offer && ($offer->conditions['under_review'] ?? false)) {
            $stillOpen = Report::where(['reportable_type' => 'offer', 'reportable_id' => $offer->id, 'status' => ReportStatus::Open])->exists();
            if (! $stillOpen) {
                $conditions = $offer->conditions;
                unset($conditions['under_review']);
                $offer->update(['conditions' => $conditions]);
            }
        }
    }
}
