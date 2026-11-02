/**
 * Distances and ranking for the website. Pure functions, no DOM: runs in the browser and in the
 * Cloudflare Worker, and can serve the Flutter app later.
 */

export * from './places';
export * from './prefs';

export interface Point {
  lat: number;
  lng: number;
}

/** Great-circle distance in km. */
export function distanceKm(a: Point, b: Point): number {
  const rad = Math.PI / 180;
  const dLat = (b.lat - a.lat) * rad;
  const dLng = (b.lng - a.lng) * rad;
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLng / 2) ** 2;
  return 12_742 * Math.asin(Math.sqrt(h));
}

/** Rough walking time from a straight-line distance: streets are about 1.3 times longer, at 80 m a minute. */
export function walkMinutes(km: number): number {
  return Math.max(1, Math.round((km * 1000 * 1.3) / 80));
}

/** What a score is worth after some days: half of it is gone every half-life. */
export function fade(score: number, days: number, halfLifeDays: number): number {
  return score * 0.5 ** (Math.max(0, days) / halfLifeDays);
}

export interface StoreInput extends Point {
  id: number;
  chainId: number;
  grade: number;
  /** Live offers at this store. */
  offers: number;
}

export interface RankedStore<T> {
  store: T;
  /** From the shopper; null when their location isn't known. */
  km: number | null;
  score: number;
  selected: boolean;
}

export interface StoreWeights {
  activity: number;
  near: number;
  grade: number;
  offers: number;
}

export interface RankStoresOptions {
  at: Point | null;
  radiusKm: number;
  /** Selected branch ids: these always show, in range or not, so they can be deselected. */
  selected?: number[];
  /** Branch id → activity score: most visited, most clicked and recent, in one fading number. */
  activity?: Record<number, number>;
  /** Pinned favourite branch ids; these come first. */
  pinned?: number[];
  weights: StoreWeights;
}

/**
 * The stores to show, best first: one branch per chain, the one that scores best for this shopper,
 * so three Lulu branches don't fill the rail. With no history the order falls back to nearness,
 * grade and offers. Stores with no live offers go last.
 */
export function rankStores<T extends StoreInput>(stores: T[], options: RankStoresOptions): RankedStore<T>[] {
  const { at, radiusKm, selected = [], activity = {}, pinned = [], weights } = options;
  const most = Math.max(0, ...Object.values(activity));

  const scored = stores
    .map((store) => {
      const km = at ? distanceKm(at, store) : null;
      const near = km === null ? 0 : Math.exp(-km / Math.max(radiusKm / 2, 0.5));
      const score =
        weights.activity * (most > 0 ? Math.max(0, activity[store.id] ?? 0) / most : 0) +
        weights.near * near +
        weights.grade * ((4 - Math.min(Math.max(store.grade, 1), 4)) / 3) +
        weights.offers * Math.min(1, store.offers / 100);
      return { store, km, score, selected: selected.includes(store.id) };
    })
    .filter((item) => item.selected || item.km === null || item.km <= radiusKm);

  const rank = (item: RankedStore<T>) => (item.selected ? 0 : pinned.includes(item.store.id) ? 1 : item.store.offers > 0 ? 2 : 3);
  scored.sort((a, b) => rank(a) - rank(b) || b.score - a.score);

  const seen = new Set<number>();
  return scored.filter((item) => {
    if (item.selected) return seen.add(item.store.chainId), true;
    if (seen.has(item.store.chainId)) return false;
    seen.add(item.store.chainId);
    return true;
  });
}

/** The place name closest to a point. */
export function nearest<T extends Point>(places: T[], at: Point): T | undefined {
  let best: T | undefined;
  let bestKm = Infinity;
  for (const place of places) {
    const km = distanceKm(at, place);
    if (km < bestKm) [best, bestKm] = [place, km];
  }
  return best;
}
