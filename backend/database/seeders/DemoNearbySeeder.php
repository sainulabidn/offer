<?php

namespace Database\Seeders;

use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\PublishBrochure;
use App\Domain\Catalog\Product;
use App\Domain\Geo\Country;
use App\Domain\Geo\Region;
use App\Domain\Offers\ExpireOffers;
use App\Domain\Offers\Offer;
use App\Domain\Offers\PromoType;
use App\Domain\Offers\RegularPrice;
use App\Domain\Retail\Chain;
use App\Domain\Retail\ChainFormat;
use App\Domain\Retail\RetailGroup;
use Clickbar\Magellan\Data\Geometries\Point;

/**
 * Local-only: enough stores and everyday products to see the home page, radius, reference prices and
 * store comparison working. Runs after DemoCatalogSeeder. Coordinates and prices are made up, and
 * some stores sit a short walk apart on purpose. Each store has only some items on offer; the rest
 * get a dated usual price, as staff would enter.
 */
class DemoNearbySeeder extends DemoCatalogSeeder
{
    /** slug => [name, retail group, format, grade] */
    private const array CHAINS = [
        'lulu-hypermarket' => ['Lulu Hypermarket', 'Lulu Group', ChainFormat::Hypermarket, 1],
        'carrefour' => ['Carrefour', 'Majid Al Futtaim', ChainFormat::Hypermarket, 1],
        'nesto-hypermarket' => ['Nesto Hypermarket', 'Nesto Group', ChainFormat::Hypermarket, 1],
        'union-coop' => ['Union Coop', 'Union Coop', ChainFormat::Supermarket, 2],
        'viva-supermarket' => ['Viva Supermarket', 'Viva', ChainFormat::Supermarket, 2],
        'west-zone-fresh' => ['West Zone Fresh', 'West Zone', ChainFormat::Convenience, 3],
    ];

    /** chain slug => [branch slug, name, region slug, lat, lng] */
    private const array BRANCHES = [
        'lulu-hypermarket' => [['al-nahda', 'Al Nahda', 'al-nahda', 25.2885, 55.3690], ['deira', 'Deira', 'deira', 25.2700, 55.3100]],
        'carrefour' => [['al-nahda', 'Al Nahda', 'al-nahda', 25.2899, 55.3741], ['al-barsha', 'Al Barsha', 'al-barsha', 25.1180, 55.2005], ['marina', 'Dubai Marina', 'dubai-marina', 25.0790, 55.1400]],
        'nesto-hypermarket' => [['al-nahda', 'Al Nahda', 'al-nahda', 25.2912, 55.3718], ['al-karama', 'Al Karama', 'al-karama', 25.2470, 55.3040], ['international-city', 'International City', 'international-city', 25.1650, 55.4100]],
        'union-coop' => [['al-barsha', 'Al Barsha', 'al-barsha', 25.1150, 55.2030], ['al-karama', 'Al Karama', 'al-karama', 25.2455, 55.3025]],
        'viva-supermarket' => [['al-nahda', 'Al Nahda', 'al-nahda', 25.2921, 55.3732], ['deira', 'Deira', 'deira', 25.2715, 55.3090]],
        'west-zone-fresh' => [['al-majaz', 'Al Majaz', 'al-majaz', 25.3255, 55.3845], ['al-nahda-sharjah', 'Al Nahda Sharjah', 'al-nahda-sharjah', 25.3000, 55.3745]],
    ];

    /** [group name, category, comparison unit, quantity, emoji], brand, product, option, [label, pack count, unit qty, unit] */
    private const array PRODUCTS = [
        [['Tomato', 'fruits-vegetables', 'kg', '1', '🍅'], null, 'Tomato', 'Origin', ['Jordan', 1, '1', 'kg']],
        [['Potato', 'fruits-vegetables', 'kg', '1', '🥔'], null, 'Potato', 'Origin', ['Lebanon', 1, '1', 'kg']],
        [['Onion', 'fruits-vegetables', 'kg', '1', '🧅'], null, 'Red Onion', 'Origin', ['India', 1, '1', 'kg']],
        [['Eggs', 'dairy-eggs', 'pc', '1', '🥚'], 'Al Jazira', 'Al Jazira White Eggs', 'Size', ['30 pack', 30, '1', 'pc']],
        [['Laban', 'dairy-eggs', 'l', '1', '🥛'], 'Al Rawabi', 'Al Rawabi Fresh Laban', 'Size', ['2 L', 1, '2', 'l']],
        [['Sunflower Oil', 'oil-ghee', 'l', '1', '🌻'], 'Noor', 'Noor Sunflower Oil', 'Size', ['1.5 L', 1, '1.5', 'l']],
        [['Sugar', 'flour-baking', 'kg', '1', '🍬'], 'Al Khaleej', 'Al Khaleej White Sugar', 'Size', ['2 kg', 1, '2', 'kg']],
        [['Whole Chicken', 'meat-poultry', 'kg', '1', '🍗'], 'Al Ain', 'Al Ain Fresh Whole Chicken', 'Size', ['1 kg', 1, '1', 'kg']],
        [['Bread', 'bakery', 'g', '100', '🍞'], 'Modern Bakery', 'Modern Bakery White Bread', 'Size', ['600 g', 1, '600', 'g']],
        [['Drinking Water', 'water', 'l', '1', '💧'], 'Mai Dubai', 'Mai Dubai Drinking Water', 'Size', ['12 × 1.5 L', 12, '1.5', 'l']],
        [['Red Lentils', 'pulses-lentils', 'kg', '1', '🥜'], 'Tilda', 'Tilda Red Lentils', 'Size', ['1 kg', 1, '1', 'kg']],
        [['Black Tea', 'tea-coffee', 'pc', '100', '🍵'], 'Lipton', 'Lipton Yellow Label Tea', 'Size', ['100 bags', 100, '1', 'pc']],
        [['Instant Coffee', 'tea-coffee', 'g', '100', '☕'], 'Nescafe', 'Nescafe Classic Instant Coffee', 'Size', ['200 g', 1, '200', 'g']],
        [['Washing Powder', 'laundry', 'kg', '1', '🧺'], 'Ariel', 'Ariel Automatic Washing Powder', 'Size', ['5 kg', 1, '5', 'kg']],
        [['Facial Tissue', 'paper-tissues', 'sheet', '100', '🧻'], 'Fine', 'Fine Facial Tissue', 'Size', ['5 × 200 sheets', 5, '200', 'sheet']],
        [['Potato Chips', 'snacks-sweets', 'g', '100', '🍟'], "Lay's", "Lay's Salted Potato Chips", 'Size', ['170 g', 1, '170', 'g']],
        [['Cola', 'soft-drinks', 'l', '1', '🥤'], 'Pepsi', 'Pepsi Cola', 'Size', ['2.25 L', 1, '2.25', 'l']],
    ];

    /**
     * product slug, pack (null when it has only one), usual price, then each chain's offer price (null = no offer, usual price only).
     * Order of chains: Lulu, Carrefour, Nesto, Union Coop, Viva, West Zone.
     */
    private const array PRICES = [
        ['tomato', null, '4.75', ['3.50', '4.25', '3.95', null, '3.75', null]],
        ['potato', null, '3.95', ['2.95', null, '3.25', '3.15', null, null]],
        ['red-onion', null, '3.50', [null, '2.95', '2.75', null, '2.85', '3.10']],
        ['al-jazira-white-eggs', null, '18.50', ['14.95', '15.50', '13.95', '14.75', null, null]],
        ['almarai-full-fat-fresh-milk', '2-l', '11.50', [null, '10.25', '9.95', '10.50', '10.75', null]],
        ['al-rawabi-fresh-laban', null, '10.75', [null, null, '8.95', '9.25', '9.50', null]],
        ['india-gate-classic-basmati-rice', '5-kg', '54.50', [null, '47.50', '44.95', null, '49.00', null]],
        ['noor-sunflower-oil', null, '18.95', ['13.95', '14.50', null, '14.25', null, '15.50']],
        ['al-khaleej-white-sugar', null, '9.95', ['7.50', null, '6.95', '7.25', '7.75', null]],
        ['al-ain-fresh-whole-chicken', null, '19.50', [null, '15.95', '14.95', null, '16.50', null]],
        ['modern-bakery-white-bread', null, '6.50', ['4.95', '5.25', null, null, '5.00', '5.50']],
        ['mai-dubai-drinking-water', null, '18.00', ['12.95', '13.50', '12.75', null, null, '14.00']],
        ['tilda-red-lentils', null, '12.50', [null, '9.95', '9.50', null, null, null]],
        ['lipton-yellow-label-tea', null, '22.50', ['16.95', null, '15.95', '16.50', null, null]],
        ['nescafe-classic-instant-coffee', null, '39.00', [null, '29.95', '28.95', null, '31.00', null]],
        ['ariel-automatic-washing-powder', null, '69.00', ['45.00', '47.50', null, '46.00', null, null]],
        ['fine-facial-tissue', null, '32.00', [null, '21.95', '19.95', null, '22.50', null]],
        ['lays-salted-potato-chips', null, '11.50', ['7.95', null, '6.95', null, '8.25', null]],
        ['pepsi-cola', null, '8.50', ['5.95', '6.25', null, '5.75', null, null]],
    ];

    public function run(): void
    {
        $publish = app(PublishBrochure::class);

        foreach (self::PRODUCTS as [$group, $brand, $name, $option, $variant]) {
            $this->product($group, $brand, $name, $option, [$variant], []);
        }

        $country = Country::where('code', 'AE')->value('id');
        $chains = [];
        foreach (self::CHAINS as $slug => [$name, $groupName, $format, $grade]) {
            $group = RetailGroup::updateOrCreate(['slug' => str($groupName)->slug()], ['name' => ['en' => $groupName]]);
            $chains[$slug] = Chain::updateOrCreate(['slug' => $slug], [
                'retail_group_id' => $group->id,
                'country_id' => $country,
                'name' => ['en' => $name],
                'format' => $format,
                'grade' => $grade,
            ]);

            foreach (self::BRANCHES[$slug] as [$branch, $label, $region, $lat, $lng]) {
                $chains[$slug]->branches()->updateOrCreate(['slug' => $branch], [
                    'name' => ['en' => $label],
                    'region_id' => Region::where('slug', $region)->value('id'),
                    'location' => Point::makeGeodetic($lat, $lng),
                ]);
            }
        }

        $today = now(config('app.market_timezone'))->startOfDay();
        $variants = [];
        foreach (self::PRICES as [$product, $variant]) {
            $variants["{$product}:{$variant}"] = Product::where('slug', $product)->firstOrFail()->variants()
                ->when($variant, fn ($query) => $query->where('slug', $variant))
                ->value('id');
        }

        foreach (array_values($chains) as $position => $chain) {
            // Last month's prices, near the usual price, so deal scores have history to compare with.
            $past = Brochure::updateOrCreate(['slug' => "{$chain->slug}-demo-past"], [
                'chain_id' => $chain->id,
                'title' => ['en' => "{$chain->name} deals (last month)"],
                'valid_from' => $today->copy()->subDays(33),
                'valid_to' => $today->copy()->subDays(27),
            ]);
            $current = Brochure::updateOrCreate(['slug' => "{$chain->slug}-demo-week"], [
                'chain_id' => $chain->id,
                'title' => ['en' => "{$chain->name} weekly deals"],
                'valid_from' => $today->copy()->subDays(2),
                'valid_to' => $today->copy()->addDays(5),
            ]);

            foreach (self::PRICES as [$product, $variant, $usual, $offers]) {
                $variantId = $variants["{$product}:{$variant}"];
                $price = $offers[$position];

                if ($price === null) {
                    RegularPrice::updateOrCreate(
                        ['product_variant_id' => $variantId, 'chain_id' => $chain->id, 'observed_at' => $today->copy()->subDays(9 + $position)->toDateString()],
                        ['price' => $usual, 'source' => RegularPrice::SOURCE_STAFF],
                    );

                    continue;
                }

                Offer::updateOrCreate(['brochure_id' => $past->id, 'product_variant_id' => $variantId], [
                    'price' => number_format((float) $usual * 0.95, 2, '.', ''),
                    'regular_price' => $usual,
                ]);
                Offer::updateOrCreate(['brochure_id' => $current->id, 'product_variant_id' => $variantId], [
                    'price' => $price,
                    'regular_price' => $usual,
                    'promo_type' => PromoType::Price,
                ]);
            }

            $publish($past);
            $publish($current);
        }

        app(ExpireOffers::class)();

        $this->command?->info('Demo stores ready: '.count(self::CHAINS).' chains and '.count(self::PRICES).' priced products.');
    }
}
