import { apiUrl } from './api';
import { distanceKm, nearest, rankStores, type OpeningHours, type Point, type Prefs, type RankedStore } from '@offer/rank';

/** "Near me": what the shopper's location means, worked out in the Worker from the store directory. */
export interface Directory {
  chains: { id: number; name: string; slug: string; grade: number; group_id: number | null; logo: string | null }[];
  /** region_id is the branch's area, or its emirate when it has no listed area. */
  branches: { id: number; chain_id: number; chain: string; name: string; slug: string; area: string | null; region_id: number | null; emirate: string | null; lat: number; lng: number; hours: OpeningHours[]; closed_today: boolean }[];
  brochures: { id: number; chain_id: number; valid_from: string; valid_to: string; branch_ids: number[]; offers: number }[];
  /** Emirates and their areas; an area's name is "Al Barsha, Dubai". */
  areas: { id: number; slug: string; name: string; name_ar: string | null; type: string; parent_id: number | null; lat: number; lng: number }[];
  settings: Settings;
}

export interface Settings {
  radius_options: number[];
  radius_default: number;
  item_radius_default: number;
  radius_max: number;
  usual_price_max_days: number;
  walk_limit_m: number;
  store_weights: { activity: number; near: number; grade: number; offers: number };
}

export type DirectoryBranch = Directory['branches'][number];

export interface StoreCard extends DirectoryBranch {
  chainId: number;
  chainSlug: string;
  groupId: number | null;
  grade: number;
  logo: string | null;
  offers: number;
}

export type RankedCard = RankedStore<StoreCard>;

let cached: { at: number; directory: Directory } | null = null;

export async function directory(): Promise<Directory> {
  if (!cached || Date.now() - cached.at > 300_000) {
    const response = await fetch(apiUrl('directory'), { headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error(`Directory failed with ${response.status}`);
    cached = { at: Date.now(), directory: (await response.json()) as Directory };
  }
  return cached.directory;
}

/** Where a page is drawn for: the shopper's point, or the middle of their emirate on a first visit. */
export function placeOf(dir: Directory, prefs: Prefs): { at: Point | null; name: string | null; rough: boolean } {
  if (prefs.at) return { at: prefs.at, name: nearest(dir.areas, prefs.at)?.name.split(',')[0] ?? null, rough: false };
  const emirate = dir.areas.find((area) => area.slug === prefs.emirate);
  return emirate ? { at: emirate, name: emirate.name, rough: true } : { at: null, name: null, rough: false };
}

/** The nearest listed area, for counts by area. */
export const regionId = (dir: Directory, prefs: Prefs) => (prefs.at ? (nearest(dir.areas, prefs.at)?.id ?? null) : null);

const clampRadius = (km: number, dir: Directory) => Math.min(km, dir.settings.radius_max);

export const storeRadius = (dir: Directory, prefs: Prefs) => clampRadius(prefs.radiusKm ?? dir.settings.radius_default, dir);

/** How far items may be from the shopper; null means all of the UAE. */
export function itemRadius(dir: Directory, prefs: Prefs): number | null {
  if (prefs.allUae || !placeOf(dir, prefs).at) return null;
  return clampRadius(prefs.itemRadiusKm ?? dir.settings.item_radius_default, dir);
}

/**
 * Typesense filter for a radius. A large circle barely changes for a shopper 5 km away, so its
 * middle is rounded harder: more shoppers then ask the identical query and share the cached answer.
 */
export function radiusFilter(at: Point, km: number): string {
  const decimals = km >= 25 ? 1 : 2;
  return `locations:(${at.lat.toFixed(decimals)},${at.lng.toFixed(decimals)},${km} km)`;
}

/** Leaves out offers only a store's own employees can buy, when the shopper unticked them. */
export function staffFilters(prefs: Prefs): string[] {
  return prefs.hideStaffOnly ? ['staff_only:false'] : [];
}

/** The Typesense filters that keep items inside the shopper's radius, and to the offers they want to see. */
export function itemFilters(dir: Directory, prefs: Prefs): string[] {
  const { at } = placeOf(dir, prefs);
  const km = itemRadius(dir, prefs);
  return [...(at && km ? [radiusFilter(at, km)] : []), ...staffFilters(prefs)];
}

/** Today in the market's timezone; the UAE has one offset all year. */
export const marketToday = () => new Date(Date.now() + 4 * 3_600_000).toISOString().slice(0, 10);

/** Brochures running today at a branch. */
export function liveBrochures(dir: Directory, branchId: number): number[] {
  const today = marketToday();
  return dir.brochures.filter((brochure) => brochure.valid_from <= today && brochure.branch_ids.includes(branchId)).map((brochure) => brochure.id);
}

/** Live offers per branch, from the brochures that cover it. */
function offersByBranch(dir: Directory): Map<number, number> {
  const today = marketToday();
  const counts = new Map<number, number>();
  for (const brochure of dir.brochures) {
    if (brochure.valid_from > today) continue;
    for (const id of brochure.branch_ids) counts.set(id, (counts.get(id) ?? 0) + brochure.offers);
  }
  return counts;
}

/** Every branch with what a store card shows. */
export function storeCards(dir: Directory): StoreCard[] {
  const chains = new Map(dir.chains.map((chain) => [chain.id, chain]));
  const offers = offersByBranch(dir);
  return dir.branches.map((branch) => {
    const chain = chains.get(branch.chain_id);
    return { ...branch, chainId: branch.chain_id, chainSlug: chain?.slug ?? '', groupId: chain?.group_id ?? null, grade: chain?.grade ?? 3, logo: chain?.logo ?? null, offers: offers.get(branch.id) ?? 0 };
  });
}

/** The stores to show for this shopper, before their personal order is applied in the browser. */
export function storesFor(dir: Directory, prefs: Prefs): RankedCard[] {
  const { at, rough } = placeOf(dir, prefs);
  // A rough emirate-level location can't support a radius: the emirate's own stores are the honest answer.
  const cards = storeCards(dir).filter((card) => !rough || card.emirate === prefs.emirate || prefs.stores.includes(card.id));
  return rankStores(cards, {
    at: rough ? null : at,
    radiusKm: storeRadius(dir, prefs),
    selected: prefs.stores,
    weights: dir.settings.store_weights,
  });
}

/** Brochures live within a distance → each one's nearest branch, for the distance shown on cards. */
export function nearestBranches(dir: Directory, at: Point, radiusKm: number | null) {
  const inRange = new Map<number, { km: number; branch: DirectoryBranch }>();
  for (const branch of dir.branches) {
    const km = distanceKm(at, branch);
    if (radiusKm === null || km <= radiusKm) inRange.set(branch.id, { km, branch });
  }

  const result = new Map<number, { km: number; branch: string }>();
  for (const brochure of dir.brochures) {
    for (const id of brochure.branch_ids) {
      const hit = inRange.get(id);
      if (hit && (!result.has(brochure.id) || hit.km < result.get(brochure.id)!.km)) {
        result.set(brochure.id, { km: hit.km, branch: hit.branch.name });
      }
    }
  }
  return result;
}
