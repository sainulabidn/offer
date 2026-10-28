<?php

use App\Domain\Catalog\Unit;
use App\Domain\Catalog\UnitDimension;
use App\Domain\Catalog\UnitPriceCalculator;

function unit(string $code, UnitDimension $dimension, string $factor): Unit
{
    return new Unit(['code' => $code, 'dimension' => $dimension, 'factor_to_base' => $factor]);
}

beforeEach(function () {
    $this->g = unit('g', UnitDimension::Mass, '1');
    $this->kg = unit('kg', UnitDimension::Mass, '1000');
    $this->l = unit('l', UnitDimension::Volume, '1000');
    $this->pc = unit('pc', UnitDimension::Count, '1');
    $this->sheet = unit('sheet', UnitDimension::Sheets, '1');
});

it('converts packs to base quantity', function (int $packs, string $qty, string $unit, string $expected) {
    expect(UnitPriceCalculator::netBaseQty($packs, $qty, $this->{$unit}))->toBe($expected);
})->with([
    '5 kg' => [1, '5', 'kg', '5000.000'],
    '500 g' => [1, '500', 'g', '500.000'],
    '6 × 1.5 l' => [6, '1.5', 'l', '9000.000'],
    '30 eggs' => [1, '30', 'pc', '30.000'],
    '5 × 200 sheets' => [5, '200', 'sheet', '1000.000'],
]);

it('prices per comparison unit', function (string $price, string $base, string $unit, string $perQty, string $perUnit, string $expected) {
    expect(UnitPriceCalculator::perComparisonUnit($price, $base, $this->{$unit}, $perQty, $this->{$perUnit}))->toBe($expected);
})->with([
    '45.95 for 5 kg → per kg' => ['45.95', '5000', 'kg', '1', 'kg', '9.1900'],
    '5.25 for 500 g → per kg' => ['5.25', '500', 'g', '1', 'kg', '10.5000'],
    '13.95 for 6 × 1.5 l → per l' => ['13.95', '9000', 'l', '1', 'l', '1.5500'],
    '15.95 for 30 eggs → per piece' => ['15.95', '30', 'pc', '1', 'pc', '0.5317'],
    '12.00 for 1000 sheets → per 100' => ['12.00', '1000', 'sheet', '100', 'sheet', '1.2000'],
]);

it('refuses to compare different dimensions', function () {
    expect(UnitPriceCalculator::perComparisonUnit('10', '1000', $this->kg, '1', $this->l))->toBeNull();
});

it('rejects empty packs', function () {
    UnitPriceCalculator::netBaseQty(0, '5', $this->kg);
})->throws(InvalidArgumentException::class);
