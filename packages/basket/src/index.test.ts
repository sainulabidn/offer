import { describe, expect, it } from 'vitest';
import { compare, lineCost, type CandidateOffer, type PlanItem, type Store } from './index';

const offer = (o: Partial<CandidateOffer> & Pick<CandidateOffer, 'id' | 'chainId' | 'price'>): CandidateOffer => ({
  variantId: 1,
  productGroupId: 10,
  label: 'Item',
  ...o,
});

describe('lineCost', () => {
  it('multiplies a plain price', () => {
    expect(lineCost(offer({ id: 'a', chainId: 1, price: 9.95 }), 3)).toBe(29.85);
  });

  it('applies "2 for 10" in groups and charges the rest at the single price', () => {
    const o = offer({ id: 'a', chainId: 1, price: 5.5, promo: { type: 'multibuy', qty: 2, total: 10 } });
    expect(lineCost(o, 2)).toBe(10);
    expect(lineCost(o, 3)).toBe(15.5);
  });

  it('applies "buy 2 get 1 free"', () => {
    const o = offer({ id: 'a', chainId: 1, price: 9, promo: { type: 'bogo', buy: 2, get: 1 } });
    expect(lineCost(o, 3)).toBe(18);
    expect(lineCost(o, 2)).toBe(18);
    expect(lineCost(o, 4)).toBe(27);
  });

  it('charges the regular price above the per-customer limit', () => {
    const o = offer({ id: 'a', chainId: 1, price: 4, regularPrice: 6, maxPerCustomer: 2 });
    expect(lineCost(o, 3)).toBe(14);
  });
});

describe('compare', () => {
  const stores: Store[] = [
    { chainId: 1, chainName: 'Lulu', distanceKm: 2, branch: 'Al Barsha' },
    { chainId: 2, chainName: 'Carrefour', distanceKm: 4, branch: 'MOE' },
    { chainId: 3, chainName: 'Union Coop', distanceKm: 1, branch: 'Al Wasl' },
  ];
  const rice: PlanItem = { key: 'rice', name: 'Rice 5 kg', quantity: 1, match: { variantId: 1 } };
  const milk: PlanItem = { key: 'milk', name: 'Milk 2 L', quantity: 2, match: { variantId: 2 } };
  const offers = [
    offer({ id: 'r1', chainId: 1, variantId: 1, price: 45.95, regularPrice: 54.5 }),
    offer({ id: 'r2', chainId: 2, variantId: 1, price: 49 }),
    offer({ id: 'm1', chainId: 1, variantId: 2, price: 11.5 }),
    offer({ id: 'm2', chainId: 2, variantId: 2, price: 11.5, promo: { type: 'multibuy', qty: 2, total: 20 } }),
    offer({ id: 'm3', chainId: 3, variantId: 2, price: 8 }),
  ];

  it('ranks complete baskets first, then by total', () => {
    const { stores: ranked } = compare([rice, milk], offers, stores);

    // Lulu 45.95 + 2 × 11.50 = 68.95; Carrefour 49 + "2 for 20" = 69.00; Union Coop has no rice.
    expect(ranked.map((r) => [r.store.chainName, r.total, r.missing.length])).toEqual([
      ['Lulu', 68.95, 0],
      ['Carrefour', 69, 0],
      ['Union Coop', 16, 1],
    ]);
    expect(ranked[0].saving).toBe(8.55);
  });

  it('suggests a split only when it beats the best store after travel cost', () => {
    // Rice at Lulu 45.95 + milk at Union Coop 16 = 61.95; +10 travel = 71.95 > Lulu alone 68.95.
    expect(compare([rice, milk], offers, stores, { maxStores: 2, extraStoreCost: 10 }).split).toBeNull();

    const split = compare([rice, milk], offers, stores, { maxStores: 2, extraStoreCost: 2 }).split!;
    expect(split.stores.map((s) => s.chainName).sort()).toEqual(['Lulu', 'Union Coop']);
    expect(split.total).toBe(61.95);
    expect(split.effectiveTotal).toBe(63.95);
  });

  it('picks the cheapest per unit for "any brand" items', () => {
    const anyRice: PlanItem = { key: 'any', name: 'Any basmati', quantity: 1, match: { productGroupId: 10 } };
    const groupOffers = [
      offer({ id: 'small', chainId: 1, variantId: 7, price: 5.25, unitPrice: 10.5 }),
      offer({ id: 'big', chainId: 1, variantId: 1, price: 45.95, unitPrice: 9.19 }),
    ];

    expect(compare([anyRice], groupOffers, stores).stores[0].lines[0].offer.id).toBe('big');
  });
});
