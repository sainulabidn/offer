<?php

namespace Database\Seeders;

use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\PublishBrochure;
use App\Domain\Catalog\Product;
use App\Domain\Offers\ExpireOffers;
use App\Domain\Offers\Offer;
use App\Domain\Offers\PromoType;
use App\Domain\Retail\Chain;
use Illuminate\Database\Seeder;

/** Local-only: a published brochure for the demo catalog, so the website has live offers. */
class DemoOffersSeeder extends Seeder
{
    public function run(PublishBrochure $publish): void
    {
        $today = now(config('app.market_timezone'))->startOfDay();
        $chainId = Chain::where('slug', 'lulu-hypermarket')->value('id');

        // A month-old brochure (now expired) so price history and deal scores have something to compare.
        $past = Brochure::updateOrCreate(['slug' => 'lulu-weekly-demo-past'], [
            'chain_id' => $chainId,
            'title' => ['en' => 'Lulu weekly deals (last month)'],
            'valid_from' => $today->copy()->subDays(33),
            'valid_to' => $today->copy()->subDays(27),
        ]);
        $riceFiveKg = Product::where('slug', 'india-gate-classic-basmati-rice')->firstOrFail()->variants()->where('slug', '5-kg')->value('id');
        Offer::updateOrCreate(['brochure_id' => $past->id, 'product_variant_id' => $riceFiveKg], ['price' => '49.50', 'regular_price' => '54.50']);
        $publish($past);
        app(ExpireOffers::class)();

        $brochure = Brochure::updateOrCreate(['slug' => 'lulu-weekly-demo'], [
            'chain_id' => Chain::where('slug', 'lulu-hypermarket')->value('id'),
            'title' => ['en' => 'Lulu weekly deals'],
            'valid_from' => $today->copy()->subDays(2),
            'valid_to' => $today->copy()->addDays(5),
        ]);

        // product slug, variant slug, price, was price, promotion
        $offers = [
            ['india-gate-classic-basmati-rice', '5-kg', '45.95', '54.50', [PromoType::Price, []]],
            ['india-gate-classic-basmati-rice', '10-kg', '85.00', '99.00', [PromoType::Price, []]],
            ['almarai-full-fat-fresh-milk', '2-l', '11.50', '11.50', [PromoType::Multibuy, ['qty' => 2, 'total' => 20]]],
            ['banana', 'philippines', '5.75', '6.95', [PromoType::Price, []]],
        ];

        foreach ($offers as [$product, $variant, $price, $regular, [$promo, $params]]) {
            $variantId = Product::where('slug', $product)->firstOrFail()->variants()->where('slug', $variant)->value('id');
            Offer::updateOrCreate(['brochure_id' => $brochure->id, 'product_variant_id' => $variantId], [
                'price' => $price,
                'regular_price' => $regular,
                'promo_type' => $promo,
                'promo_params' => $params,
                'promo_label' => $promo === PromoType::Multibuy ? ['en' => "{$params['qty']} for AED {$params['total']}"] : null,
            ]);
        }

        $count = $publish($brochure);
        $this->command?->info("Demo brochure published with {$count} offers.");
    }
}
