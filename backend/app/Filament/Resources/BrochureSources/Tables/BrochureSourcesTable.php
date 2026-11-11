<?php

namespace App\Filament\Resources\BrochureSources\Tables;

use App\Domain\Flyers\Sources\BrochureSource;
use App\Domain\Flyers\Sources\FetchBrochureSource;
use App\Domain\Retail\Branch;
use App\Domain\Retail\Chain;
use App\Domain\Retail\ContentPermission;
use App\Filament\Resources\BrochureSources\Schemas\BrochureSourceForm;
use App\Filament\Resources\Chains\ChainResource;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Flyer sources as a table: every store's on the Flyer sources page ($allStores), or one store's on its page.
 */
class BrochureSourcesTable
{
    public static function configure(Table $table, bool $allStores = true): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('chain'))
            ->defaultSort($allStores ? 'chain.name' : 'name')
            ->columns([
                TextColumn::make('chain.name')
                    ->label('Store')
                    ->visible($allStores)
                    ->url(fn (BrochureSource $s) => ChainResource::getUrl('edit', ['record' => $s->chain_id]))
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('chain', fn (Builder $q) => $q
                        ->whereRaw("name->>'en' ilike ?", ['%'.$search.'%'])
                        ->orWhereRaw("name->>'ar' ilike ?", ['%'.$search.'%'])))
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy(
                        Chain::query()->selectRaw("name->>'en'")->whereColumn('chains.id', 'brochure_sources.chain_id'),
                        $direction,
                    )),
                TextColumn::make('name')
                    ->description(fn (BrochureSource $s) => str($s->url)->limit(60))
                    ->searchable(['name', 'url'])
                    ->sortable(),
                TextColumn::make('kind')->badge()->formatStateUsing(fn (string $state) => self::kindLabel($state)),
                TextColumn::make('branch_ids')
                    ->label('Branches')
                    ->state(fn (BrochureSource $s) => $s->branch_ids ? count($s->branch_ids).' chosen' : 'All')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('check_minutes')
                    ->label('Every')
                    ->formatStateUsing(fn (int $state) => BrochureSourceForm::intervals()[$state] ?? "{$state} min")
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_checked_at')->label('Checked')->since()->placeholder('Never')->sortable(),
                TextColumn::make('last_new_at')->label('Last new flyer')->since()->placeholder('—')->sortable(),
                TextColumn::make('consecutive_failures')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (int $state, BrochureSource $s) => ! $s->is_active ? 'Paused' : ($state ? "{$state} failed" : 'OK'))
                    ->color(fn (int $state, BrochureSource $s) => ! $s->is_active ? 'gray' : ($state ? 'danger' : 'success'))
                    ->tooltip(fn (BrochureSource $s) => $s->last_error)
                    ->sortable(),
                TextColumn::make('next_check_at')->label('Next check')->since()->placeholder('—')->sortable(),
                TextColumn::make('chain.content_permission')->label('Permission')->badge()->visible($allStores),
            ])
            ->filters($allStores ? self::filters() : [], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->persistFiltersInSession()
            ->recordActions([
                Action::make('check')
                    ->label('Check now')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->action(function (BrochureSource $source) {
                        self::checkNow($source);
                        Notification::make()->success()->title('Checking')->body('A new flyer appears under Brochures within a few minutes.')->send();
                    }),
                Action::make('log')
                    ->label('Log')
                    ->icon(Heroicon::OutlinedListBullet)
                    ->color('gray')
                    ->modalHeading(fn (BrochureSource $source) => "Checks of {$source->name}")
                    ->modalContent(fn (BrochureSource $source) => view('filament.chains.source-log', ['fetches' => $source->fetches()->limit(20)->get()]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('check')
                        ->label('Check now')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            $records->each(fn (BrochureSource $s) => self::checkNow($s));
                            Notification::make()->success()->title("Checking {$records->count()} sources")->send();
                        }),
                    BulkAction::make('pause')
                        ->label('Pause')
                        ->icon(Heroicon::OutlinedPause)
                        ->color('gray')
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => $records->each->update(['is_active' => false])),
                    BulkAction::make('resume')
                        ->label('Resume')
                        ->icon(Heroicon::OutlinedPlay)
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => $records->each->update(['is_active' => true, 'next_check_at' => now()])),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            BrochureSource::KIND_PAGE => 'Page',
            BrochureSource::KIND_NESTO => 'Nesto',
            BrochureSource::KIND_WOWDEALS => 'WowDeals',
            default => 'PDF',
        };
    }

    /** Sources whose flyers are for this branch: its store's sources for every branch, or ones that chose it. */
    private static function coveringBranch(Builder $query, int $branchId): Builder
    {
        $branch = Branch::query()->find($branchId);
        if (! $branch) {
            return $query->whereRaw('false');
        }

        return $query->where('chain_id', $branch->chain_id)->where(fn (Builder $q) => $q
            ->whereRaw('jsonb_array_length(branch_ids) = 0')
            // The form may have stored the ids as numbers or as strings.
            ->orWhereRaw('branch_ids @> ?::jsonb', [json_encode([$branchId])])
            ->orWhereRaw('branch_ids @> ?::jsonb', [json_encode([(string) $branchId])]));
    }

    private static function checkNow(BrochureSource $source): void
    {
        $source->update(['next_check_at' => now()->addMinutes(15)]);
        FetchBrochureSource::dispatch($source->id);
    }

    /** @return list<BaseFilter> */
    private static function filters(): array
    {
        return [
            SelectFilter::make('chain')
                ->label('Store')
                ->multiple()
                ->searchable()
                ->options(fn () => Chain::query()->whereHas('brochureSources')->orderBy('slug')->get()->mapWithKeys(fn (Chain $c) => [$c->id => $c->name]))
                ->attribute('chain_id'),
            SelectFilter::make('branch')
                ->label('Covers branch')
                ->searchable()
                ->options(fn () => Branch::query()
                    ->whereIn('chain_id', BrochureSource::query()->select('chain_id'))
                    ->with('chain')
                    ->orderBy('slug')
                    ->get()
                    ->mapWithKeys(fn (Branch $b) => [$b->id => "{$b->chain?->name} — {$b->name}"]))
                ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $branchId) => self::coveringBranch($query, (int) $branchId))),
            SelectFilter::make('status')
                ->options(['ok' => 'OK', 'failing' => 'Failing', 'paused' => 'Paused', 'never' => 'Never checked'])
                ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                    'ok' => $query->where('is_active', true)->where('consecutive_failures', 0),
                    'failing' => $query->where('is_active', true)->where('consecutive_failures', '>', 0),
                    'paused' => $query->where('is_active', false),
                    'never' => $query->whereNull('last_checked_at'),
                    default => $query,
                }),
            SelectFilter::make('kind')
                ->multiple()
                ->options(collect(BrochureSourceForm::kinds())->mapWithKeys(fn ($label, $kind) => [$kind => self::kindLabel($kind)])->all()),
            SelectFilter::make('permission')
                ->label('Store permission')
                ->multiple()
                ->options(ContentPermission::class)
                ->query(fn (Builder $query, array $data) => $query->when($data['values'] ?? [], fn (Builder $query, array $values) => $query
                    ->whereHas('chain', fn (Builder $q) => $q->whereIn('content_permission', $values)))),
            SelectFilter::make('quiet')
                ->label('No new flyer for')
                ->options([7 => '7+ days', 14 => '14+ days', 30 => '30+ days', 60 => '60+ days'])
                ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $days) => $query
                    ->where(fn (Builder $q) => $q->whereNull('last_new_at')->orWhere('last_new_at', '<', now()->subDays((int) $days))))),
            SelectFilter::make('check_minutes')
                ->label('Checked every')
                ->multiple()
                ->options(BrochureSourceForm::intervals()),
            SelectFilter::make('publish_mode')
                ->label('When read')
                ->options(['default' => 'As in Flyer AI settings', ...BrochureSourceForm::publishModes()])
                ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                    null, '' => $query,
                    'default' => $query->whereNull('publish_mode'),
                    default => $query->where('publish_mode', $data['value']),
                }),
            TernaryFilter::make('branches')
                ->label('Branches')
                ->placeholder('Any')
                ->trueLabel('Every branch')
                ->falseLabel('Chosen branches')
                ->queries(
                    true: fn (Builder $query) => $query->whereRaw('jsonb_array_length(branch_ids) = 0'),
                    false: fn (Builder $query) => $query->whereRaw('jsonb_array_length(branch_ids) > 0'),
                ),
            Filter::make('last_checked')
                ->columnSpan(2)
                ->columns(2)
                ->schema([
                    DatePicker::make('checked_from')->label('Checked from'),
                    DatePicker::make('checked_until')->label('Checked until'),
                ])
                ->query(fn (Builder $query, array $data) => $query
                    ->when($data['checked_from'] ?? null, fn (Builder $q, $date) => $q->where('last_checked_at', '>=', Carbon::parse($date, config('app.market_timezone'))->startOfDay()))
                    ->when($data['checked_until'] ?? null, fn (Builder $q, $date) => $q->where('last_checked_at', '<=', Carbon::parse($date, config('app.market_timezone'))->endOfDay())))
                ->indicateUsing(fn (array $data) => array_filter([
                    ($data['checked_from'] ?? null) ? 'Checked from '.Carbon::parse($data['checked_from'])->format('j M') : null,
                    ($data['checked_until'] ?? null) ? 'Checked until '.Carbon::parse($data['checked_until'])->format('j M') : null,
                ])),
        ];
    }
}
