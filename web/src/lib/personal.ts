import { dueGroups, storeActivity, topIds, type Candidate, type Scored } from '@offer/rank';
import { searchConfig } from './plan';
import { activitySettings, buyingRhythm, loadProfile, personalOn, pinnedStores, shownCounts } from './profile';

/** One offer for the "For you" rail, as Typesense returns it. */
export interface ForYouHit {
  id: string;
  product_id: number;
  product_slug: string;
  product_name: string;
  product_group_id: number;
  product_rank: number;
  variant_slug: string;
  /** Packs of the product; with more than one, each has its own page. */
  pack_count?: number;
  variant_label: string;
  chain_id: number;
  chain_name: string;
  brand_id?: number;
  category_ids: number[];
  price: number;
  regular_price?: number;
  item_grade: number;
  deal_score?: number;
  discount_pct?: number;
  is_lowest_90d?: boolean;
  was_price_suspicious?: boolean;
  under_review?: boolean;
  image?: string;
  icon?: string;
}

export type ForYouCandidate = Candidate & { hit: ForYouHit };
export type ForYouEntry = Scored<ForYouCandidate>;

const CANDIDATES = 40;

export function toCandidate(hit: ForYouHit, ignored: number, due = false): ForYouCandidate {
  return {
    hit,
    id: hit.id,
    productId: hit.product_id,
    groupId: hit.product_group_id,
    chainId: hit.chain_id,
    categoryIds: hit.category_ids,
    brandId: hit.brand_id,
    grade: hit.item_grade,
    dealScore: hit.deal_score,
    popularity: hit.product_rank / 10000,
    ignored,
    due,
    isLowest: hit.is_lowest_90d,
    suspicious: hit.was_price_suspicious,
    underReview: hit.under_review,
  };
}

/**
 * The first ranking, in Typesense: what the shopper shows interest in rises to the top, then the shared
 * order, one offer per product. Only ids go out, and only in the query for the public offers index.
 */
export async function forYouCandidates(filters: string[]): Promise<ForYouCandidate[]> {
  const profile = loadProfile();
  const settings = activitySettings();
  const now = Date.now();
  const top = (kind: Parameters<typeof topIds>[1], limit: number) => topIds(profile, kind, limit, now, settings);

  const due = dueGroups(buyingRhythm(), now, 8);
  const boosts = [
    ['product_group_id', due, 5],
    ['product_id', top('product', 12), 4],
    ['product_group_id', top('group', 12), 3],
    ['category_ids', top('category', 6), 2],
    ['brand_id', top('brand', 6), 1],
  ].filter(([, ids]) => (ids as number[]).length) as [string, number[], number][];
  if (!boosts.length) return [];

  const boost = boosts.map(([field, ids, weight]) => `(${field}:[${ids.join(',')}]):${weight}`).join(',');
  const { url, collection, key } = await searchConfig();
  const params = new URLSearchParams({
    q: '*',
    query_by: 'product_name',
    sort_by: `_eval([${boost}]):desc,product_rank:desc,effective_unit_price:asc`,
    group_by: 'product_id',
    group_limit: '1',
    per_page: String(CANDIDATES),
  });
  if (filters.length) params.set('filter_by', filters.join(' && '));

  const response = await fetch(`${url}/collections/${collection}/documents/search?${params}`, { headers: { 'X-TYPESENSE-API-KEY': key } });
  if (!response.ok) return [];
  const body = (await response.json()) as { grouped_hits?: { hits: { document: ForYouHit }[] }[] };
  const shown = shownCounts();
  return (body.grouped_hits ?? []).map((group) => {
    const hit = group.hits[0].document;
    return toCandidate(hit, shown[hit.product_id] ?? 0, due.includes(hit.product_group_id));
  });
}

/**
 * Puts the home page's store cards in this shopper's order: their favourites, then their activity.
 * The server draws everyone the same order, because the page is cached; moving cards with CSS order
 * here means nothing re-renders.
 */
export function orderStoreCards() {
  if (!personalOn()) return;
  const cards = [...document.querySelectorAll<HTMLElement>('[data-store-card]')];
  if (cards.length < 2) return;

  const profile = loadProfile();
  const settings = activitySettings();
  const now = Date.now();
  const pinned = pinnedStores();
  const branches = cards.map((card) => ({ id: Number(card.dataset.branch), chainId: Number(card.dataset.chain) }));
  const activity = storeActivity(profile, branches, now, settings);
  const most = Math.max(0, ...Object.values(activity));

  const value = (card: HTMLElement) => {
    const id = Number(card.dataset.branch);
    const personal = most > 0 ? (0.5 * Math.max(0, activity[id] ?? 0)) / most : 0;
    return (card.dataset.selected ? 200 : 0) + (pinned.includes(id) ? 100 : 0) + Number(card.dataset.score) + personal;
  };

  [...cards].sort((a, b) => value(b) - value(a)).forEach((card, order) => (card.style.order = String(order)));
}
