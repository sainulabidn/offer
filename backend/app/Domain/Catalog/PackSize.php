<?php

namespace App\Domain\Catalog;

/**
 * A pack size as brochures print it: "6 x 200 ml", "200ml x 6", "1.5 Ltr", "500gm", "12's", "per kg".
 * Units are read through units.aliases (Unit::fromAlias), so new spellings are added there, not here.
 */
final readonly class PackSize
{
    public function __construct(
        public int $packCount,
        public string $unitQty,
        public Unit $unit,
        /** Sold by weight ("per kg"): the price is for one unit of measure, not a pack. */
        public bool $byWeight = false,
    ) {}

    public static function parse(?string $text): ?self
    {
        $text = mb_strtolower(trim((string) $text));
        $text = preg_replace('/(\d),(\d)/', '$1.$2', str_replace(['×', '*'], 'x', $text));
        $num = '(\d+(?:\.\d+)?)';
        $unit = "((?:fl\\s?oz)|'s|[a-z]+)";

        if (preg_match("~^(?:per|/)\\s*{$unit}$~u", $text, $m) && ($u = Unit::fromAlias($m[1]))) {
            return new self(1, '1', $u, byWeight: true);
        }
        if (preg_match("~(\\d+)\\s*x\\s*{$num}\\s*{$unit}~u", $text, $m) && ($u = Unit::fromAlias($m[3]))) {
            return self::make((int) $m[1], $m[2], $u);
        }
        if (preg_match("~{$num}\\s*{$unit}\\s*x\\s*(\\d+)~u", $text, $m) && ($u = Unit::fromAlias($m[2]))) {
            return self::make((int) $m[3], $m[1], $u);
        }
        if (preg_match("~{$num}\\s*{$unit}~u", $text, $m) && ($u = Unit::fromAlias($m[2]))) {
            return self::make(1, $m[1], $u);
        }

        return null;
    }

    private static function make(int $packCount, string $qty, Unit $unit): ?self
    {
        return $packCount >= 1 && (float) $qty > 0 ? new self($packCount, $qty, $unit) : null;
    }

    /** Total in the dimension's base unit (6 × 200 ml → 1200), comparable with product_variants.net_base_qty. */
    public function netBaseQty(): string
    {
        return UnitPriceCalculator::netBaseQty($this->packCount, $this->unitQty, $this->unit);
    }

    /** "6x200ml", "1x5kg", "per-kg": the size part of a learned alias. */
    public function key(): string
    {
        return $this->byWeight ? "per-{$this->unit->code}" : $this->packCount.'x'.rtrim(rtrim(number_format((float) $this->unitQty, 3, '.', ''), '0'), '.').$this->unit->code;
    }
}
