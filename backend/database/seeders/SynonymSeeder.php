<?php

namespace Database\Seeders;

use App\Domain\Search\Synonym;
use App\Domain\Search\Synonyms;
use Illuminate\Database\Seeder;

/**
 * Starter search synonyms for UAE grocery shopping: English, Arabic and South Asian names for the same
 * item, and plural spellings. Safe to re-run: a group is skipped when its first word already exists.
 * Staff add more in the admin (Catalog → Search synonyms).
 */
class SynonymSeeder extends Seeder
{
    public const array GROUPS = [
        ['atta', 'wheat flour', 'chakki atta', 'whole wheat flour', 'طحين', 'دقيق'],
        ['dal', 'dhal', 'daal', 'lentils', 'lentil', 'عدس'],
        ['ghee', 'samn', 'سمن'],
        ['rice', 'chawal', 'أرز', 'ارز', 'رز'],
        ['laban', 'buttermilk', 'لبن'],
        ['milk', 'حليب'],
        ['yogurt', 'yoghurt', 'curd', 'dahi', 'zabadi', 'زبادي', 'روب'],
        ['chicken', 'دجاج'],
        ['egg', 'eggs', 'بيض'],
        ['tomato', 'tomatoes', 'طماطم', 'بندورة'],
        ['potato', 'potatoes', 'aloo', 'بطاطس', 'بطاطا'],
        ['onion', 'onions', 'pyaz', 'بصل'],
        ['chickpeas', 'chickpea', 'chana', 'kabuli chana', 'garbanzo', 'حمص'],
        ['coriander', 'cilantro', 'dhania', 'كزبرة'],
        ['okra', 'ladies finger', 'bhindi', 'بامية'],
        ['aubergine', 'eggplant', 'brinjal', 'باذنجان'],
        ['capsicum', 'bell pepper', 'فلفل رومي'],
        ['zucchini', 'courgette', 'كوسا'],
        ['banana', 'bananas', 'موز'],
        ['apple', 'apples', 'تفاح'],
        ['sugar', 'chini', 'سكر'],
        ['salt', 'namak', 'ملح'],
        ['tea', 'chai', 'شاي'],
        ['coffee', 'قهوة'],
        ['bread', 'khubz', 'خبز'],
        ['dates', 'tamr', 'تمر'],
        ['vermicelli', 'sevai', 'شعيرية'],
        ['semolina', 'sooji', 'suji', 'rava', 'سميد'],
        ['biscuits', 'biscuit', 'cookies', 'بسكويت'],
        ['diapers', 'nappies', 'حفاضات'],
        ['minced meat', 'mince', 'keema', 'لحم مفروم'],
        ['tissues', 'tissue', 'facial tissue', 'مناديل'],
        ['soft drink', 'soda', 'fizzy drink', 'مشروب غازي'],
        ['detergent', 'washing powder', 'laundry detergent', 'منظف'],
        ['cooking oil', 'vegetable oil', 'sunflower oil', 'زيت طبخ'],
    ];

    public function run(): void
    {
        foreach (self::GROUPS as $terms) {
            if (! Synonym::query()->whereJsonContains('terms', $terms[0])->exists()) {
                // Saved without events, then pushed to search once below.
                Synonym::withoutEvents(fn () => Synonym::create(['terms' => Synonym::clean($terms)]));
            }
        }

        app(Synonyms::class)->syncQuietly();
    }
}
