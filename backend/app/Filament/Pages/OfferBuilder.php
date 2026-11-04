<?php

namespace App\Filament\Pages;

use App\Domain\Access\Access;
use App\Domain\Catalog\Brand;
use App\Domain\Catalog\Category;
use App\Domain\Catalog\ProductGroup;
use App\Domain\Offers\DirectOffers;
use App\Domain\Offers\Offer;
use App\Domain\Offers\PriceWait;
use App\Domain\Offers\PromoType;
use App\Domain\Retail\RetailGroup;
use App\Filament\Imports\OfferImportGuide;
use App\Filament\Imports\OfferSheet;
use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Support\OfferFields;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Many offers at once: filter stores, branches and products, set the dates and shared texts, then edit
 * every row in place (or generate prices, e.g. 10% off the shelf price) and save them all, or download
 * them as a CSV. Saved offers go live straight away (DirectOffers), with the usual checks per row.
 */
class OfferBuilder extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Offers';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Offer builder';

    protected ?string $subheading = 'Choose stores and products, set the prices in the table, then save them all or download a CSV.';

    /** A table bigger than this is too slow to edit in the browser; download a CSV for more. */
    public const int MAX_ROWS = 1_000;

    public const int PER_PAGE = 50;

    /** Fields a person edits in each row. */
    private const array EDITABLE = ['selected', 'price', 'was_price', 'promotion', 'multibuy_qty', 'multibuy_total', 'buy', 'get_free',
        'promotion_text', 'limit_text', 'limit_text_ar', 'max_per_customer', 'employees_only', 'loyalty_card', 'from', 'until'];

    /** @var array<string, mixed> the filters */
    public array $data = [];

    /** @var list<array<string, mixed>> what the person edits, per row */
    public array $rows = [];

    /** @var list<array<string, mixed>> which store, branches and pack each row is: set here only, from what the person may use */
    #[Locked]
    public array $targets = [];

    /** @var array<int, array{ok: bool, message: string}> the outcome of the last save, per row */
    #[Locked]
    public array $results = [];

    public int $tablePage = 1;

    public static function canAccess(): bool
    {
        return OfferResource::canCreate();
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('load')
                ->footer([
                    Actions::make([
                        Action::make('load')->label(fn () => $this->targets ? 'Load again' : 'Load products')->icon(Heroicon::OutlinedArrowPath)->submit('load'),
                    ]),
                ]),
            Section::make(fn () => number_format(count($this->targets)).' '.str('row')->plural(count($this->targets)))
                ->description(fn () => $this->tally())
                ->visible(fn () => $this->targets !== [])
                ->schema([
                    Actions::make([
                        $this->generateAction(),
                        Action::make('selectAll')->label('Select all')->color('gray')->action(fn () => $this->select(true)),
                        Action::make('selectNone')->label('Select none')->color('gray')->action(fn () => $this->select(false)),
                        Action::make('reset')->label('Reset')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                            ->requiresConfirmation()
                            ->modalDescription('Your changes in the table are lost and the rows are loaded again from the filters.')
                            ->action(fn () => $this->load()),
                        Action::make('download')->label('Download CSV')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                            ->action(fn () => $this->download()),
                        Action::make('save')->label('Save offers')->icon(Heroicon::OutlinedCheck)
                            ->requiresConfirmation()
                            ->modalHeading('Save the selected offers?')
                            ->modalDescription(fn () => $this->priced().' selected '.str('row')->plural($this->priced()).' with a price go live straight away. Rows without a price are left out.')
                            ->action(fn (DirectOffers $offers) => $this->save($offers)),
                    ])->key('tableActions'),
                    View::make('filament.offers.builder-table')->viewData(fn () => [
                        'targets' => $this->targets,
                        'rows' => $this->rows,
                        'results' => $this->results,
                        'page' => $this->tablePage,
                        'pages' => (int) max(1, ceil(count($this->targets) / self::PER_PAGE)),
                        'perPage' => self::PER_PAGE,
                        'promotions' => collect(PromoType::cases())->mapWithKeys(fn (PromoType $type) => [$type->value => $type->getLabel()])->all(),
                    ]),
                ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $user = fn () => Access::user();
        $groups = fn () => Access::scope(RetailGroup::query(), Access::user(), 'retail_groups')->get()->sortBy('name')->mapWithKeys(fn (RetailGroup $g) => [$g->id => $g->name]);

        return $schema
            ->statePath('data')
            ->components([
                Section::make('Stores')
                    ->columns(3)
                    ->schema([
                        Select::make('retail_group_ids')->label('Retail groups')->multiple()
                            ->options($groups)
                            ->visible(fn () => $groups()->count() > 1)
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('chain_ids', [])),
                        Select::make('chain_ids')->label('Stores')->multiple()->required()->searchable()
                            ->options(fn (Get $get) => OfferImportGuide::chains($user())
                                ->when($get('retail_group_ids'), fn ($q, array $ids) => $q->whereIn('retail_group_id', $ids))
                                ->get()->sortBy('name')->mapWithKeys(fn ($chain) => [$chain->id => $chain->name]))
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('branch_ids', [])),
                        Select::make('branch_ids')->label('Branches')->multiple()->searchable()
                            ->options(fn (Get $get) => OfferSheet::branchOptions($user(), $get('chain_ids') ?? []))
                            ->helperText('Empty = every branch of each store (or every branch you work at).'),
                    ]),
                Section::make('Dates and details')
                    ->description('Start values for every row; change any row in the table.')
                    ->columns(4)
                    ->schema([
                        DatePicker::make('valid_from')->label('From')->native(false)->required()
                            ->default(fn () => now(config('app.market_timezone'))->toDateString()),
                        DatePicker::make('valid_to')->label('Until')->native(false)->required()->afterOrEqual('valid_from')
                            ->minDate(fn () => now(config('app.market_timezone'))->toDateString()),
                        Grid::make(3)->columnSpan(2)->schema(OfferFields::times()),
                        TextInput::make('promotion_text')->placeholder('e.g. Weekend deal'),
                        TextInput::make('limit_text')->placeholder('e.g. Max 3 kg per customer'),
                        TextInput::make('limit_text_ar')->label('Limit text (Arabic)')->extraInputAttributes(['dir' => 'rtl']),
                        TextInput::make('max_per_customer')->integer()->minValue(1),
                        Toggle::make('employees_only')->label('Registered customers only'),
                        Toggle::make('loyalty_card')->label('Loyalty card price'),
                    ]),
                Section::make('Products')
                    ->description('Leave everything empty for every product.')
                    ->columns(4)
                    ->schema([
                        Select::make('category_ids')->label('Categories')->multiple()->searchable()->live()
                            ->options(fn () => Category::query()->orderByRaw("path || id::text || '/'")->get()
                                ->mapWithKeys(fn (Category $c) => [$c->id => str_repeat('— ', (int) $c->depth).$c->name]))
                            ->helperText('Includes their subcategories.'),
                        Select::make('brand_ids')->label('Brands')->multiple()->searchable()->live()
                            ->options(fn () => Brand::query()->get()->sortBy('name')->mapWithKeys(fn (Brand $b) => [$b->id => $b->name])),
                        Select::make('product_group_ids')->label('Product groups')->multiple()->searchable()->live()
                            ->options(fn () => ProductGroup::query()->get()->sortBy('name')->mapWithKeys(fn (ProductGroup $g) => [$g->id => $g->name])),
                        TextInput::make('search')->label('Name or barcode contains')->live(debounce: 500),
                        Toggle::make('only_priced')->label('Only packs the store has a shelf price for')->live()->columnSpan(2),
                        Text::make(fn (Get $get) => OfferSheet::summary($user(), [
                            'chain_ids' => $get('chain_ids') ?? [], 'branch_ids' => $get('branch_ids') ?? [], 'category_ids' => $get('category_ids') ?? [],
                            'brand_ids' => $get('brand_ids') ?? [], 'product_group_ids' => $get('product_group_ids') ?? [], 'search' => $get('search'),
                        ], self::MAX_ROWS))->weight('bold')->columnSpan(2),
                    ]),
            ]);
    }

    /** Fills the table from the filters; any edits in it are replaced. */
    public function load(): void
    {
        try {
            $sheet = OfferSheet::rows(Access::user(), $this->form->getState(), self::MAX_ROWS);
        } catch (RuntimeException $e) {
            Notification::make()->warning()->title($e->getMessage())->send();

            return;
        }

        $this->targets = array_map(fn (array $row) => array_diff_key($row, array_flip(self::EDITABLE)), $sheet);
        $this->rows = array_map(fn (array $row) => [
            ...array_intersect_key($row, array_flip(self::EDITABLE)),
            'selected' => true,
            'price' => '',
            // The shelf price is what shoppers compare with, so it starts as the "was" price.
            'was_price' => $row['usual_price'],
            'promotion' => PromoType::Price->value,
            'multibuy_qty' => '', 'multibuy_total' => '', 'buy' => '', 'get_free' => '',
        ], $sheet);
        $this->results = [];
        $this->tablePage = 1;
    }

    private function generateAction(): Action
    {
        return Action::make('generate')
            ->label('Generate prices')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->modalDescription('Works out the offer price of every selected row from its shelf price, e.g. 10% off.')
            ->schema([
                TextInput::make('percent')->label('Discount')->numeric()->minValue(0.5)->maxValue(90)->default(10)->suffix('%')->required(),
                Select::make('base')->label('Off')->options(['usual' => 'The usual shelf price', 'was' => 'The row\'s "was" price'])->default('usual')->required(),
                Select::make('rows')->label('Rows')->options(['all' => 'All selected rows', 'empty' => 'Selected rows without a price yet'])->default('all')->required(),
                Select::make('rounding')->options(['0.01' => 'To the fils', '0.05' => 'To 0.05', '0.25' => 'To 0.25', '.95' => 'Down to .95 (e.g. 8.95)'])->default('0.05')->required(),
                Toggle::make('set_was')->label('Show the base price as the "was" price')->default(true),
            ])
            ->action(function (array $data) {
                [$done, $missing] = [0, 0];
                foreach ($this->rows as $i => $row) {
                    if (! $row['selected'] || ($data['rows'] === 'empty' && $row['price'] !== '' && $row['price'] !== null)) {
                        continue;
                    }
                    $base = (float) ($data['base'] === 'was' ? $row['was_price'] : $this->targets[$i]['usual_price']);
                    if ($base <= 0) {
                        $missing++;

                        continue;
                    }
                    $this->rows[$i]['price'] = self::round($base * (1 - $data['percent'] / 100), $data['rounding']);
                    if ($data['set_was']) {
                        $this->rows[$i]['was_price'] = number_format($base, 2, '.', '');
                    }
                    $done++;
                }

                Notification::make()->success()
                    ->title("Prices set for {$done} ".str('row')->plural($done))
                    ->body($missing ? "{$missing} ".str('row')->plural($missing).' had no price to start from; type those yourself.' : null)
                    ->send();
            });
    }

    private static function round(float $price, string $rounding): string
    {
        $rounded = match ($rounding) {
            '.95' => floor($price + 0.05) - 0.05,
            default => round($price / (float) $rounding) * (float) $rounding,
        };

        return number_format(max(0.01, $rounded), 2, '.', '');
    }

    private function select(bool $on): void
    {
        foreach ($this->rows as $i => $row) {
            // Saved rows stay unticked, so saving again doesn't post them twice.
            $this->rows[$i]['selected'] = $on && ! ($this->results[$i]['ok'] ?? false);
        }
    }

    public function goToPage(int $page): void
    {
        $this->tablePage = max(1, min($page, (int) ceil(count($this->targets) / self::PER_PAGE)));
    }

    private function priced(): int
    {
        return collect($this->rows)->filter(fn (array $row) => $row['selected'] && is_numeric($row['price']))->count();
    }

    private function tally(): string
    {
        $selected = collect($this->rows)->where('selected', true)->count();
        $saved = collect($this->results)->where('ok', true)->count();
        $failed = collect($this->results)->where('ok', false)->count();

        return collect([
            "{$selected} selected",
            "{$this->priced()} with a price",
            $saved ? "{$saved} saved" : null,
            $failed ? "{$failed} not saved (see the rows)" : null,
        ])->filter()->implode(' · ');
    }

    public function save(DirectOffers $offers): void
    {
        $user = Access::user();
        $items = [];
        foreach ($this->rows as $i => $row) {
            if (! $row['selected'] || ! filled($row['price'])) {
                continue;
            }
            $errors = Validator::make($row, [
                'price' => ['required', 'numeric', 'gt:0', 'max:99999'],
                'was_price' => ['nullable', 'numeric', 'gt:0', 'max:99999'],
                'promotion' => ['required', Rule::enum(PromoType::class)],
                'multibuy_qty' => ['nullable', 'integer', 'min:2', 'required_if:promotion,multibuy'],
                'multibuy_total' => ['nullable', 'numeric', 'gt:0', 'required_if:promotion,multibuy'],
                'buy' => ['nullable', 'integer', 'min:1', 'required_if:promotion,bogo'],
                'get_free' => ['nullable', 'integer', 'min:1', 'required_if:promotion,bogo'],
                'max_per_customer' => ['nullable', 'integer', 'min:1'],
                'from' => ['required', 'date'],
                'until' => ['required', 'date', 'after_or_equal:from', 'after_or_equal:today'],
            ])->errors();
            if ($errors->isNotEmpty()) {
                $this->results[$i] = ['ok' => false, 'message' => $errors->first()];

                continue;
            }
            $items[$i] = [$this->attributes($this->targets[$i], $row), $this->targets[$i]['branch_ids']];
        }

        foreach ($offers->addMany($items, $user) as $i => $result) {
            $this->results[$i] = $result instanceof Offer
                ? ['ok' => true, 'message' => 'Saved']
                : ['ok' => false, 'message' => $result];
            if ($result instanceof Offer) {
                $this->rows[$i]['selected'] = false;
            }
        }

        $saved = collect($items)->keys()->filter(fn ($i) => $this->results[$i]['ok'])->count();
        $failed = collect($this->results)->where('ok', false)->count();
        Notification::make()
            ->status($failed ? 'warning' : 'success')
            ->title($saved.' '.str('offer')->plural($saved).' saved and live')
            ->body($failed ? "{$failed} ".str('row')->plural($failed).' not saved: the reason is on each row.' : null)
            ->send();
    }

    /** @return array<string, mixed> the offer for one row, as DirectOffers takes it */
    private function attributes(array $target, array $row): array
    {
        $type = PromoType::from($row['promotion']);
        $text = fn (string $key) => trim((string) ($row[$key] ?? '')) ?: null;

        return [
            'chain_id' => $target['chain_id'],
            'product_variant_id' => $target['variant_id'],
            'price' => (string) $row['price'],
            'regular_price' => $text('was_price'),
            'promo_type' => $type,
            'promo_params' => match ($type) {
                PromoType::Multibuy => ['qty' => (int) $row['multibuy_qty'], 'total' => (string) $row['multibuy_total']],
                PromoType::Bogo => ['buy' => (int) $row['buy'], 'get' => (int) $row['get_free']],
                default => [],
            },
            'promo_label' => $text('promotion_text') ? ['en' => $text('promotion_text')] : null,
            'condition_label' => array_filter(['en' => $text('limit_text'), 'ar' => $text('limit_text_ar')]) ?: null,
            'conditions' => array_filter([
                'max_per_customer' => $text('max_per_customer') ? (int) $row['max_per_customer'] : null,
                'card_required' => (bool) $row['loyalty_card'] ?: null,
                'staff_only' => (bool) $row['employees_only'] ?: null,
            ]),
            'valid_from' => $row['from'],
            'valid_to' => $row['until'],
            'start_time' => $target['from_time'] ?: null,
            'end_time' => $target['until_time'] ?: null,
        ];
    }

    /** The selected rows not saved yet, with today's edits, as a CSV for OfferImporter. */
    private function download(): StreamedResponse
    {
        $rows = collect($this->rows)
            ->filter(fn (array $row, int $i) => $row['selected'] && ! ($this->results[$i]['ok'] ?? false))
            ->map(fn (array $row, int $i) => [...$this->targets[$i], ...$row])
            ->values()
            ->all();

        return OfferSheet::csv('offers-'.($this->data['valid_from'] ?? now()->toDateString()).'.csv', $rows);
    }

    public function getSubheading(): ?string
    {
        $hours = PriceWait::hours();

        return $this->subheading.($hours ? " Store users can set a pack's price once every {$hours} hours." : '');
    }
}
