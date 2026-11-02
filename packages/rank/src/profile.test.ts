import { describe, expect, it } from 'vitest';
import { MAX_PROFILE_ENTRIES, chooseDefaultPlace, dueGroups, noteBought, recordEvent, rerank, scoreNow, scoreOffer, storeActivity, topIds, type Candidate, type Profile } from './profile';

const NOW = Date.UTC(2026, 10, 2);
const DAY = 86_400_000;
const weights = { interest: 0.4, grade: 0.2, deal: 0.2, popularity: 0.1, near: 0.1 };

const candidate = (id: string, more: Partial<Candidate> = {}): Candidate => ({ id, productId: Number(id), groupId: Number(id), chainId: 1, categoryIds: [], grade: 3, popularity: 0, ignored: 0, ...more });

describe('recordEvent', () => {
  it('uses store weights for stores and item weights for items', () => {
    const profile = recordEvent({}, 'plan', ['chain:1', 'product:2'], NOW);

    expect(profile['chain:1'].s).toBe(3);
    expect(profile['product:2'].s).toBe(5);
  });

  it('fades the old score before adding', () => {
    const start = recordEvent({}, 'directions', ['chain:1'], NOW - 30 * DAY);

    expect(recordEvent(start, 'store_visit', ['chain:1'], NOW)['chain:1'].s).toBeCloseTo(3.5);
  });

  it('skips events that mean nothing for that kind', () => {
    expect(recordEvent({}, 'directions', ['product:1'], NOW)).toEqual({});
  });

  it('keeps a full profile to its limit, dropping the weakest', () => {
    let profile: Profile = {};
    for (let i = 0; i <= MAX_PROFILE_ENTRIES; i++) profile[`product:${i}`] = { s: 1 + i, t: NOW };
    profile = recordEvent(profile, 'click', ['product:99999'], NOW);

    expect(Object.keys(profile)).toHaveLength(MAX_PROFILE_ENTRIES);
    expect(profile['product:0']).toBeUndefined();
    expect(profile[`product:${MAX_PROFILE_ENTRIES}`]).toBeDefined();
  });
});

describe('scoreNow', () => {
  it('halves stores after 30 days and items after 21', () => {
    const profile = { 'branch:1': { s: 8, t: NOW - 30 * DAY }, 'group:1': { s: 8, t: NOW - 21 * DAY } };

    expect(scoreNow(profile, 'branch:1', NOW)).toBeCloseTo(4);
    expect(scoreNow(profile, 'group:1', NOW)).toBeCloseTo(4);
  });
});

describe('topIds', () => {
  it('lists the strongest first, and never items turned down', () => {
    const profile = { 'group:1': { s: 2, t: NOW }, 'group:2': { s: 9, t: NOW }, 'group:3': { s: -8, t: NOW }, 'brand:4': { s: 9, t: NOW } };

    expect(topIds(profile, 'group', 5, NOW)).toEqual([2, 1]);
  });
});

describe('storeActivity', () => {
  it('counts a branch and its chain', () => {
    const profile = { 'branch:1': { s: 2, t: NOW }, 'chain:9': { s: 3, t: NOW } };

    expect(storeActivity(profile, [{ id: 1, chainId: 9 }, { id: 2, chainId: 8 }], NOW)).toEqual({ 1: 5, 2: 0 });
  });
});

describe('scoreOffer', () => {
  it('ranks what a shopper shows interest in above an equal stranger', () => {
    const profile = recordEvent({}, 'plan', ['group:1'], NOW);
    const liked = scoreOffer(candidate('1'), profile, weights, NOW);
    const other = scoreOffer(candidate('2'), profile, weights, NOW);

    expect(liked.value).toBeGreaterThan(other.value);
    expect(liked).toMatchObject({ reason: 'You often check this', known: true });
    expect(other.known).toBe(false);
  });

  it('works with no history, falling back to grade and deal', () => {
    const essential = scoreOffer(candidate('1', { grade: 1 }), {}, weights, NOW);
    const deal = scoreOffer(candidate('2', { dealScore: 40 }), {}, weights, NOW);

    expect(essential.value).toBeCloseTo(0.2 + (0 * weights.interest));
    expect(essential.reason).toBe('A daily essential');
    expect(deal.reason).toBe('A real deal this week');
  });

  it('names a lowest price first', () => {
    expect(scoreOffer(candidate('1', { isLowest: true }), {}, weights, NOW).reason).toBe('Lowest in 90 days');
  });

  it('takes value off an inflated discount, a reported price and items ignored again and again', () => {
    const base = scoreOffer(candidate('1', { grade: 1 }), {}, weights, NOW).value;

    expect(scoreOffer(candidate('1', { grade: 1, suspicious: true }), {}, weights, NOW).value).toBeLessThan(base);
    expect(scoreOffer(candidate('1', { grade: 1, underReview: true }), {}, weights, NOW).value).toBeLessThan(base);
    expect(scoreOffer(candidate('1', { grade: 1, ignored: 10 }), {}, weights, NOW).value).toBeLessThan(base);
    expect(scoreOffer(candidate('1', { grade: 1, ignored: 4 }), {}, weights, NOW).value).toBe(base);
  });

  it('counts brand and category as weaker signals than the item itself', () => {
    const byGroup = scoreOffer(candidate('1'), { 'group:1': { s: 5, t: NOW } }, weights, NOW);
    const byCategory = scoreOffer(candidate('1', { categoryIds: [7] }), { 'category:7': { s: 5, t: NOW } }, weights, NOW);

    expect(byGroup.value).toBeGreaterThan(byCategory.value);
    expect(byCategory.known).toBe(true);
  });
});

describe('buying rhythm', () => {
  
  const addsEvery = (gap: number, times: number) => Array.from({ length: times }, (_, i) => i * gap * DAY);

  it('counts one add per shop and keeps only the latest few', () => {
    let rhythm = noteBought({}, [5], 0);
    rhythm = noteBought(rhythm, [5], DAY);
    expect(rhythm[5]).toEqual([0]);

    for (let i = 1; i <= 8; i++) rhythm = noteBought(rhythm, [5], i * 7 * DAY);
    expect(rhythm[5]).toHaveLength(6);
  });

  it('brings up milk every 5 days long before rice every 60', () => {
    const rhythm = { 1: addsEvery(5, 4), 2: addsEvery(60, 4) };
    expect(dueGroups(rhythm, 15 * DAY + 5 * DAY, 5)).toEqual([1]);
    expect(dueGroups(rhythm, 180 * DAY + 55 * DAY, 5)).toEqual([2]);
  });

  it('needs three shops, and lets go of what they stopped buying', () => {
    expect(dueGroups({ 1: addsEvery(5, 2) }, 10 * DAY, 5)).toEqual([]);
    expect(dueGroups({ 1: addsEvery(5, 4) }, 60 * DAY, 5)).toEqual([]);
  });

  it('puts a due item first with a plain reason', () => {
    const plain = candidate('1');
    const due = candidate('1', { due: true });
    const result = scoreOffer(due, {}, weights, NOW);
    expect(result.value).toBeGreaterThan(scoreOffer(plain, {}, weights, NOW).value);
    expect(result.reason).toBe('Time to buy again');
  });
});

describe('rerank', () => {
  const limits = { slots: 4, maxPerGroup: 2, maxPerChain: 3, discoverySlots: 1 };
  const entry = (id: string, value: number, more: Partial<Candidate> = {}, known = true) => ({ item: candidate(id, more), value, reason: 'r', known });

  it('limits one product group and one store', () => {
    const picked = rerank(
      [entry('1', 9, { groupId: 1 }), entry('2', 8, { groupId: 1 }), entry('3', 7, { groupId: 1 }), entry('4', 6, { groupId: 2, chainId: 2 }), entry('5', 5, { groupId: 3, chainId: 3 })],
      limits,
    );

    expect(picked.map((p) => p.item.id)).toEqual(['1', '2', '4', '5']);
  });

  it('keeps slots for something new', () => {
    const picked = rerank([entry('1', 9, { chainId: 1 }), entry('2', 8, { chainId: 2 }), entry('3', 7, { chainId: 3 }), entry('4', 6, { chainId: 4 }), entry('5', 1, { chainId: 5 }, false)], limits);

    expect(picked.map((p) => p.item.id)).toEqual(['1', '2', '3', '5']);
    expect(picked[3].reason).toBe('Something new for you');
  });

  it('never shows what the shopper turned down', () => {
    expect(rerank([entry('1', -1)], limits)).toEqual([]);
  });

  it('fills discovery slots with the next best when nothing is new', () => {
    expect(rerank([entry('1', 9, { chainId: 1 }), entry('2', 8, { chainId: 2 }), entry('3', 7, { chainId: 3 }), entry('4', 6, { chainId: 4 })], limits)).toHaveLength(4);
  });
});

describe('chooseDefaultPlace', () => {
  const place = (id: string, more = {}) => ({ id, lat: 25, lng: 55, is_default: false, is_auto: false, ...more });
  const branches = [{ id: 1, chainId: 1, lat: 25.29, lng: 55.37 }, { id: 2, chainId: 2, lat: 24.45, lng: 54.37 }];
  const areas = [{ name: 'Al Nahda', lat: 25.29, lng: 55.37 }, { name: 'Abu Dhabi', lat: 24.45, lng: 54.37 }];

  it('never replaces a default the shopper chose', () => {
    expect(chooseDefaultPlace([place('a', { is_default: true }), place('b', { use_score: 9 })], {}, branches, areas, NOW)).toBeNull();
  });

  it('picks the most used saved place', () => {
    expect(chooseDefaultPlace([place('a', { use_score: 2 }), place('b', { use_score: 9 })], {}, branches, areas, NOW)).toEqual({ place: expect.objectContaining({ id: 'b' }) });
  });

  it('can replace its own earlier choice', () => {
    expect(chooseDefaultPlace([place('a', { is_default: true, is_auto: true, use_score: 1 }), place('b', { use_score: 9 })], {}, branches, areas, NOW)).toEqual({ place: expect.objectContaining({ id: 'b' }) });
  });

  it('falls back to the area of the most viewed stores', () => {
    const profile = { 'branch:2': { s: 6, t: NOW }, 'branch:1': { s: 1, t: NOW } };

    expect(chooseDefaultPlace([], profile, branches, areas, NOW)).toEqual({ area: areas[1] });
  });

  it('waits until there is enough to go on', () => {
    expect(chooseDefaultPlace([], { 'branch:1': { s: 1, t: NOW } }, branches, areas, NOW)).toBeNull();
    expect(chooseDefaultPlace([], {}, branches, areas, NOW)).toBeNull();
  });
});
