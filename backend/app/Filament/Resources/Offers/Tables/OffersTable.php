<?php

namespace App\Filament\Resources\Offers\Tables;

use App\Domain\Access\Access;
use App\Domain\Brochures\Brochure;
use App\Domain\Offers\DirectOffers;
use App\Domain\Offers\Offer;
use App\Domain\Offers\OfferStatus;
use App\Filament\Resources\Brochures\BrochureResource;
use App\Filament\Resources\Offers\OfferResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OffersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['chain', 'brochure', 'variant.product', 'variant.optionValues']))
            ->columns([
                TextColumn::make('variant')->label('Product')->state(fn (Offer $offer) => $offer->variant->label())->wrap()
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('variant.product', fn (Builder $q) => $q->whereRaw("name->>'en' ilike ?", ['%'.$search.'%']))),
                TextColumn::make('chain.name')->label('Store'),
                TextColumn::make('source')
                    ->state(fn (Offer $offer) => $offer->isDirect() ? 'Added directly' : $offer->brochure->title)
                    ->color(fn (Offer $offer) => $offer->isDirect() ? 'info' : null)
                    ->badge(fn (Offer $offer) => $offer->isDirect())
                    ->wrap(),
                TextColumn::make('price')->money('AED')->sortable(),
                TextColumn::make('regular_price')->label('Was')->money('AED')->placeholder('—'),
                TextColumn::make('promo_type')->badge(),
                TextColumn::make('audience')
                    ->label('Who')
                    ->state(fn (Offer $offer) => $offer->isStaffOnly() ? 'Employees only' : null)
                    ->badge()
                    ->color('warning')
                    ->placeholder('—'),
                TextColumn::make('valid_to')->label('Until')->date('d M Y')->sortable()
                    ->description(fn (Offer $offer) => $offer->isTimed() ? 'Daily '.$offer->windowLabel() : null),
                TextColumn::make('status')->badge(),
                TextColumn::make('price_set_at')->label('Price set')->since()->sortable()->toggleable(),
            ])
            ->defaultSort('price_set_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(OfferStatus::class),
                SelectFilter::make('chain')->label('Store')->relationship('chain', 'slug')->searchable()->preload(),
                TernaryFilter::make('direct')
                    ->label('Source')
                    ->trueLabel('Added directly')
                    ->falseLabel('From brochures')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('brochure', fn (Builder $q) => $q->where('type', Brochure::TYPE_DIRECT)),
                        false: fn (Builder $query) => $query->whereHas('brochure', fn (Builder $q) => $q->where('type', '!=', Brochure::TYPE_DIRECT)),
                    ),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (Offer $offer) => OfferResource::canEdit($offer)),
                Action::make('brochure')
                    ->label('Open brochure')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->visible(fn (Offer $offer) => ! $offer->isDirect())
                    ->url(fn (Offer $offer) => BrochureResource::getUrl('edit', ['record' => $offer->brochure_id])),
                Action::make('withdraw')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The offer leaves the website straight away.')
                    ->visible(fn (Offer $offer) => in_array($offer->status, [OfferStatus::Published, OfferStatus::Approved, OfferStatus::Draft], true)
                        && (OfferResource::canEdit($offer) || Access::allows(Access::user(), 'brochures', 'publish', $offer->brochure)))
                    ->action(function (Offer $offer, DirectOffers $offers) {
                        $offers->withdraw($offer);
                        Notification::make()->title('Offer withdrawn')->success()->send();
                    }),
            ]);
    }
}
