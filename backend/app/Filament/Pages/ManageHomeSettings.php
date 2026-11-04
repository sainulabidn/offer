<?php

namespace App\Filament\Pages;

use App\Domain\Access\Access;
use App\Domain\Settings\HomeSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Radius choices for shoppers, and how the home page weighs its signals. No deploy needed to change these. */
class ManageHomeSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Offers';

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'Home and ranking';

    protected static ?string $title = 'Home and ranking settings';

    protected ?string $subheading = 'The website picks up changes within 10 minutes.';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return Access::allows(Access::user(), 'settings', 'view');
    }

    private function canEdit(): bool
    {
        return Access::allows(Access::user(), 'settings', 'edit');
    }

    public function mount(): void
    {
        $this->form->fill(HomeSettings::all());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([Action::make('save')->label('Save')->submit('save')->visible(fn () => $this->canEdit())]),
                ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $number = fn (string $path, string $label) => TextInput::make($path)->label($label)->numeric()->required();
        $weights = fn (string $group, array $labels) => array_map(
            fn (string $key) => $number("{$group}.{$key}", $labels[$key])->minValue(0)->maxValue(1)->step(0.05),
            array_keys($labels),
        );

        return $schema
            ->statePath('data')
            ->disabled(fn () => ! $this->canEdit())
            ->components([
                Section::make('Distance')
                    ->description('What shoppers can choose. They can also type their own distance, up to the limit.')
                    ->columns(2)
                    ->schema([
                        TagsInput::make('radius_options')
                            ->label('Distance choices (km)')
                            ->required()
                            ->nestedRecursiveRules(['integer', 'between:1,500'])
                            ->columnSpanFull(),
                        $number('radius_default', 'Default for stores (km)')->integer()->minValue(1)->lte('radius_max'),
                        $number('item_radius_default', 'Default for items (km)')->integer()->minValue(1)->lte('radius_max'),
                        $number('radius_max', 'Longest distance allowed (km)')->integer()->minValue(1)->maxValue(500),
                        $number('walk_limit_m', 'Walking distance limit (metres)')->integer()->minValue(100)->maxValue(3000),
                        $number('usual_price_max_days', 'Hide usual prices older than (days)')->integer()->minValue(1)->maxValue(365),
                    ]),
                Section::make('Store order')
                    ->description('Weights from 0 to 1. Activity covers each shopper\'s most visited, most clicked and recent stores.')
                    ->columns(4)
                    ->collapsed()
                    ->schema($weights('store_weights', ['activity' => 'Shopper activity', 'near' => 'Nearness', 'grade' => 'Store grade', 'offers' => 'Live offers'])),
                Section::make('Item order for everyone')
                    ->columns(3)
                    ->collapsed()
                    ->schema($weights('rank_weights', ['grade' => 'Item grade', 'popularity' => 'Most viewed', 'deal' => 'Best deal'])),
                Section::make('Item order for each shopper')
                    ->columns(5)
                    ->collapsed()
                    ->schema($weights('value_weights', ['interest' => 'Personal interest', 'grade' => 'Item grade', 'deal' => 'Deal quality', 'popularity' => 'Most viewed', 'near' => 'Near their stores'])),
                Section::make('How fast activity fades')
                    ->description('A visit counts half as much after this many days.')
                    ->columns(2)
                    ->collapsed()
                    ->schema([
                        $number('store_half_life_days', 'Stores (days)')->integer()->minValue(1),
                        $number('item_half_life_days', 'Items (days)')->integer()->minValue(1),
                    ]),
                Section::make('Personal feed limits')
                    ->columns(4)
                    ->collapsed()
                    ->schema([
                        $number('feed.slots', 'Items shown')->integer()->minValue(1),
                        $number('feed.max_per_group', 'Most from one product group')->integer()->minValue(1),
                        $number('feed.max_per_chain', 'Most from one store')->integer()->minValue(1),
                        $number('feed.discovery_slots', 'Slots for something new')->integer()->minValue(0),
                    ]),
            ]);
    }

    public function save(): void
    {
        abort_unless($this->canEdit(), 403);

        $values = $this->form->getState();
        $values['radius_options'] = collect($values['radius_options'])->map(fn ($km) => (int) $km)->unique()->sort()->values()->all();
        // The form gives strings; the defaults hold numbers, and a value equal to its default isn't stored.
        array_walk_recursive($values, function (&$value) {
            if (is_string($value) && is_numeric($value)) {
                $value += 0;
            }
        });

        HomeSettings::save($values);
        Notification::make()->success()->title('Saved')->send();
    }
}
