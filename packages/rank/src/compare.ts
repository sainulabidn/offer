/**
 * Prices of the same packs side by side at chosen stores. A store's cell is its live offer, or else
 * its last usual price, marked with the day it was seen. Never a guess: no price means an empty cell.
 */
export interface CompareColumn {
  branchId: number;
  chainId: number;
  /** Live brochures covering this branch. */
  brochureIds: number[];
}

export interface CompareOffer {
  variantId: number;
  chainId: number;
  brochureId: number;
  /** What one pack really costs under the offer. */
  price: number;
  rank: number;
  card?: boolean;
  /** Other chains' usual prices for this pack: [chain id, price, day]. */
  usual: [number, number, string][];
}

export interface CompareCell {
  price: number;
  usualOn?: string;
  card?: boolean;
}

export interface CompareRow<T extends CompareOffer> {
  offer: T;
  /** One per column, in column order. */
  cells: (CompareCell | null)[];
  /** Column index of the lowest price, when two or more stores have one. */
  cheapest: number | null;
}

export function compareRows<T extends CompareOffer>(offers: T[], columns: CompareColumn[], { oldest }: { oldest: string }): CompareRow<T>[] {
  const byVariant = new Map<number, T[]>();
  for (const offer of offers) byVariant.set(offer.variantId, [...(byVariant.get(offer.variantId) ?? []), offer]);

  const rows = [...byVariant.values()].map((packOffers) => {
    const cells = columns.map((column): CompareCell | null => {
      const live = packOffers.filter((offer) => column.brochureIds.includes(offer.brochureId)).sort((a, b) => a.price - b.price)[0];
      if (live) return { price: live.price, card: live.card };

      const usual = packOffers
        .flatMap((offer) => offer.usual)
        .filter(([chainId, , day]) => chainId === column.chainId && day >= oldest)
        .sort((a, b) => b[2].localeCompare(a[2]))[0];
      return usual ? { price: usual[1], usualOn: usual[2] } : null;
    });

    const priced = cells.flatMap((cell, index) => (cell ? [{ index, price: cell.price }] : []));
    const low = priced.length > 1 ? priced.reduce((best, cell) => (cell.price < best.price ? cell : best)) : null;
    return { offer: packOffers[0], cells, cheapest: low?.index ?? null };
  });

  const stores = (row: CompareRow<T>) => row.cells.filter(Boolean).length;
  return rows.sort((a, b) => stores(b) - stores(a) || b.offer.rank - a.offer.rank);
}

/** Totals over the packs every column has a price for, so the sums compare like with like. */
export function compareTotals<T extends CompareOffer>(rows: CompareRow<T>[], columns: number): { items: number; totals: number[] } {
  const complete = rows.filter((row) => row.cells.every(Boolean));
  const totals = Array.from({ length: columns }, (_, i) => Math.round(complete.reduce((sum, row) => sum + row.cells[i]!.price, 0) * 100) / 100);
  return { items: complete.length, totals };
}

/** What walking to another column saves on packs it sells cheaper than the first column, live offers only. */
export function walkSaving<T extends CompareOffer>(rows: CompareRow<T>[], column: number): { items: number; saving: number } {
  let items = 0;
  let saving = 0;
  for (const row of rows) {
    const here = row.cells[0];
    const there = row.cells[column];
    if (here && there && !there.usualOn && there.price < here.price) {
      items++;
      saving += here.price - there.price;
    }
  }
  return { items, saving: Math.round(saving * 100) / 100 };
}
