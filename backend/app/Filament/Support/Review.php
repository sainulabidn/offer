<?php

namespace App\Filament\Support;

use App\Domain\Access\Access;
use App\Domain\Access\ReviewStatus;
use App\Domain\Catalog\Product;
use App\Domain\Offers\Offer;
use App\Domain\Search\OfferDocument;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Model;

/** Review of shared-catalog items that store owners added: status column, filter, approve and reject. */
final class Review
{
    public static function column(): TextColumn
    {
        return TextColumn::make('review_status')->label('Review')->badge()->sortable()->toggleable();
    }

    public static function filter(): SelectFilter
    {
        return SelectFilter::make('review_status')->label('Review')->options(ReviewStatus::class);
    }

    /** @return list<Action> */
    public static function actions(string $section): array
    {
        $canReview = fn (Model $record) => $record->review_status !== ReviewStatus::Approved && Access::allows(Access::user(), $section, 'approve');

        return [
            Action::make('approve')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible($canReview)
                ->requiresConfirmation()
                ->modalDescription('It becomes visible on the website.')
                ->action(function (Model $record) {
                    if ($waiting = self::waitingParents($record)) {
                        Notification::make()->warning()->title("Approve its {$waiting} first.")->send();

                        return;
                    }
                    $record->review(ReviewStatus::Approved, Access::user());
                    if ($record instanceof Product) {
                        self::reindexOffers($record);
                    }
                    Notification::make()->success()->title('Approved')->send();
                }),
            Action::make('reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (Model $record) => $canReview($record) && $record->review_status !== ReviewStatus::Rejected)
                ->schema([Textarea::make('note')->label('Reason (shown to the person who added it)')->required()])
                ->action(function (Model $record, array $data) {
                    $record->review(ReviewStatus::Rejected, Access::user(), $data['note']);
                    Notification::make()->title('Rejected')->send();
                }),
        ];
    }

    /** Menu badge: how many items wait for someone who can approve them. */
    public static function badge(string $section, string $model): ?string
    {
        if (! Access::allows(Access::user(), $section, 'approve')) {
            return null;
        }
        $count = $model::query()->where('review_status', ReviewStatus::Pending)->count();

        return $count > 0 ? (string) $count : null;
    }

    /** A product can only go live with an approved brand, product group and category. */
    private static function waitingParents(Model $record): ?string
    {
        if (! $record instanceof Product) {
            return null;
        }

        $record->loadMissing(['brand', 'productGroup', 'category']);

        return collect(['brand' => $record->brand, 'product group' => $record->productGroup, 'category' => $record->category])
            ->filter(fn (?Model $parent) => $parent !== null && $parent->review_status !== ReviewStatus::Approved)
            ->keys()
            ->implode(', ') ?: null;
    }

    private static function reindexOffers(Product $product): void
    {
        Offer::query()->live()
            ->whereHas('variant', fn ($q) => $q->where('product_id', $product->id))
            ->with(OfferDocument::RELATIONS)
            ->get()
            ->searchable();
    }
}
