<?php

namespace App\Filament\Resources\Brochures\Tables;

use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochureStatus;
use App\Domain\Brochures\ItemStatus;
use App\Filament\Resources\Brochures\BrochureResource;
use App\Filament\Support\Fields;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BrochuresTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Direct offers have their own page (Offers); their hidden containers aren't brochures to manage.
            ->modifyQueryUsing(fn ($query) => $query->where('type', '!=', Brochure::TYPE_DIRECT)->with('chain')->withCount([
                'offers',
                'items',
                'items as open_items_count' => fn ($q) => $q->whereIn('status', [ItemStatus::New, ItemStatus::Matched]),
            ]))
            ->columns([
                TextColumn::make('chain.name')->label('Chain'),
                Fields::nameColumn('title'),
                TextColumn::make('valid_from')->date('d M')->label('From')->sortable(),
                TextColumn::make('valid_to')->date('d M Y')->label('To')->sortable(),
                TextColumn::make('offers_count')->label('Offers'),
                // Flyers read by AI: how far the reading and the review are.
                TextColumn::make('flyer')
                    ->label('Flyer AI')
                    ->state(fn (Brochure $brochure) => match (true) {
                        ! $brochure->isFlyerImport() => null,
                        $brochure->status === BrochureStatus::Processing => 'Reading pages…',
                        $brochure->open_items_count > 0 => "{$brochure->open_items_count} of {$brochure->items_count} to review",
                        default => 'Reviewed',
                    })
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'Reviewed' => 'success',
                        'Reading pages…' => 'info',
                        default => 'warning',
                    })
                    ->placeholder('—'),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('valid_from', 'desc')
            ->filters([
                SelectFilter::make('status')->options(BrochureStatus::class),
                SelectFilter::make('chain')->relationship('chain', 'slug')->searchable()->preload(),
            ])
            ->recordActions([
                Action::make('review')
                    ->label('Review')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->visible(fn (Brochure $brochure) => $brochure->isFlyerImport())
                    ->url(fn (Brochure $brochure) => BrochureResource::getUrl('review', ['record' => $brochure])),
                EditAction::make(),
            ]);
    }
}
