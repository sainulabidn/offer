<?php

namespace App\Filament\Resources\Brochures\Pages;

use App\Domain\Access\Access;
use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochureItem;
use App\Domain\Brochures\BrochureNotifier;
use App\Domain\Brochures\BrochurePage;
use App\Domain\Brochures\BrochureStatus;
use App\Domain\Brochures\ItemStatus;
use App\Domain\Brochures\PageStatus;
use App\Domain\Brochures\PublishBrochure;
use App\Domain\Catalog\ProductVariant;
use App\Domain\Flyers\AcceptItems;
use App\Domain\Flyers\Ai\PageResult;
use App\Domain\Flyers\AiCall;
use App\Domain\Flyers\ApplyPageResult;
use App\Domain\Flyers\ExtractFlyerPage;
use App\Domain\Flyers\FlyerSettings;
use App\Domain\Flyers\ItemValidator;
use App\Domain\Offers\PromoType;
use App\Filament\Imports\OfferImportGuide;
use App\Filament\Resources\Brochures\BrochureResource;
use App\Filament\Support\OfferFields;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use RuntimeException;
use Throwable;

/**
 * Checking a flyer the AI read: each page with boxes on the offers it found, and the items beside it to
 * accept, fix or ignore. Accepted items become draft offers on the brochure; Publish (or Submit for review)
 * takes them live as for any brochure. Matching again is free; reading a page again uses the AI budget.
 *
 * Who may change items: anyone who may edit or publish the brochure, and its uploader until it is live.
 */
class ReviewFlyer extends Page
{
    use InteractsWithRecord;

    protected static string $resource = BrochureResource::class;

    #[Url(as: 'page')]
    public int $pageNo = 1;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        if (! $this->record->pages()->where('page_no', $this->pageNo)->exists()) {
            $this->pageNo = (int) ($this->record->pages()->min('page_no') ?? 1);
        }
    }

    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        return Access::allows(Access::user(), 'brochures', 'view', $record instanceof Brochure ? $record : null);
    }

    public function getTitle(): string
    {
        return 'Review flyer';
    }

    public function getSubheading(): ?string
    {
        $brochure = $this->record->loadMissing('chain');

        return "{$brochure->chain->name} · {$brochure->title} · {$brochure->valid_from->format('j M')} – {$brochure->valid_to->format('j M Y')} · {$brochure->status->getLabel()}";
    }

    public function canEditItems(): bool
    {
        $user = Access::user();
        $brochure = $this->record;
        $canPublish = Access::allows($user, 'brochures', 'publish', $brochure);
        if ($brochure->status === BrochureStatus::Published && ! $canPublish) {
            return false;
        }

        return $canPublish
            || Access::allows($user, 'brochures', 'edit', $brochure)
            || ($user !== null && $brochure->ai_requested_by === $user->id);
    }

    private function canUseAi(): bool
    {
        return $this->canEditItems() && Access::allows(Access::user(), 'brochures', 'ai_import');
    }

    protected function getHeaderActions(): array
    {
        $open = fn () => $this->record->items()->where('status', ItemStatus::Matched)->get()->filter->isReadyToAccept((float) FlyerSettings::get('auto_min_confidence'))->count();

        return [
            Action::make('acceptClean')
                ->label(fn () => 'Accept all clean ('.$open().')')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->visible(fn () => $this->canEditItems() && $open() > 0)
                ->requiresConfirmation()
                ->modalDescription('Accepts every item with a matched product, nothing to check and a confident AI reading. They become draft offers.')
                ->action(function (AcceptItems $accept) {
                    $result = $accept->acceptClean($this->record, Access::user());
                    $notification = Notification::make()->title("{$result['accepted']} accepted");
                    $result['failed'] ? $notification->warning()->body(count($result['failed']).' not accepted: '.reset($result['failed'])) : $notification->success();
                    $notification->send();
                }),
            Action::make('useDates')
                ->label('Use the flyer\'s dates')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->visible(fn () => $this->canEditItems() && $this->detectedDatesDiffer())
                ->requiresConfirmation()
                ->modalDescription(fn () => 'Valid '.($this->record->detected['valid_from'] ?? '?').' to '.($this->record->detected['valid_to'] ?? '?').', as printed on the flyer.')
                ->action(function () {
                    $detected = $this->record->detected;
                    $this->record->update(['valid_from' => $detected['valid_from'] ?? $this->record->valid_from, 'valid_to' => $detected['valid_to']]);
                    Notification::make()->success()->title('Dates updated')->send();
                }),
            Action::make('useBranches')
                ->label('Limit to branches on the flyer')
                ->icon(Heroicon::OutlinedMapPin)
                ->color('gray')
                ->visible(fn () => $this->canEditItems() && $this->branchSuggestions() !== [])
                ->modalDescription(fn () => 'The flyer mentions: '.implode(', ', $this->record->detected['locations'] ?? []).'. The brochure will cover only the branches you tick.')
                ->schema([
                    CheckboxList::make('branch_ids')
                        ->label('Branches')
                        ->options(fn () => collect($this->branchSuggestions())->mapWithKeys(fn (array $b) => [$b['id'] => "{$b['name']} (\"{$b['because']}\")"]))
                        ->default(fn () => array_column($this->branchSuggestions(), 'id'))
                        ->required(),
                ])
                ->action(function (array $data) {
                    $allowed = array_column($this->branchSuggestions(), 'id');
                    $this->record->scopes()->delete();
                    foreach (array_intersect(array_map('intval', $data['branch_ids']), $allowed) as $branchId) {
                        $this->record->scopes()->create(['scope_type' => 'branch', 'scope_id' => $branchId]);
                    }
                    Notification::make()->success()->title('Branches updated')->send();
                }),
            Action::make('publish')
                ->label('Publish')
                ->icon('heroicon-o-rocket-launch')
                ->visible(fn () => Access::allows(Access::user(), 'brochures', 'publish', $this->record) && $this->record->status !== BrochureStatus::Processing)
                ->requiresConfirmation()
                ->modalDescription(fn () => EditBrochure::publishDescription($this->record))
                ->action(function (PublishBrochure $publish) {
                    try {
                        $count = $publish($this->record);
                    } catch (RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }
                    $this->record->refresh()->update(['reviewed_by' => Access::user()?->id]);
                    Notification::make()->title("{$count} offers published")->success()->send();
                }),
            Action::make('submit')
                ->label('Submit for review')
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn () => ! Access::allows(Access::user(), 'brochures', 'publish', $this->record)
                    && $this->canEditItems()
                    && ! in_array($this->record->status, [BrochureStatus::InReview, BrochureStatus::Published, BrochureStatus::Processing], true))
                ->requiresConfirmation()
                ->modalDescription(fn () => EditBrochure::publishDescription($this->record).' Staff check the offers and publish them.')
                ->action(function () {
                    $this->record->update(['status' => BrochureStatus::InReview]);
                    BrochureNotifier::waitingForReview($this->record, Access::user());
                    Notification::make()->title('Sent for review')->success()->send();
                }),
            Action::make('edit')
                ->label('Brochure details')
                ->color('gray')
                ->url(fn () => BrochureResource::getUrl('edit', ['record' => $this->record])),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Actions::make([
                Action::make('rematch')
                    ->label('Match products again')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->size('sm')
                    ->tooltip('Free: uses what the AI already read, with what reviewers have taught since.')
                    ->visible(fn () => $this->canEditItems() && $this->currentPage()?->ai_output)
                    ->action(function (ApplyPageResult $apply) {
                        $page = $this->currentPage();
                        $apply($page, PageResult::fromStored($page->ai_output));
                        Notification::make()->success()->title('Matched again')->send();
                    }),
                $this->readAgainAction('readAgain', 'Read page again', tiles: false),
                $this->readAgainAction('readHalves', 'Read in halves', tiles: true),
            ])->key('pageActions'),
            View::make('filament.brochures.review-flyer')->viewData(fn () => $this->viewData()),
        ]);
    }

    private function readAgainAction(string $name, string $label, bool $tiles): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->size('sm')
            ->visible(fn () => $this->canUseAi() && in_array($this->currentPage()?->ai_status, [PageStatus::Done, PageStatus::Failed, PageStatus::Reused, PageStatus::Skipped], true))
            ->requiresConfirmation()
            ->modalDescription($tiles
                ? 'The AI reads the top and bottom halves separately: better for dense pages with small print, about twice the cost. Items not accepted yet are replaced.'
                : 'The AI reads this page again (uses the AI budget). Items not accepted yet are replaced.')
            ->action(function () use ($tiles) {
                $page = $this->currentPage();
                $page->update(['ai_status' => PageStatus::Queued, 'ai_error' => null]);
                ExtractFlyerPage::dispatch($page->id, tiles: $tiles);
                Notification::make()->success()->title('Reading the page again')->send();
            });
    }

    /** Fix an item's product, price or promotion, then accept it. */
    public function editItemAction(): Action
    {
        return Action::make('editItem')
            ->modalHeading(fn (array $arguments) => $this->item($arguments['item'] ?? 0)->name)
            ->modalSubmitActionLabel('Save and accept')
            ->fillForm(function (array $arguments) {
                $item = $this->item($arguments['item'] ?? 0);

                return [
                    'item_id' => $item->id,
                    'product_variant_id' => $item->product_variant_id,
                    'price' => $item->price,
                    'regular_price' => $item->regular_price,
                    'promo_type' => $item->promo_type?->value,
                    'promo_params' => $item->promo_params,
                    'promo_label' => $item->getTranslations('promo_label'),
                    'condition_label' => $item->getTranslations('condition_label'),
                    'conditions' => $item->conditions,
                    'valid_from' => $item->valid_from,
                    'valid_to' => $item->valid_to,
                ];
            })
            ->schema([
                Hidden::make('item_id'),
                OfferFields::variant()->options(fn (Get $get) => $this->candidateOptions($this->item((int) $get('item_id')))),
                TextInput::make('price')->label('Offer price (AED)')->numeric()->gt(0)->required(),
                TextInput::make('regular_price')->label('Was price')->numeric()->gt(0),
                Select::make('promo_type')->label('Promotion')->options(PromoType::class)->required()->live(),
                ...OfferFields::promotion(),
                DatePicker::make('valid_from')->native(false)->helperText('Empty = brochure dates'),
                DatePicker::make('valid_to')->native(false)->afterOrEqual('valid_from'),
            ])
            ->action(function (array $data, array $arguments, AcceptItems $accept, Action $action) {
                $this->authorizeItems();
                $item = $this->item($arguments['item'] ?? 0);
                $filled = fn (?array $values) => array_filter($values ?? [], fn ($v) => $v !== null && $v !== '' && $v !== false);
                $variant = (int) $data['product_variant_id'];
                $item->update([
                    'product_variant_id' => $variant,
                    'match_method' => $variant === $item->product_variant_id ? $item->match_method : 'manual',
                    'price' => $data['price'],
                    'regular_price' => $data['regular_price'] ?: null,
                    'promo_type' => $data['promo_type'],
                    'promo_params' => $filled($data['promo_params'] ?? []),
                    'promo_label' => $filled($data['promo_label'] ?? []) ?: null,
                    'condition_label' => $filled($data['condition_label'] ?? []) ?: null,
                    'conditions' => $filled($data['conditions'] ?? []),
                    'valid_from' => $data['valid_from'] ?: null,
                    'valid_to' => $data['valid_to'] ?: null,
                ]);
                try {
                    $accept->accept($item->refresh(), Access::user());
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                    $action->halt();
                }
                Notification::make()->success()->title('Accepted')->send();
            });
    }

    public function acceptItem(int $id, AcceptItems $accept): void
    {
        $this->authorizeItems();
        try {
            $accept->accept($this->item($id), Access::user());
        } catch (ValidationException $e) {
            Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
        }
    }

    public function ignoreItem(int $id, AcceptItems $accept): void
    {
        $this->authorizeItems();
        $accept->ignore($this->item($id), Access::user());
    }

    /** Back to the list to check: an ignored item, or an accepted one (its draft offer is removed). */
    public function restoreItem(int $id, AcceptItems $accept): void
    {
        $this->authorizeItems();
        $item = $this->item($id);
        if ($item->offer) {
            $accept->ignore($item, Access::user());
        }
        $this->recheck($item->refresh());
    }

    /** "Did you mean": the reviewer picks one of the matcher's candidates. */
    public function useCandidate(int $id, int $variantId): void
    {
        $this->authorizeItems();
        $item = $this->item($id);
        if (! in_array($variantId, array_column($item->candidates ?? [], 'id'), true)) {
            return;
        }
        $item->update(['product_variant_id' => $variantId, 'match_method' => 'manual']);
        $this->recheck($item);
    }

    /** Issues and status again after a change. */
    private function recheck(BrochureItem $item): void
    {
        $attributes = [...$item->only(['price', 'regular_price', 'valid_from', 'valid_to', 'confidence', 'product_variant_id', 'promo_params']), 'valid_from' => $item->valid_from?->toDateString(), 'valid_to' => $item->valid_to?->toDateString()];
        $issues = array_values(array_filter(ItemValidator::issues($attributes, $this->record->chain_id, $item->page?->text()), fn (array $issue) => $issue['code'] !== 'unsure' || $item->match_method !== 'manual'));
        $item->update(['issues' => $issues, 'status' => $item->product_variant_id && $issues === [] ? ItemStatus::Matched : ItemStatus::New, 'offer_id' => null]);
    }

    private function viewData(): array
    {
        $pages = $this->record->pages()
            ->withCount(['items', 'items as open_count' => fn ($q) => $q->whereIn('status', [ItemStatus::New, ItemStatus::Matched])])
            ->get();
        $page = $pages->firstWhere('page_no', $this->pageNo);
        $items = $page ? $page->items()->with(['variant.product', 'variant.optionValues'])->get() : collect();
        $candidateIds = $items->flatMap(fn (BrochureItem $item) => array_column($item->candidates ?? [], 'id'))->unique();

        return [
            'pages' => $pages,
            'page' => $page,
            'image' => $this->url($page?->image_path),
            'thumbs' => $pages->mapWithKeys(fn (BrochurePage $p) => [$p->page_no => $this->url($p->thumb_path)]),
            'items' => $items,
            'labels' => ProductVariant::with(['product', 'optionValues'])->whereKey($candidateIds)->get()->mapWithKeys(fn (ProductVariant $v) => [$v->id => $v->label()]),
            'canEdit' => $this->canEditItems(),
            'processing' => $this->record->status === BrochureStatus::Processing || $pages->contains(fn (BrochurePage $p) => $p->ai_status !== PageStatus::Held && $p->ai_status->isOpen()),
            'cost' => (float) AiCall::query()->where('brochure_id', $this->record->id)->sum('cost_usd'),
        ];
    }

    private function currentPage(): ?BrochurePage
    {
        return $this->record->pages()->where('page_no', $this->pageNo)->first();
    }

    private function item(int $id): BrochureItem
    {
        return $this->record->items()->with(['offer', 'brochure', 'page'])->findOrFail($id);
    }

    /** @return array<int, string> the matcher's candidates, so the picker opens with them */
    private function candidateOptions(BrochureItem $item): array
    {
        $ids = array_filter([$item->product_variant_id, ...array_column($item->candidates ?? [], 'id')]);

        return ProductVariant::with(['product', 'optionValues'])->whereKey($ids)->get()->mapWithKeys(fn (ProductVariant $v) => [$v->id => $v->label()])->all();
    }

    /** @return list<array{id: int, name: string, because: string}> suggested branches the person may pick */
    private function branchSuggestions(): array
    {
        $mine = OfferImportGuide::branches(Access::user(), $this->record->loadMissing('chain')->chain)->pluck('id')->all();

        return array_values(array_filter($this->record->detected['branches'] ?? [], fn (array $b) => in_array($b['id'], $mine, true)));
    }

    private function detectedDatesDiffer(): bool
    {
        $detected = $this->record->detected ?? [];

        return ($detected['valid_to'] ?? null) !== null
            && ($detected['valid_to'] !== $this->record->valid_to->toDateString() || ($detected['valid_from'] ?? null) !== $this->record->valid_from->toDateString());
    }

    private function authorizeItems(): void
    {
        abort_unless($this->canEditItems(), 403);
    }

    private function url(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }
        try {
            return Storage::disk(config('flyers.disk'))->temporaryUrl($path, now()->addHour());
        } catch (Throwable) {
            return null;
        }
    }
}
