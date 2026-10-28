<?php

namespace App\Domain\Catalog;

use InvalidArgumentException;

/**
 * Turns a pack price into a comparable price: AED 45.95 for 5 kg → AED 9.19 per kg.
 * Uses bcmath on decimal strings so money never goes through floats.
 */
final class UnitPriceCalculator
{
    /** Quantity in the dimension's base unit: 6 × 1.5 l → 9000 (ml). */
    public static function netBaseQty(int $packCount, string $unitQty, Unit $unit): string
    {
        if ($packCount < 1 || bccomp($unitQty, '0', 3) <= 0) {
            throw new InvalidArgumentException('Pack count and unit quantity must be positive.');
        }

        return bcmul(bcmul((string) $packCount, $unitQty, 6), (string) $unit->factor_to_base, 3);
    }

    /**
     * Price per comparison quantity (per 1 kg, per 100 sheets…), rounded half-up to 4 decimals.
     * Returns null when the units measure different things (kg vs pieces) or the quantity is empty.
     */
    public static function perComparisonUnit(string $price, string $netBaseQty, Unit $variantUnit, string $comparisonQty, Unit $comparisonUnit): ?string
    {
        if ($variantUnit->dimension !== $comparisonUnit->dimension || bccomp($netBaseQty, '0', 3) <= 0) {
            return null;
        }

        $comparisonBaseQty = bcmul($comparisonQty, (string) $comparisonUnit->factor_to_base, 6);

        return self::roundHalfUp(bcdiv(bcmul($price, $comparisonBaseQty, 10), $netBaseQty, 10), 4);
    }

    /** bcmath truncates; add half of the last digit first (inputs are never negative). */
    private static function roundHalfUp(string $value, int $scale): string
    {
        return bcadd($value, '0.'.str_repeat('0', $scale).'5', $scale);
    }
}
