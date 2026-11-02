<?php

namespace App\Filament\Resources\Chains\Tables;

use App\Domain\Brochures\BrochureStatus;
use App\Domain\Retail\BrochureFrequency;
use App\Domain\Retail\Chain;
use App\Domain\Retail\ChainFormat;
use App\Domain\Retail\ContentPermission;
use App\Filament\Support\Fields;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ChainsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->with(['retailGroup', 'branches.region.parent'])
                ->withCount('branches')
                ->withBrochuresUntil())
            ->columns([
                Fields::nameColumn(),
                TextColumn::make('format')->badge(),
                Fields::gradeColumn(Fields::STORE_GRADES)->selectablePlaceholder(false),
                TextColumn::make('retailGroup.name')->label('Parent group'),
                TextColumn::make('branches_count')->label('Branches')->sortable(),
                TextColumn::make('coverage')
                    ->label('UAE coverage')
                    ->state(fn (Chain $chain) => $chain->emirates() ?: null)
                    ->placeholder('No branches')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('brochure_frequency')->label('Brochures')->placeholder('Not known'),
                TextColumn::make('brochures_until')
                    ->label('Offers until')
                    ->formatStateUsing(fn (?string $state, Chain $chain) => ($chain->brochureOverdue() ? 'Overdue since ' : '').Carbon::parse($state)->toFormattedDateString())
                    ->placeholder('No brochure yet')
                    ->color(fn (Chain $chain) => $chain->brochureOverdue() ? 'danger' : null)
                    ->sortable(),
                TextColumn::make('content_permission')->label('Permission')->badge()->visible(Fields::canGrade(...))->toggleable(),
                IconColumn::make('is_verified')->label('Verified')->boolean(),
                IconColumn::make('is_active')->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('format')->options(ChainFormat::class),
                Fields::gradeFilter(Fields::STORE_GRADES),
                Filter::make('overdue')
                    ->label('Brochure overdue')
                    ->query(fn (Builder $query) => $query
                        ->whereIn('brochure_frequency', BrochureFrequency::regular())
                        ->whereDoesntHave('brochures', fn (Builder $q) => $q
                            ->whereIn('status', [BrochureStatus::Published, BrochureStatus::Scheduled])
                            ->where('valid_to', '>=', now(config('app.market_timezone'))->toDateString()))),
                TernaryFilter::make('is_verified')->label('Verified'),
                SelectFilter::make('content_permission')->label('Permission')->options(ContentPermission::class)->visible(Fields::canGrade(...)),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    Action::make('brochure_page')
                        ->label('Open offers page')
                        ->icon(Heroicon::OutlinedGlobeAlt)
                        ->url(fn (Chain $chain) => $chain->brochure_page_url, shouldOpenInNewTab: true)
                        ->visible(fn (Chain $chain) => filled($chain->brochure_page_url)),
                    Action::make('brochure_pdf')
                        ->label('Download PDF')
                        ->icon(Heroicon::OutlinedArrowDownTray)
                        ->url(fn (Chain $chain) => $chain->brochure_pdf_url, shouldOpenInNewTab: true)
                        ->visible(fn (Chain $chain) => filled($chain->brochure_pdf_url) && Fields::canGrade()),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    Fields::gradeBulkAction(Fields::STORE_GRADES),
                ]),
            ]);
    }
}
