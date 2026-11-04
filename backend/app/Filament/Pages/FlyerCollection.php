<?php

namespace App\Filament\Pages;

use App\Domain\Access\Access;
use App\Domain\Brochures\BrochureStatus;
use App\Domain\Retail\BrochureFrequency;
use App\Domain\Retail\Chain;
use App\Domain\Retail\ContentPermission;
use App\Filament\Imports\OfferImportGuide;
use App\Filament\Resources\Chains\ChainResource;
use App\Filament\Resources\Offers\OfferResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The flyer worklist: stores that publish regularly but have no flyer covering today, sources that keep
 * failing, and stores with no automatic source or no permission yet. Upload their flyer by hand (Offers →
 * Import from flyer) or fix the source.
 */
class FlyerCollection extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Offers';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Flyer collection';

    protected static ?string $title = 'Flyer collection';

    protected ?string $subheading = 'Stores whose flyer is due, and why it didn\'t arrive by itself.';

    public static function canAccess(): bool
    {
        return Access::allows(Access::user(), 'brochures', 'ai_import');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $today = fn () => now(config('app.market_timezone'))->toDateString();

        return $table
            ->query(fn () => OfferImportGuide::chains(Access::user())
                ->withBrochuresUntil()
                ->withCount([
                    'brochureSources as sources_count' => fn (Builder $q) => $q->where('is_active', true),
                    'brochureSources as failing_count' => fn (Builder $q) => $q->where('is_active', true)->where('consecutive_failures', '>', 0),
                ]))
            ->defaultSort('grade')
            ->columns([
                TextColumn::make('name')->label('Store')->description(fn (Chain $chain) => $chain->brochure_contact),
                TextColumn::make('grade')->sortable(),
                TextColumn::make('brochure_frequency')->label('Publishes')->badge()->placeholder('—'),
                TextColumn::make('brochures_until')
                    ->label('Flyers until')
                    ->date('j M')
                    ->placeholder('None')
                    ->color(fn (Chain $chain) => $chain->brochureOverdue() ? 'danger' : null),
                TextColumn::make('sources')
                    ->label('Automatic source')
                    ->state(fn (Chain $chain) => match (true) {
                        $chain->sources_count === 0 => 'None',
                        $chain->content_permission !== ContentPermission::Granted => 'Waiting for permission',
                        $chain->failing_count > 0 => "{$chain->failing_count} failing",
                        default => 'Working',
                    })
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'Working' => 'success',
                        'None' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('content_permission')->label('Permission')->badge(),
            ])
            ->filters([
                SelectFilter::make('need')
                    ->label('Show')
                    ->options(['overdue' => 'Flyer due now', 'failing' => 'Source failing', 'no_source' => 'No automatic source', 'all' => 'All stores'])
                    ->default('overdue')
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? 'overdue') {
                        'overdue' => $query
                            ->whereIn('brochure_frequency', BrochureFrequency::regular())
                            ->whereDoesntHave('brochures', fn (Builder $q) => $q
                                ->whereIn('status', [BrochureStatus::Published, BrochureStatus::Scheduled])
                                ->whereDate('valid_to', '>=', $today())),
                        'failing' => $query->whereHas('brochureSources', fn (Builder $q) => $q->where('is_active', true)->where('consecutive_failures', '>', 0)),
                        'no_source' => $query->whereDoesntHave('brochureSources', fn (Builder $q) => $q->where('is_active', true)),
                        default => $query,
                    }),
            ])
            ->recordActions([
                Action::make('upload')
                    ->label('Upload flyer')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->url(fn () => OfferResource::getUrl('index')),
                Action::make('sources')
                    ->label('Sources')
                    ->color('gray')
                    ->visible(fn () => Access::allows(Access::user(), 'settings', 'edit'))
                    ->url(fn (Chain $chain) => ChainResource::getUrl('edit', ['record' => $chain])),
            ])
            ->emptyStateHeading('Nothing due')
            ->emptyStateDescription('Every store that publishes regularly has a flyer covering today.');
    }
}
