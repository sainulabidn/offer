<?php

namespace App\Filament\Resources\BrochureSources\Schemas;

use App\Domain\Flyers\Sources\BrochureSource;
use App\Domain\Retail\Branch;
use App\Domain\Retail\Chain;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * The flyer source form, on a store's page (Chains → Flyer sources, $chainId = that store) and on the
 * Flyer sources page (the store is chosen in the form).
 */
class BrochureSourceForm
{
    public static function configure(Schema $schema, ?int $chainId = null): Schema
    {
        return $schema->columns(2)->components([
            ...($chainId === null ? [
                Select::make('chain_id')
                    ->label('Store')
                    ->options(fn () => Chain::query()->orderBy('slug')->get()->mapWithKeys(fn (Chain $c) => [$c->id => $c->name]))
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('branch_ids', []))
                    ->columnSpanFull(),
            ] : []),
            TextInput::make('name')->required()->maxLength(120)->placeholder('e.g. Dubai & Northern Emirates flyer'),
            Select::make('kind')
                ->options(self::kinds())
                ->default(BrochureSource::KIND_PDF)
                ->required()
                ->live(),
            TextInput::make('url')
                ->label('Address')
                ->url()
                ->required()
                ->columnSpanFull()
                ->maxLength(2000)
                ->helperText(fn (Get $get) => match ($get('kind')) {
                    BrochureSource::KIND_NESTO => 'https://admin.nestooffers.com/api/deal-of-the-week?store_id=…&location_id=… — the store and its location as nestooffers.com numbers them. Store 80, location 44 = offers for every UAE branch.',
                    BrochureSource::KIND_WOWDEALS => 'The store\'s catalogue list, e.g. https://www.wowdeals.me/ae/carrefour/catalogs. Only for stores whose own flyers can\'t be fetched.',
                    default => null,
                }),
            TextInput::make('link_pattern')
                ->label('Which links are flyers')
                ->placeholder(fn (Get $get) => $get('kind') === BrochureSource::KIND_WOWDEALS ? 'Every catalogue' : BrochureSource::DEFAULT_PATTERN)
                ->helperText(fn (Get $get) => $get('kind') === BrochureSource::KIND_WOWDEALS
                    ? 'A regular expression matched against each catalogue\'s address, to keep one region\'s, e.g. "abu-dhabi|al-ain".'
                    : 'A regular expression matched against each link; empty = links ending in .pdf. E.g. "uploads/.*flyer.*\.pdf".')
                ->visible(fn (Get $get) => in_array($get('kind'), [BrochureSource::KIND_PAGE, BrochureSource::KIND_WOWDEALS], true))
                ->columnSpanFull(),
            Select::make('branch_ids')
                ->label('Branches')
                ->multiple()
                ->options(fn (Get $get) => self::branches($chainId ?? (int) $get('chain_id')))
                ->helperText('Empty = every branch. A regional or branch flyer: pick its branches.'),
            Select::make('check_minutes')
                ->label('Check every')
                ->options(self::intervals())
                ->default(60)
                ->required(),
            Select::make('publish_mode')
                ->label('When read')
                ->options(self::publishModes())
                ->placeholder('As in Flyer AI settings'),
            Toggle::make('is_active')->label('Active')->default(true)->inline(false),
        ]);
    }

    /** @return array<string, string> */
    public static function kinds(): array
    {
        return [
            BrochureSource::KIND_PDF => 'A PDF link that stays the same',
            BrochureSource::KIND_PAGE => 'A page that links to the flyers',
            BrochureSource::KIND_NESTO => 'A Nesto store (nestooffers.com)',
            BrochureSource::KIND_WOWDEALS => 'A store on WowDeals (third party, page pictures)',
        ];
    }

    /** @return array<int, string> */
    public static function intervals(): array
    {
        return [60 => 'Hour', 180 => '3 hours', 360 => '6 hours', 720 => '12 hours', 1440 => 'Day'];
    }

    /** @return array<string, string> */
    public static function publishModes(): array
    {
        return ['review' => 'People check, then publish', 'auto' => 'Publish clean flyers automatically'];
    }

    /** @return array<int, string> the store's active branches */
    private static function branches(int $chainId): array
    {
        if (! $chainId) {
            return [];
        }

        return Branch::query()->where('chain_id', $chainId)->where('is_active', true)->orderBy('slug')->get()
            ->mapWithKeys(fn (Branch $b) => [$b->id => $b->name])->all();
    }
}
