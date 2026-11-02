import { describe, expect, it } from 'vitest';
import { compareRows, compareTotals, walkSaving, type CompareColumn, type CompareOffer } from './compare';

const columns: CompareColumn[] = [
  { branchId: 1, chainId: 10, brochureIds: [100] },
  { branchId: 2, chainId: 20, brochureIds: [200] },
];
const offer = (variantId: number, brochureId: number, price: number, more: Partial<CompareOffer> = {}): CompareOffer => ({
  variantId,
  brochureId,
  price,
  chainId: brochureId === 100 ? 10 : 20,
  rank: 0,
  usual: [],
  ...more,
});
const options = { oldest: '2026-09-01' };

describe('compareRows', () => {
  it('puts each store price in its column and marks the cheapest', () => {
    const [row] = compareRows([offer(1, 100, 5), offer(1, 200, 4)], columns, options);

    expect(row.cells.map((cell) => cell?.price)).toEqual([5, 4]);
    expect(row.cheapest).toBe(1);
  });

  it('fills a gap with a dated usual price', () => {
    const [row] = compareRows([offer(1, 100, 5, { usual: [[20, 6, '2026-10-12']] })], columns, options);

    expect(row.cells[1]).toEqual({ price: 6, usualOn: '2026-10-12' });
    expect(row.cheapest).toBe(0);
  });

  it('leaves a gap when the usual price is too old, and marks no cheapest with one price', () => {
    const [row] = compareRows([offer(1, 100, 5, { usual: [[20, 6, '2026-01-01']] })], columns, options);

    expect(row.cells[1]).toBeNull();
    expect(row.cheapest).toBeNull();
  });

  it('prefers a live offer to a usual price, and the lowest of two live offers', () => {
    const [row] = compareRows([offer(1, 100, 5, { usual: [[20, 9, '2026-10-12']] }), offer(1, 200, 7), offer(1, 200, 6)], columns, options);

    expect(row.cells[1]).toEqual({ price: 6, card: undefined });
  });

  it('shows items with more stores first, then by rank', () => {
    const rows = compareRows([offer(1, 100, 5, { rank: 9 }), offer(2, 100, 5, { rank: 1 }), offer(2, 200, 4, { rank: 1 }), offer(3, 200, 4, { rank: 5 })], columns, options);

    expect(rows.map((row) => row.offer.variantId)).toEqual([2, 1, 3]);
  });

  it('gives two branches of one chain the same prices', () => {
    const both = [columns[0], { branchId: 3, chainId: 10, brochureIds: [100] }];

    expect(compareRows([offer(1, 100, 5)], both, options)[0].cells.map((cell) => cell?.price)).toEqual([5, 5]);
  });
});

describe('compareTotals', () => {
  it('adds only packs every store has', () => {
    const rows = compareRows([offer(1, 100, 5), offer(1, 200, 4), offer(2, 100, 3)], columns, options);

    expect(compareTotals(rows, 2)).toEqual({ items: 1, totals: [5, 4] });
  });
});

describe('walkSaving', () => {
  it('counts only live offers cheaper at the other store', () => {
    const rows = compareRows(
      [offer(1, 100, 5), offer(1, 200, 3.5), offer(2, 100, 5), offer(2, 200, 6), offer(3, 100, 5, { usual: [[20, 1, '2026-10-12']] })],
      columns,
      options,
    );

    expect(walkSaving(rows, 1)).toEqual({ items: 1, saving: 1.5 });
  });
});
