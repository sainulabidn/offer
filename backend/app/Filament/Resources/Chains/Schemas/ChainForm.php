<?php

namespace App\Filament\Resources\Chains\Schemas;

use App\Domain\Geo\Country;
use App\Domain\Retail\BrochureFrequency;
use App\Domain\Retail\Chain;
use App\Domain\Retail\ChainFormat;
use App\Domain\Retail\ContentPermission;
use App\Filament\Support\Fields;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ChainForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Fields::translatable('name', 'Name', fillsSlug: true)->columnSpanFull(),
                Fields::slug(),
                Select::make('format')->options(ChainFormat::class)->required(),
                Fields::gradeInput(Fields::STORE_GRADES)
                    ->default(3)
                    ->selectablePlaceholder(false)
                    ->helperText('Product cards compare prices with big stores first.'),
                Fields::belongsTo('retail_group_id', 'retailGroup', 'Parent group', section: 'retail_groups')
                    ->helperText('The company behind the banner, e.g. Lulu Group for Lulu Hypermarket and Lulu Express.'),
                Select::make('country_id')
                    ->label('Country')
                    ->relationship('country', 'code')
                    ->getOptionLabelFromRecordUsing(fn (Country $country) => $country->name)
                    ->default(fn () => Country::where('code', 'AE')->value('id'))
                    ->required(),
                Fields::belongsTo('loyalty_program_id', 'loyaltyProgram', 'Loyalty program'),
                Toggle::make('is_active')->default(true)->inline(false),
                FileUpload::make('logo_path')
                    ->label('Logo')
                    ->image()
                    ->disk(config('filesystems.media_disk'))
                    ->visibility('public')
                    ->directory('logos')
                    ->maxSize(2048)
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->helperText('Square works best, on a white or clear background. Without a logo, the website shows the store\'s initials.')
                    ->columnSpanFull(),

                Section::make('For shoppers')
                    ->description('Shown on the store page.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('website')->url(),
                        TextInput::make('online_shop_url')->label('Online shop')->url()->helperText('Where shoppers can order for delivery, if the store sells online.'),
                        TextInput::make('customer_phone')->label('Customer service phone')->tel()->maxLength(30),
                        TextInput::make('brochure_page_url')->label('Store\'s own offers page')->url()->helperText('Linked from the store page, so shoppers can check the original brochure.'),
                        Select::make('brochure_frequency')
                            ->label('New brochures')
                            ->options(BrochureFrequency::class)
                            ->placeholder('Not known')
                            ->helperText('A store with a regular brochure shows as overdue in the store list when none covers today.'),
                    ]),

                Section::make('Brochure collection')
                    ->description('For staff only.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->visible(Fields::canGrade(...))
                    ->schema([
                        TextInput::make('brochure_pdf_url')->label('PDF download link')->url()->helperText('Only if the link stays the same for each new brochure. To fetch flyers automatically, add it under Flyer sources below.'),
                        TextInput::make('brochure_contact')->label('Brochure contact')->helperText('Who sends brochures or answers questions, e.g. marketing@store.ae.'),
                        Select::make('content_permission')
                            ->label('Permission to show their brochures and logo')
                            ->options(ContentPermission::class)
                            ->default(ContentPermission::Unknown)
                            ->selectablePlaceholder(false),
                        Toggle::make('is_verified')
                            ->label('Details verified')
                            ->inline(false)
                            ->helperText(fn (?Chain $record) => $record?->is_verified && $record->verified_at
                                ? 'Checked '.$record->verified_at->toFormattedDateString().($record->verifiedBy ? " by {$record->verifiedBy->name}" : '').'. Untick and tick again after the next check.'
                                : 'Tick after checking the name, parent group, branches and links against the store\'s own website.'),
                        Textarea::make('staff_notes')->rows(3)->columnSpanFull(),
                    ]),
            ]);
    }
}
