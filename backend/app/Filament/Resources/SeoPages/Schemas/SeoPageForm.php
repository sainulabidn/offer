<?php

namespace App\Filament\Resources\SeoPages\Schemas;

use App\Domain\Catalog\Brand;
use App\Domain\Catalog\Category;
use App\Domain\Catalog\ProductGroup;
use App\Domain\Geo\Region;
use App\Domain\Retail\Chain;
use App\Domain\Seo\SeoPage;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class SeoPageForm
{
    /** What Google shows before cutting off, roughly; longer still works but is shortened in results. */
    private const int TITLE_LENGTH = 60;

    private const int DESCRIPTION_LENGTH = 160;

    public static function configure(Schema $schema): Schema
    {
        $isLanding = fn (Get $get) => str_starts_with(SeoPage::cleanPath($get('path')), SeoPage::LANDING_PREFIX);

        return $schema
            ->columns(1)
            ->components([
                Section::make('Page')
                    ->columns(3)
                    ->schema([
                        TextInput::make('path')
                            ->label('Website address')
                            ->prefix(rtrim((string) config('app.frontend_url'), '/'))
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (?string $state, Set $set) => $set('path', SeoPage::cleanPath($state)))
                            ->rule('regex:'.SeoPage::PATH_PATTERN)
                            ->validationMessages(['regex' => 'Use lower-case words and hyphens between slashes, e.g. /deals/basmati-rice-dubai.'])
                            ->helperText('A new page: start with /deals/ and choose what it lists below. To change the texts of an existing page, paste its address: /dubai, /dubai/al-barsha/c/dairy-eggs, /brands/almarai, /products/…')
                            ->columnSpan(2),
                        Grid::make(1)->columnSpan(1)->schema([
                            Toggle::make('is_active')->label('Active')->default(true),
                            Toggle::make('noindex')
                                ->label('Hide from search engines')
                                ->helperText('The page still works for shoppers, but Google and others are asked not to list it.'),
                        ]),
                    ]),

                Section::make('Search results and social sharing')
                    ->description('Empty fields keep what the page writes by itself, which already names the place, brand or category.')
                    ->schema([
                        self::translatable('title', 'Title', self::TITLE_LENGTH),
                        self::translatable('description', 'Description', self::DESCRIPTION_LENGTH, textarea: true),
                        FileUpload::make('image_path')
                            ->label('Share picture')
                            ->image()
                            ->disk(config('filesystems.media_disk'))
                            ->visibility('public')
                            ->directory('share')
                            ->maxSize(4096)
                            ->acceptedFileTypes(['image/jpeg', 'image/png'])
                            ->helperText('Shown when the page is shared on WhatsApp, Facebook, X or LinkedIn. 1200 × 630 pixels, JPEG or PNG.'),
                    ]),

                Section::make('On the page')
                    ->schema([
                        self::translatable('heading', 'Heading'),
                        self::translatable('intro', 'Introduction', textarea: true),
                    ]),

                Section::make('What the page lists')
                    ->description('Live offers that match all of these. Leave a field empty to not filter on it.')
                    ->visible($isLanding)
                    ->columns(2)
                    ->schema([
                        TextInput::make('filters.q')
                            ->label('Search words')
                            ->placeholder('e.g. basmati rice')
                            ->maxLength(100),
                        Select::make('filters.region_id')
                            ->label('Emirate or area')
                            ->options(fn () => self::regionOptions())
                            ->searchable(),
                        Select::make('filters.category_id')
                            ->label('Category')
                            ->options(fn () => self::nameOptions(Category::query()->where('is_active', true)->orderBy('path')->orderBy('sort')->get()))
                            ->searchable(),
                        Select::make('filters.product_group_id')
                            ->label('Product group')
                            ->options(fn () => self::nameOptions(ProductGroup::query()->get()))
                            ->searchable(),
                        Select::make('filters.brand_ids')
                            ->label('Brands')
                            ->multiple()
                            ->options(fn () => self::nameOptions(Brand::query()->get()))
                            ->searchable(),
                        Select::make('filters.chain_ids')
                            ->label('Stores')
                            ->multiple()
                            ->options(fn () => self::nameOptions(Chain::query()->where('is_active', true)->get()))
                            ->searchable(),
                        Select::make('filters.min_discount')
                            ->label('Discount')
                            ->options([10 => '10% or more', 20 => '20% or more', 30 => '30% or more', 50 => '50% or more'])
                            ->placeholder('Any'),
                        Select::make('filters.sort')
                            ->label('Order')
                            ->options(SeoPage::SORTS)
                            ->placeholder('Biggest discount'),
                        Toggle::make('filters.card_only')->label('Loyalty card prices only'),
                    ]),
            ]);
    }

    /** English and Arabic inputs with a character count, so staff see when Google would cut the text. */
    private static function translatable(string $column, string $label, ?int $length = null, bool $textarea = false): Grid
    {
        $input = function (string $locale, string $language) use ($column, $label, $length, $textarea) {
            $field = ($textarea ? Textarea::make("{$column}.{$locale}")->rows(3) : TextInput::make("{$column}.{$locale}"))
                ->label("{$label} ({$language})")
                ->maxLength(500);
            if ($length) {
                $field->live(debounce: 500)->hint(fn (?string $state) => mb_strlen((string) $state)." / {$length}")
                    ->hintColor(fn (?string $state) => mb_strlen((string) $state) > $length ? 'warning' : 'gray');
            }

            return $locale === 'ar' ? $field->extraInputAttributes(['dir' => 'rtl']) : $field;
        };

        return Grid::make(2)->schema([$input('en', 'English'), $input('ar', 'Arabic')]);
    }

    /** @param  Collection<int, Model>  $records */
    private static function nameOptions(Collection $records): array
    {
        return $records->mapWithKeys(fn (Model $record) => [$record->getKey() => $record->name])->sort()->all();
    }

    /** "Dubai", then its areas as "Dubai › Al Barsha". */
    private static function regionOptions(): array
    {
        $regions = Region::query()->orderBy('sort')->get();
        $options = [];
        foreach ($regions->whereNull('parent_id')->sortBy('name') as $emirate) {
            $options[$emirate->id] = $emirate->name;
            foreach ($regions->where('parent_id', $emirate->id)->sortBy('name') as $area) {
                $options[$area->id] = "{$emirate->name} › {$area->name}";
            }
        }

        return $options;
    }
}
