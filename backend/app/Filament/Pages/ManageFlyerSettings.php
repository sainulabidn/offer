<?php

namespace App\Filament\Pages;

use App\Domain\Access\Access;
use App\Domain\Flyers\Ai\FlyerReaderManager;
use App\Domain\Flyers\Ai\FlyerReadFailed;
use App\Domain\Flyers\Ai\PageInput;
use App\Domain\Flyers\AiCall;
use App\Domain\Flyers\CostCalculator;
use App\Domain\Flyers\FlyerBudget;
use App\Domain\Flyers\FlyerSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use UnitEnum;

/**
 * The flyer AI: which provider and model read pages, when flyers publish themselves, the monthly budget and
 * per-person quota, and the price table the bill is worked out with. API keys are set on the server
 * (ANTHROPIC_API_KEY, OPENAI_API_KEY), never here: these settings are in the activity log.
 */
class ManageFlyerSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Offers';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Flyer AI settings';

    protected static ?string $title = 'Flyer AI settings';

    protected ?string $subheading = 'How flyer PDFs and photos are read into offers, and what it may cost.';

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
        $settings = FlyerSettings::all();
        $settings['prices'] = collect($settings['prices'])->map(fn (array $price, string $model) => ['model' => $model, ...$price])->values()->all();
        $this->form->fill($settings);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('This month')
                ->schema([Text::make(fn () => $this->usage())]),
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Save')->submit('save')->visible(fn () => $this->canEdit()),
                        Action::make('test')
                            ->label('Test the saved model')
                            ->icon(Heroicon::OutlinedBeaker)
                            ->color('gray')
                            ->visible(fn () => $this->canEdit())
                            ->requiresConfirmation()
                            ->modalDescription('Sends a small made-up price tag to the saved provider and model (a fraction of a cent).')
                            ->action(fn (FlyerReaderManager $readers) => $this->test($readers)),
                    ]),
                ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $keyStatus = fn (string $provider) => FlyerSettings::hasKey($provider) ? 'key set on the server' : 'no key on the server yet';
        // The picker suggests the chosen provider's models; the hint gives the rough cost per page of the typed one.
        $models = fn (?string $provider) => FlyerSettings::MODELS[$provider] ?? array_merge(...array_values(FlyerSettings::MODELS));
        $costHint = fn (?string $model) => ($cost = FlyerSettings::pageCost(trim((string) $model))) !== null
            ? 'About $'.number_format($cost, 4).' a page ($'.number_format($cost / 2, 4).' in batches).'
            : 'Add a price row for this model below.';

        return $schema
            ->statePath('data')
            ->disabled(fn () => ! $this->canEdit())
            ->components([
                Section::make('Which AI reads the pages')
                    ->columns(3)
                    ->schema([
                        Select::make('provider')
                            ->options(collect(FlyerSettings::PROVIDERS)->map(fn (string $label, string $provider) => "{$label} — {$keyStatus($provider)}"))
                            ->required()
                            ->live(),
                        TextInput::make('model')
                            ->required()
                            ->live(onBlur: true)
                            ->datalist(fn (Get $get) => $models($get('provider')))
                            ->helperText(fn (Get $get) => $costHint($get('model'))),
                        Select::make('effort')
                            ->options(['low' => 'Low (cheapest)', 'medium' => 'Medium', 'high' => 'High'])
                            ->required()
                            ->helperText('How hard the model thinks. Thinking is billed as output.'),
                        Select::make('fallback_provider')
                            ->label('Fallback provider')
                            ->options(FlyerSettings::PROVIDERS)
                            ->placeholder('None')
                            ->live(),
                        TextInput::make('fallback_model')
                            ->label('Fallback model')
                            ->live(onBlur: true)
                            ->datalist(fn (Get $get) => $models($get('fallback_provider')))
                            ->helperText(fn (Get $get) => filled($get('fallback_model')) ? $costHint($get('fallback_model')) : 'Reads a page when the main model fails or declines it.'),
                        Text::make(fn () => $this->modelCosts())->columnSpanFull(),
                    ]),
                Section::make('Publishing')
                    ->columns(2)
                    ->schema([
                        Radio::make('publish_mode')
                            ->label('When a flyer has been read')
                            ->options([
                                'review' => 'People check the offers, then publish',
                                'auto' => 'Publish clean flyers automatically',
                            ])
                            ->descriptions([
                                'auto' => 'Only when every offer is matched with nothing to check, the dates are known and the uploader may publish (or it came from an automatic source). Anything else waits for review.',
                            ])
                            ->required(),
                        TextInput::make('auto_min_confidence')
                            ->label('Lowest AI confidence for "clean"')
                            ->numeric()->minValue(0.5)->maxValue(1)->step(0.05)->required()
                            ->helperText('Also used by "Accept all clean" on the review screen.'),
                    ]),
                Section::make('Budget and limits')
                    ->columns(3)
                    ->schema([
                        TextInput::make('monthly_budget_usd')->label('Monthly AI budget (USD)')->numeric()->minValue(0)->required()
                            ->helperText('Once spent, pages wait on hold until it is raised or the month turns.'),
                        TextInput::make('user_daily_pages')->label('Pages per person per day')->integer()->minValue(1)->required()
                            ->helperText('Super admins have no limit.'),
                        TextInput::make('max_pages')->label('Most pages in one flyer')->integer()->minValue(1)->maxValue(200)->required(),
                    ]),
                Section::make('Reading')
                    ->columns(3)
                    ->collapsed()
                    ->schema([
                        TextInput::make('render_long_edge')->label('Page picture size (px, long edge)')->integer()->minValue(1000)->maxValue(2300)->required()
                            ->helperText('Never above the flyer\'s own resolution. Bigger reads small print better and costs more input.'),
                        Toggle::make('tile_dense_pages')->label('Read every page in two halves')->inline(false)
                            ->helperText('For flyers with very small print; about twice the cost. Pages cut off are read in halves anyway.'),
                        Toggle::make('arabic_names')->label('Also read Arabic names')->inline(false)->helperText('More output, so more cost.'),
                        Toggle::make('skip_no_price_pages')->label('Skip text pages without prices')->inline(false),
                        Toggle::make('batch_for_sources')->label('Half-price batches for fetched flyers')->inline(false)
                            ->helperText('Flyers from store websites are read within hours instead of minutes, at half the AI cost.'),
                        TextInput::make('match_threshold')->label('Name match: lowest score')->numeric()->minValue(0.2)->maxValue(1)->step(0.05)->required(),
                        TextInput::make('match_margin')->label('Name match: lead over the next')->numeric()->minValue(0)->maxValue(0.5)->step(0.05)->required(),
                    ]),
                Section::make('Prices (USD per million tokens)')
                    ->description('Every model that reads pages needs a row, so the budget can be kept. Batch requests cost the batch share of these. OpenAI bills cached input at "Cache read" and has no cache-write charge (set it equal to input). Built-in rows come back if removed; change their prices instead.')
                    ->collapsed()
                    ->schema([
                        Repeater::make('prices')
                            ->hiddenLabel()
                            ->columns(5)
                            ->addActionLabel('Add a model')
                            ->schema([
                                TextInput::make('model')->required(),
                                TextInput::make('input')->numeric()->minValue(0)->required(),
                                TextInput::make('output')->numeric()->minValue(0)->required(),
                                TextInput::make('cache_write')->label('Cache write')->numeric()->minValue(0),
                                TextInput::make('cache_read')->label('Cache read')->numeric()->minValue(0),
                            ]),
                        TextInput::make('batch_discount')->label('Batch share of the price')->numeric()->minValue(0)->maxValue(1)->step(0.05)->required(),
                    ]),
            ]);
    }

    public function save(): void
    {
        abort_unless($this->canEdit(), 403);

        $values = $this->form->getState();
        $values['prices'] = collect($values['prices'] ?? [])
            ->filter(fn (array $row) => filled($row['model'] ?? null))
            ->mapWithKeys(fn (array $row) => [trim($row['model']) => collect($row)->except('model')->filter(fn ($v) => $v !== null && $v !== '')->map(fn ($v) => (float) $v)->all()])
            ->all();
        foreach (['auto_min_confidence', 'match_threshold', 'match_margin', 'batch_discount'] as $key) {
            $values[$key] = (float) $values[$key];
        }
        foreach (['monthly_budget_usd', 'user_daily_pages', 'max_pages', 'render_long_edge'] as $key) {
            $values[$key] = (int) $values[$key];
        }
        foreach (['tile_dense_pages', 'arabic_names', 'skip_no_price_pages', 'batch_for_sources'] as $key) {
            $values[$key] = (bool) $values[$key];
        }

        foreach (['' => 'model', 'fallback_' => 'fallback_model'] as $prefix => $field) {
            $model = trim((string) ($values[$field] ?? ''));
            $belongs = $model !== '' ? FlyerSettings::providerOf($model) : null;
            if ($belongs !== null && filled($values["{$prefix}provider"] ?? null) && $belongs !== $values["{$prefix}provider"]) {
                Notification::make()->danger()->title("{$model} is a ".FlyerSettings::PROVIDERS[$belongs].' model: choose that provider, or another model.')->send();

                return;
            }
        }
        if (! isset($values['prices'][trim($values['model'])])) {
            Notification::make()->danger()->title("Add a price row for {$values['model']} first")->send();

            return;
        }
        FlyerSettings::save($values);
        Notification::make()->success()->title('Saved')->send();
    }

    /** "Rough cost per page — Claude: opus $0.06 · … OpenAI: …", from the price table, to compare models. */
    private function modelCosts(): string
    {
        return 'Rough cost per flyer page (half in batches) — '.collect(FlyerSettings::MODELS)
            ->map(fn (array $models, string $provider) => FlyerSettings::PROVIDERS[$provider].': '.collect($models)
                ->map(fn (string $model) => ($cost = FlyerSettings::pageCost($model)) !== null ? "{$model} \$".number_format($cost, 4) : null)
                ->filter()->implode(' · '))
            ->implode('. ').'. Run flyers:eval on reviewed flyers to see how accurate a cheaper model is.';
    }

    private function usage(): string
    {
        $calls = AiCall::query()->where('created_at', '>=', FlyerBudget::monthStart());
        $pages = (clone $calls)->whereNotNull('brochure_page_id')->distinct()->count('brochure_page_id');
        $spent = FlyerBudget::spentThisMonth();
        $budget = (float) FlyerSettings::get('monthly_budget_usd');
        $byModel = (clone $calls)->groupBy('model')->select('model', DB::raw('count(*) as calls'), DB::raw('sum(cost_usd) as cost'))->get()
            ->map(fn ($row) => "{$row->model}: {$row->calls} calls, \$".number_format((float) $row->cost, 2))->implode('; ');

        return 'Spent $'.number_format($spent, 2).' of $'.Number::format($budget).' on '.Number::format($pages).' pages'
            .($pages ? ' (about $'.number_format($spent / $pages, 4).' a page)' : '').'.'
            .($byModel ? " {$byModel}." : '');
    }

    /** A made-up price tag through the saved provider and model: proves the key, model and price row work. */
    private function test(FlyerReaderManager $readers): void
    {
        $image = imagecreatetruecolor(480, 200);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $ink = imagecolorallocate($image, 0, 0, 0);
        imagestring($image, 5, 20, 40, 'ALMARAI FRESH MILK FULL FAT 2 L', $ink);
        imagestring($image, 5, 20, 90, 'NOW AED 9.95   WAS AED 11.50', $ink);
        ob_start();
        imagejpeg($image, null, 90);

        $reader = $readers->main();
        try {
            $result = $reader->extract(new PageInput((string) ob_get_clean(), 480, 200, 'Test store', now(config('app.market_timezone'))->toDateString()));
        } catch (FlyerReadFailed $e) {
            Notification::make()->danger()->title('The test failed')->body($e->getMessage())->persistent()->send();

            return;
        }
        $cost = CostCalculator::cost($result->model, $result->usage) ?? 0;
        AiCall::create([
            'provider' => $reader->provider(), 'model' => $result->model, 'purpose' => 'test', 'user_id' => Access::user()?->id,
            'input_tokens' => $result->usage->input, 'cache_write_tokens' => $result->usage->cacheWrite,
            'cache_read_tokens' => $result->usage->cacheRead, 'output_tokens' => $result->usage->output, 'cost_usd' => $cost,
            'request_id' => $result->requestId,
        ]);
        $item = $result->items[0] ?? null;
        Notification::make()->success()->title('It works')
            ->body($item ? "Read: {$item['name']} · AED {$item['price']}".($item['was'] ? " (was {$item['was']})" : '').' · cost $'.number_format($cost, 5) : 'The model answered but found no offer.')
            ->persistent()->send();
    }
}
