<?php

use App\Domain\Offers\OfferPricer;
use App\Domain\Offers\PromoType;

it('works out the price per item when the promotion is taken', function (string $price, PromoType $type, array $params, string $expected) {
    expect(OfferPricer::effectivePrice($price, $type, $params))->toBe($expected);
})->with([
    'plain price' => ['9.95', PromoType::Price, [], '9.95'],
    '2 for 10' => ['5.50', PromoType::Multibuy, ['qty' => 2, 'total' => 10], '5.0000'],
    '3 for 12' => ['4.50', PromoType::Multibuy, ['qty' => 3, 'total' => 12], '4.0000'],
    'buy 2 get 1 at 9' => ['9.00', PromoType::Bogo, ['buy' => 2, 'get' => 1], '6.0000'],
    'buy 1 get 1 at 10' => ['10.00', PromoType::Bogo, ['buy' => 1, 'get' => 1], '5.0000'],
    'multibuy without params' => ['7.00', PromoType::Multibuy, [], '7.00'],
]);
