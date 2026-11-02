import { describe, expect, it } from 'vitest';
import { distanceKm, fade, nearest, rankStores, walkMinutes, type StoreInput } from './index';

describe('distanceKm', () => {
  it('measures a known distance', () => {
    const dubai = { lat: 25.2048, lng: 55.2708 };
    const abuDhabi = { lat: 24.4539, lng: 54.3773 };
    expect(distanceKm(dubai, abuDhabi)).toBeCloseTo(122.9, 0);
  });

  it('is zero for the same point', () => {
    expect(distanceKm({ lat: 25, lng: 55 }, { lat: 25, lng: 55 })).toBe(0);
  });
});

describe('walkMinutes', () => {
  it('allows for streets being longer than a straight line', () => {
    expect(walkMinutes(0.4)).toBe(7);
  });

  it('never says zero minutes', () => {
    expect(walkMinutes(0.01)).toBe(1);
  });
});

describe('fade', () => {
  it('halves after one half-life', () => {
    expect(fade(8, 30, 30)).toBeCloseTo(4);
  });

  it('keeps the whole score on the same day', () => {
    expect(fade(8, 0, 30)).toBe(8);
  });

  it('does not grow when a device clock runs backwards', () => {
    expect(fade(8, -5, 30)).toBe(8);
  });
});

describe('rankStores', () => {
  const at = { lat: 25.29, lng: 55.37 };
  const weights = { activity: 0.5, near: 0.3, grade: 0.1, offers: 0.1 };
  const store = (id: number, chainId: number, kmNorth: number, more: Partial<StoreInput> = {}): StoreInput => ({
    id,
    chainId,
    lat: at.lat + kmNorth / 111.2,
    lng: at.lng,
    grade: 2,
    offers: 50,
    ...more,
  });
  const ids = (ranked: { store: StoreInput }[]) => ranked.map((r) => r.store.id);

  it('with no history, puts near and big stores first', () => {
    const ranked = rankStores([store(1, 1, 8), store(2, 2, 1), store(3, 3, 1, { grade: 1 })], { at, radiusKm: 10, weights });

    expect(ids(ranked)).toEqual([3, 2, 1]);
  });

  it('puts the stores a shopper uses first, even when they are further away', () => {
    const ranked = rankStores([store(1, 1, 1), store(2, 2, 8)], { at, radiusKm: 10, weights, activity: { 2: 12 } });

    expect(ids(ranked)).toEqual([2, 1]);
  });

  it('shows only stores inside the radius', () => {
    expect(ids(rankStores([store(1, 1, 3), store(2, 2, 12)], { at, radiusKm: 10, weights }))).toEqual([1]);
  });

  it('keeps a selected store outside the radius, first, so it can be deselected', () => {
    const ranked = rankStores([store(1, 1, 1), store(2, 2, 30)], { at, radiusKm: 10, weights, selected: [2] });

    expect(ids(ranked)).toEqual([2, 1]);
    expect(ranked[0]).toMatchObject({ selected: true });
    expect(ranked[0].km).toBeCloseTo(30, 0);
  });

  it('shows one branch per chain, the best for this shopper', () => {
    const ranked = rankStores([store(1, 1, 1), store(2, 1, 5), store(3, 2, 2)], { at, radiusKm: 10, weights, activity: { 2: 9 } });

    expect(ids(ranked)).toEqual([2, 3]);
  });

  it('keeps two selected branches of one chain', () => {
    expect(ids(rankStores([store(1, 1, 1), store(2, 1, 5)], { at, radiusKm: 10, weights, selected: [1, 2] }))).toHaveLength(2);
  });

  it('puts pinned stores first, and stores with no offers last', () => {
    const ranked = rankStores([store(1, 1, 1, { offers: 0 }), store(2, 2, 2), store(3, 3, 9)], { at, radiusKm: 10, weights, pinned: [3] });

    expect(ids(ranked)).toEqual([3, 2, 1]);
  });

  it('works with no known location', () => {
    const ranked = rankStores([store(1, 1, 500), store(2, 2, 900, { grade: 1 })], { at: null, radiusKm: 10, weights });

    expect(ids(ranked)).toEqual([2, 1]);
    expect(ranked[0].km).toBeNull();
  });

  it('ignores negative activity', () => {
    const ranked = rankStores([store(1, 1, 1), store(2, 2, 2)], { at, radiusKm: 10, weights, activity: { 1: -8, 2: -1 } });

    expect(ids(ranked)).toEqual([1, 2]);
  });
});

describe('nearest', () => {
  it('finds the closest place', () => {
    const places = [{ name: 'Far', lat: 24, lng: 54 }, { name: 'Near', lat: 25.3, lng: 55.4 }];

    expect(nearest(places, { lat: 25.29, lng: 55.37 })?.name).toBe('Near');
    expect(nearest([], { lat: 25, lng: 55 })).toBeUndefined();
  });
});
