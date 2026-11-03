<?php

namespace App\Filament\Pages;

use App\Domain\Access\Access;
use App\Domain\Seo\SeoSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
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

/** The site name in titles, the default share picture, social profiles and search engine verification codes. */
class ManageSeoSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlassCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Website';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'SEO and sharing';

    protected static ?string $title = 'SEO and social sharing';

    protected ?string $subheading = 'For the whole website. The website picks up changes within 5 minutes.';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return Access::allows(Access::user(), 'seo', 'view');
    }

    private function canEdit(): bool
    {
        return Access::allows(Access::user(), 'seo', 'edit');
    }

    public function mount(): void
    {
        $this->form->fill(SeoSettings::all());
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
        $code = fn (string $key, string $label, string $meta) => TextInput::make($key)
            ->label($label)
            ->maxLength(200)
            ->helperText("Only the code from <meta name=\"{$meta}\" content=\"…\">.")
            ->dehydrateStateUsing(fn (?string $state) => self::verificationCode($state));

        return $schema
            ->statePath('data')
            ->disabled(fn () => ! $this->canEdit())
            ->components([
                Section::make('Name and description')
                    ->columns(2)
                    ->schema([
                        TextInput::make('site_name')
                            ->label('Site name')
                            ->required()
                            ->maxLength(40)
                            ->helperText('Ends every page title: "Dairy offers in Dubai · offer".'),
                        TextInput::make('tagline')
                            ->required()
                            ->maxLength(60)
                            ->helperText('The home page title, after the name.'),
                        Textarea::make('description')
                            ->label('Default description')
                            ->required()
                            ->rows(2)
                            ->maxLength(300)
                            ->helperText('For pages that don\'t write their own. Around 150 characters.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Social sharing')
                    ->columns(2)
                    ->schema([
                        FileUpload::make('share_image_path')
                            ->label('Default share picture')
                            ->image()
                            ->disk(config('filesystems.media_disk'))
                            ->visibility('public')
                            ->directory('share')
                            ->maxSize(4096)
                            ->acceptedFileTypes(['image/jpeg', 'image/png'])
                            ->helperText('Used on WhatsApp, Facebook, X and LinkedIn when a page has no picture of its own. 1200 × 630 pixels.')
                            ->columnSpanFull(),
                        TextInput::make('x_handle')
                            ->label('X (Twitter) account')
                            ->prefix('@')
                            ->maxLength(30)
                            ->dehydrateStateUsing(fn (?string $state) => ltrim(trim((string) $state), '@') ?: null),
                        TextInput::make('facebook_app_id')->label('Facebook app ID')->maxLength(30),
                        TagsInput::make('social_profiles')
                            ->label('Official social profiles')
                            ->placeholder('https://www.instagram.com/…')
                            ->nestedRecursiveRules(['url', 'max:200'])
                            ->helperText('Full addresses. Search engines link them to the site.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Search engine verification')
                    ->description('Proves to each search engine that you own the site, so you can use its webmaster tools.')
                    ->columns(2)
                    ->collapsed()
                    ->schema([
                        $code('google_verification', 'Google Search Console', 'google-site-verification'),
                        $code('bing_verification', 'Bing Webmaster Tools', 'msvalidate.01'),
                        $code('yandex_verification', 'Yandex', 'yandex-verification'),
                        $code('facebook_verification', 'Facebook domain', 'facebook-domain-verification'),
                        $code('pinterest_verification', 'Pinterest', 'p:domain_verify'),
                    ]),
            ]);
    }

    /** Staff often paste the whole meta tag; keep only its content. */
    public static function verificationCode(?string $value): ?string
    {
        $value = trim((string) $value);
        if (preg_match('/content\s*=\s*["\']([^"\']+)["\']/i', $value, $match)) {
            $value = $match[1];
        }

        return $value === '' ? null : $value;
    }

    public function save(): void
    {
        abort_unless($this->canEdit(), 403);

        $values = $this->form->getState();
        $values['social_profiles'] = array_values(array_unique($values['social_profiles'] ?? []));

        SeoSettings::save($values);
        Notification::make()->success()->title('Saved')->send();
    }
}
