import { distanceKm, fade, type Point } from './index';

/**
 * A shopper's activity: one fading number per store or item, so it covers most visited, most clicked
 * and recent, with no event log. Kept in the browser, and on the account once the shopper agrees.
 */
export type ActivityKind = 'chain' | 'branch' | 'product' | 'group' | 'category' | 'brand';
export type ActivityEvent = 'store_visit' | 'click' | 'directions' | 'plan' | 'like' | 'not_interested';

export interface ActivityEntry {
  /** Score at time `t`. */
  s: number;
  /** Milliseconds since 1970. */
  t: number;
}

export type Profile = Record<string, ActivityEntry>;

export interface ActivitySettings {
  storeHalfLife: number;
  itemHalfLife: number;
  weights: Record<ActivityEvent, { store: number; item: number }>;
}

export const DEFAULT_ACTIVITY: ActivitySettings = {
  storeHalfLife: 30,
  itemHalfLife: 21,
  weights: {
    store_visit: { store: 1, item: 0 },
    click: { store: 1, item: 2 },
    directions: { store: 5, item: 0 },
    plan: { store: 3, item: 5 },
    like: { store: 3, item: 4 },
    not_interested: { store: 0, item: -8 },
  },
};

const DAY = 86_400_000;
export const MAX_PROFILE_ENTRIES = 400;

const isStore = (key: string) => key.startsWith('chain:') || key.startsWith('branch:');
const halfLife = (key: string, settings: ActivitySettings) => (isStore(key) ? settings.storeHalfLife : settings.itemHalfLife);

/** An entry's score right now. */
export function scoreNow(profile: Profile, key: string, now: number, settings = DEFAULT_ACTIVITY): number {
  const entry = profile[key];
  return entry ? fade(entry.s, (now - entry.t) / DAY, halfLife(key, settings)) : 0;
}

/** Adds an event to targets such as "product:12" and "chain:3"; the weakest entries drop off a full profile. */
export function recordEvent(profile: Profile, event: ActivityEvent, targets: string[], now: number, settings = DEFAULT_ACTIVITY): Profile {
  const next = { ...profile };
  for (const key of targets) {
    const weight = settings.weights[event][isStore(key) ? 'store' : 'item'];
    if (weight !== 0) next[key] = { s: scoreNow(profile, key, now, settings) + weight, t: now };
  }

  const keys = Object.keys(next);
  if (keys.length > MAX_PROFILE_ENTRIES) {
    const weakest = keys.sort((a, b) => Math.abs(scoreNow(next, b, now, settings)) - Math.abs(scoreNow(next, a, now, settings))).slice(MAX_PROFILE_ENTRIES);
    for (const key of weakest) delete next[key];
  }
  return next;
}

/** Ids of one kind with the highest scores, for the search boost. Negative scores never make the list. */
export function topIds(profile: Profile, kind: ActivityKind, limit: number, now: number, settings = DEFAULT_ACTIVITY): number[] {
  return Object.keys(profile)
    .filter((key) => key.startsWith(`${kind}:`) && scoreNow(profile, key, now, settings) > 0)
    .sort((a, b) => scoreNow(profile, b, now, settings) - scoreNow(profile, a, now, settings))
    .slice(0, limit)
    .map((key) => Number(key.split(':')[1]));
}

/** Branch id → activity, with each branch's chain counting a little, for ordering stores. */
export function storeActivity(profile: Profile, branches: { id: number; chainId: number }[], now: number, settings = DEFAULT_ACTIVITY): Record<number, number> {
  return Object.fromEntries(branches.map((branch) => [branch.id, scoreNow(profile, `branch:${branch.id}`, now, settings) + scoreNow(profile, `chain:${branch.chainId}`, now, settings)]));
}

/** Group id → the days it was added to a plan, for learning how often a shopper buys it. */
export type BuyingRhythm = Record<string, number[]>;

const KEEP_ADDS = 6;
const MAX_RHYTHM_GROUPS = 100;
/** Two adds this close are one shop. */
const SAME_SHOP_DAYS = 2;
/** It takes three shops to see a rhythm. */
const MIN_ADDS = 3;
/** Long past due, they probably stopped buying it. */
const GIVE_UP_AFTER = 3;
const ALMOST_DUE = 0.85;

/** Notes a plan add for each group, one per shop, keeping the latest few. */
export function noteBought(rhythm: BuyingRhythm, groupIds: number[], now: number): BuyingRhythm {
  const next = { ...rhythm };
  for (const id of groupIds) {
    const days = next[id] ?? [];
    if (days.length && (now - days[days.length - 1]) / DAY < SAME_SHOP_DAYS) continue;
    next[id] = [...days, now].slice(-KEEP_ADDS);
  }

  const groups = Object.keys(next);
  if (groups.length > MAX_RHYTHM_GROUPS) {
    const oldest = groups.sort((a, b) => next[b][next[b].length - 1] - next[a][next[a].length - 1]).slice(MAX_RHYTHM_GROUPS);
    for (const id of oldest) delete next[id];
  }
  return next;
}

/** Groups due again, most overdue first: milk every 5 days comes up well before rice every 60. */
export function dueGroups(rhythm: BuyingRhythm, now: number, limit: number): number[] {
  const overdue: [number, number][] = [];
  for (const [id, days] of Object.entries(rhythm)) {
    if (days.length < MIN_ADDS) continue;
    const gaps = days.slice(1).map((day, i) => (day - days[i]) / DAY).sort((a, b) => a - b);
    const usual = gaps[Math.floor(gaps.length / 2)];
    const late = (now - days[days.length - 1]) / DAY / usual;
    if (late >= ALMOST_DUE && late <= GIVE_UP_AFTER) overdue.push([Number(id), late]);
  }
  return overdue.sort((a, b) => b[1] - a[1]).slice(0, limit).map(([id]) => id);
}

export interface Candidate {
  id: string;
  productId: number;
  groupId: number;
  chainId: number;
  categoryIds: number[];
  brandId?: number;
  grade: number;
  /** % below the 90-day median price. */
  dealScore?: number;
  /** 0–1: the product's shared rank, which holds its popularity. */
  popularity: number;
  /** Times shown to this shopper with no click. */
  ignored: number;
  /** The shopper usually buys this about now. */
  due?: boolean;
  isLowest?: boolean;
  suspicious?: boolean;
  underReview?: boolean;
}

export interface ValueWeights {
  interest: number;
  grade: number;
  deal: number;
  popularity: number;
  near: number;
}

export interface Scored<T> {
  item: T;
  value: number;
  reason: string;
  /** Matches something the shopper has shown interest in. */
  known: boolean;
}

/** Shown this many times with no click, an item starts losing value. */
const IGNORED_FREE = 4;

/**
 * How much an item is worth showing this shopper: personal interest, daily use, real deal quality,
 * popularity and nearness to stores they use, minus penalties. The card's reason is the strongest signal.
 */
export function scoreOffer<T extends Candidate>(item: T, profile: Profile, weights: ValueWeights, now: number, settings = DEFAULT_ACTIVITY): Scored<T> {
  const most = Math.max(1, ...Object.keys(profile).filter((key) => !isStore(key)).map((key) => scoreNow(profile, key, now, settings)));
  const score = (key: string) => scoreNow(profile, key, now, settings);

  const direct = Math.max(score(`product:${item.productId}`), score(`group:${item.groupId}`));
  const broad = Math.max(0, item.brandId ? score(`brand:${item.brandId}`) : 0, ...item.categoryIds.map((id) => score(`category:${id}`)));
  const disliked = Math.min(score(`product:${item.productId}`), score(`group:${item.groupId}`));
  const interest = Math.min(1, Math.max(0, Math.max(direct, broad * 0.5) / most));

  const signals = {
    interest: weights.interest * interest,
    grade: weights.grade * ((4 - Math.min(Math.max(item.grade, 1), 4)) / 3),
    deal: weights.deal * (Math.min(Math.max(item.dealScore ?? 0, 0), 40) / 40),
    popularity: weights.popularity * Math.min(item.popularity, 1),
    near: weights.near * Math.min(1, score(`chain:${item.chainId}`) / 5),
  };

  const penalty = (item.suspicious ? 0.3 : 0) + (item.underReview ? 0.3 : 0) + Math.max(0, item.ignored - IGNORED_FREE) * 0.05 + (disliked < 0 ? 1 : 0);
  const value = Object.values(signals).reduce((sum, signal) => sum + signal, 0) - penalty + (item.due ? weights.interest : 0);

  const strongest = (Object.entries(signals) as [keyof typeof signals, number][]).sort((a, b) => b[1] - a[1])[0][0];
  const reason = item.due
    ? 'Time to buy again'
    : item.isLowest
    ? 'Lowest in 90 days'
    : { interest: 'You often check this', grade: 'A daily essential', deal: 'A real deal this week', popularity: 'Popular right now', near: 'At a store you use' }[strongest];

  return { item, value, reason, known: direct > 0 || broad > 0 };
}

export interface FeedLimits {
  slots: number;
  maxPerGroup: number;
  maxPerChain: number;
  discoverySlots: number;
}

/**
 * The final list: best first, but not all one thing, with a few slots kept for something outside the
 * shopper's usual items, so the feed doesn't shrink to what they already know. Anything they turned
 * down (negative value) never shows.
 */
export function rerank<T extends Candidate>(scored: Scored<T>[], limits: FeedLimits): Scored<T>[] {
  const ordered = scored.filter((entry) => entry.value > -0.5).sort((a, b) => b.value - a.value);
  const groups = new Map<number, number>();
  const chains = new Map<number, number>();
  const picked: Scored<T>[] = [];

  const fits = ({ item }: Scored<T>) => (groups.get(item.groupId) ?? 0) < limits.maxPerGroup && (chains.get(item.chainId) ?? 0) < limits.maxPerChain && !picked.some((entry) => entry.item.id === item.id);
  const pick = (entry: Scored<T>) => {
    picked.push(entry);
    groups.set(entry.item.groupId, (groups.get(entry.item.groupId) ?? 0) + 1);
    chains.set(entry.item.chainId, (chains.get(entry.item.chainId) ?? 0) + 1);
  };

  const main = limits.slots - limits.discoverySlots;
  for (const entry of ordered) if (picked.length < main && fits(entry)) pick(entry);
  for (const entry of ordered) if (picked.length < limits.slots && !entry.known && fits(entry)) pick({ ...entry, reason: 'Something new for you' });
  for (const entry of ordered) if (picked.length < limits.slots && fits(entry)) pick(entry);

  return picked;
}

export interface PlaceUse extends Point {
  id: string;
  is_default: boolean;
  is_auto: boolean;
  use_score?: number;
}

export interface AreaName extends Point {
  name: string;
}

/**
 * The default place when the shopper hasn't chosen one: their most used saved place, or else the
 * area of the stores they view most. A place they chose themselves always wins, so this returns null.
 */
export function chooseDefaultPlace<P extends PlaceUse>(
  places: P[],
  profile: Profile,
  branches: (Point & { id: number; chainId: number })[],
  areas: AreaName[],
  now: number,
  settings = DEFAULT_ACTIVITY,
): { place: P } | { area: AreaName } | null {
  if (places.some((place) => place.is_default && !place.is_auto)) return null;

  const used = [...places].filter((place) => (place.use_score ?? 0) > 0).sort((a, b) => (b.use_score ?? 0) - (a.use_score ?? 0))[0];
  if (used) return { place: used };

  const activity = storeActivity(profile, branches, now, settings);
  const byArea = new Map<AreaName, number>();
  for (const branch of branches) {
    const score = activity[branch.id];
    if (score <= 0 || !areas.length) continue;
    const area = areas.reduce((best, candidate) => (distanceKm(branch, candidate) < distanceKm(branch, best) ? candidate : best));
    byArea.set(area, (byArea.get(area) ?? 0) + score);
  }
  const best = [...byArea.entries()].sort((a, b) => b[1] - a[1])[0];
  return best && best[1] >= 3 ? { area: best[0] } : null;
}
