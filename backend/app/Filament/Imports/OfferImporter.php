<?php

namespace App\Filament\Imports;

use App\Domain\Access\Access;
use App\Domain\Offers\DirectOffers;
use App\Domain\Offers\Offer;
use App\Domain\Offers\PromoType;
use App\Domain\Retail\Branch;
use App\Domain\Retail\Chain;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Offers from a CSV, one row per offer, added the same way as on the Offers page (DirectOffers): each goes
 * live as its row is read, and store users' rows follow the price wait (PriceWait).
 */
class OfferImporter extends Importer
{
    use FindsPack;

    protected static ?string $model = Offer::class;

    public static function getColumns(): array
    {
        // Examples are filled in when downloaded, from the person's own store and real packs (OfferImportGuide).
        $column = fn (string $name, array $rules = ['nullable'], bool $required = false) => ImportColumn::make($name)
            ->examples(fn () => OfferImportGuide::examples($name))
            ->helperText(OfferImportGuide::COLUMNS[$name])
            ->requiredMapping($required)
            ->rules($rules)
            ->fillRecordUsing(fn () => null); // saveRecord() does the work
        $yesNo = ['nullable', Rule::in(['yes', 'no', 'Yes', 'No', 'YES', 'NO', '1', '0', 'true', 'false'])];

        return [
            $column('store', ['required'], true),
            $column('branches'),
            $column('barcode'),
            $column('product'),
            $column('pack'),
            $column('price', ['required', 'numeric', 'gt:0', 'max:99999'], true),
            $column('was_price', ['nullable', 'numeric', 'gt:0', 'max:99999']),
            $column('promotion', ['nullable', Rule::enum(PromoType::class)]),
            $column('multibuy_qty', ['nullable', 'integer', 'min:2', 'required_if:promotion,multibuy']),
            $column('multibuy_total', ['nullable', 'numeric', 'gt:0', 'required_if:promotion,multibuy']),
            $column('buy', ['nullable', 'integer', 'min:1', 'required_if:promotion,bogo']),
            $column('get_free', ['nullable', 'integer', 'min:1', 'required_if:promotion,bogo']),
            $column('promotion_text'),
            $column('max_per_customer', ['nullable', 'integer', 'min:1']),
            $column('loyalty_card', $yesNo),
            $column('employees_only', $yesNo),
            $column('limit_text'),
            $column('limit_text_ar'),
            $column('from', ['nullable', 'date']),
            $column('until', ['required', 'date', 'after_or_equal:today'], true),
        ];
    }

    public function resolveRecord(): Offer
    {
        return new Offer;
    }

    public function saveRecord(): void
    {
        $chain = Chain::where('slug', trim((string) $this->data['store']))->first()
            ?? throw new RowImportFailedException("Unknown store [{$this->data['store']}]. Use the store's slug.");

        // Runs in a queue job, where no admin is signed in: ask about the person who started the import.
        $user = $this->import->user;
        if (! Access::allows($user, 'brochures', 'create') || ! Access::allows($user, 'chains', 'view', $chain)) {
            throw new RowImportFailedException("You can't add offers for {$chain->name}.");
        }

        $text = fn (string $key) => trim((string) ($this->data[$key] ?? '')) ?: null;
        $yes = fn (string $key) => filter_var($this->data[$key] ?? false, FILTER_VALIDATE_BOOL);
        $type = PromoType::tryFrom((string) $text('promotion')) ?? PromoType::Price;
        $from = $text('from') ?? now(config('app.market_timezone'))->toDateString();
        if ($from > $this->data['until']) {
            throw new RowImportFailedException('"from" is after "until".');
        }

        $attributes = [
            'chain_id' => $chain->id,
            'product_variant_id' => $this->variant()->id,
            'price' => (string) $this->data['price'],
            'regular_price' => $text('was_price'),
            'promo_type' => $type,
            'promo_params' => match ($type) {
                PromoType::Multibuy => ['qty' => (int) $this->data['multibuy_qty'], 'total' => (string) $this->data['multibuy_total']],
                PromoType::Bogo => ['buy' => (int) $this->data['buy'], 'get' => (int) $this->data['get_free']],
                default => [],
            },
            'promo_label' => $text('promotion_text') ? ['en' => $text('promotion_text')] : null,
            'condition_label' => array_filter(['en' => $text('limit_text'), 'ar' => $text('limit_text_ar')]) ?: null,
            'conditions' => array_filter([
                'max_per_customer' => $text('max_per_customer') ? (int) $text('max_per_customer') : null,
                'card_required' => $yes('loyalty_card') ?: null,
                'staff_only' => $yes('employees_only') ?: null,
            ]),
            'valid_from' => $from,
            'valid_to' => (string) $this->data['until'],
        ];

        try {
            $this->record = app(DirectOffers::class)->add($attributes, $this->branchIds($chain), $user);
        } catch (ValidationException $e) {
            throw new RowImportFailedException(collect($e->errors())->flatten()->first() ?? $e->getMessage());
        }
    }

    /** @return list<int> the branches named in the row; none means the whole store, for those who manage it */
    private function branchIds(Chain $chain): array
    {
        $slugs = collect(explode('|', (string) ($this->data['branches'] ?? '')))->map(fn ($slug) => trim($slug))->filter()->unique();
        $user = $this->import->user;

        if ($slugs->isEmpty()) {
            if (! Access::allows($user, 'chains', 'edit', $chain)) {
                throw new RowImportFailedException("Name your branches in the \"branches\" column: you can't add offers for every {$chain->name} branch.");
            }

            return [];
        }

        $branches = Branch::where('chain_id', $chain->id)->where('is_active', true)->whereIn('slug', $slugs)->get();
        if ($missing = $slugs->diff($branches->pluck('slug'))->implode(', ')) {
            throw new RowImportFailedException("Unknown {$chain->name} branches: {$missing}.");
        }
        if ($branches->contains(fn (Branch $branch) => ! Access::allows($user, 'branches', 'view', $branch))) {
            throw new RowImportFailedException("You can't add offers for some of these {$chain->name} branches.");
        }

        return $branches->modelKeys();
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Offer import finished: '.Number::format($import->successful_rows).' '.str('offer')->plural($import->successful_rows).' live.';

        if ($failed = $import->getFailedRowsCount()) {
            $body .= ' '.Number::format($failed).' '.str('row')->plural($failed).' failed; download them to see why.';
        }

        return $body;
    }
}
