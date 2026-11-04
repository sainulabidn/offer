<?php

namespace App\Filament\Resources\Chains\RelationManagers;

use App\Domain\Access\Access;
use App\Domain\Flyers\Sources\BrochureSource;
use App\Domain\Flyers\Sources\FetchBrochureSource;
use App\Domain\Retail\Branch;
use App\Domain\Retail\ContentPermission;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Where this store's flyers are fetched automatically: a PDF link that stays the same, or a page that links
 * to the current flyers. Checked on their own interval; new flyers are read by AI and wait for review.
 * Runs only when the store has given permission (Brochure collection → Content permission).
 */
class BrochureSourcesRelationManager extends RelationManager
{
    protected static string $relationship = 'brochureSources';

    protected static ?string $title = 'Flyer sources';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Access::allows(Access::user(), 'settings', 'edit');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(120)->placeholder('e.g. Dubai & Northern Emirates flyer'),
            Select::make('kind')
                ->options([BrochureSource::KIND_PDF => 'A PDF link that stays the same', BrochureSource::KIND_PAGE => 'A page that links to the flyers'])
                ->default(BrochureSource::KIND_PDF)
                ->required()
                ->live(),
            TextInput::make('url')->label('Address')->url()->required()->columnSpanFull()->maxLength(2000),
            TextInput::make('link_pattern')
                ->label('Which links are flyers')
                ->placeholder(BrochureSource::DEFAULT_PATTERN)
                ->helperText('A regular expression matched against each link; empty = links ending in .pdf. E.g. "uploads/.*flyer.*\.pdf".')
                ->visible(fn (Get $get) => $get('kind') === BrochureSource::KIND_PAGE)
                ->columnSpanFull(),
            Select::make('branch_ids')
                ->label('Branches')
                ->multiple()
                ->options(fn () => Branch::query()->where('chain_id', $this->getOwnerRecord()->id)->where('is_active', true)->orderBy('slug')->get()->mapWithKeys(fn (Branch $b) => [$b->id => $b->name]))
                ->helperText('Empty = every branch. A regional or branch flyer: pick its branches.'),
            Select::make('check_minutes')
                ->label('Check every')
                ->options([60 => 'Hour', 180 => '3 hours', 360 => '6 hours', 720 => '12 hours', 1440 => 'Day'])
                ->default(60)
                ->required(),
            Select::make('publish_mode')
                ->label('When read')
                ->options(['review' => 'People check, then publish', 'auto' => 'Publish clean flyers automatically'])
                ->placeholder('As in Flyer AI settings'),
            Toggle::make('is_active')->label('Active')->default(true)->inline(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description(fn () => $this->getOwnerRecord()->content_permission === ContentPermission::Granted
                ? null
                : 'Not fetched until the store gives permission: set Content permission to "granted" under Brochure collection.')
            ->columns([
                TextColumn::make('name')->description(fn (BrochureSource $s) => str($s->url)->limit(60)),
                TextColumn::make('kind')->badge()->formatStateUsing(fn (string $state) => $state === BrochureSource::KIND_PAGE ? 'Page' : 'PDF'),
                TextColumn::make('last_checked_at')->label('Checked')->since()->placeholder('Never'),
                TextColumn::make('last_new_at')->label('Last new flyer')->since()->placeholder('—'),
                TextColumn::make('consecutive_failures')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (int $state, BrochureSource $s) => ! $s->is_active ? 'Paused' : ($state ? "{$state} failed" : 'OK'))
                    ->color(fn (int $state, BrochureSource $s) => ! $s->is_active ? 'gray' : ($state ? 'danger' : 'success'))
                    ->tooltip(fn (BrochureSource $s) => $s->last_error),
                TextColumn::make('next_check_at')->label('Next check')->since()->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()->label('Add source')->mutateDataUsing(fn (array $data) => [...$data, 'next_check_at' => now()]),
            ])
            ->recordActions([
                Action::make('check')
                    ->label('Check now')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->action(function (BrochureSource $source) {
                        $source->update(['next_check_at' => now()->addMinutes(15)]);
                        FetchBrochureSource::dispatch($source->id);
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
            ]);
    }
}
