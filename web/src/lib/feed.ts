import { packPrice, promoFrom } from '@offer/basket';
import { compareRows, compareTotals, neighbours, walkMinutes, walkSaving, type CompareColumn, type CompareOffer, type Prefs } from '@offer/rank';
import { itemFilters, liveBrochures, storeCards, type Directory, type StoreCard } from './near';
import { searchOffers, type OfferHit } from './search';
import { distanceLabel } from './format';

/** Another store's price for the same pack, shown on a card. */
export interface ComparePrice {
  chain: string;
  price: number;
  /** Set when this is a last known usual price rather than a live offer: the day it was seen. */
  usualOn?: string;
  card?: boolean;
}

const COMPARE_STORES = 2;
const BIG_STORE_GRADE = 2;
const DAY = 86_400_000;

/**
 * Up to two other big stores' prices for each card, in one extra query rather than one per card.
 * Same pack only. A multi-buy counts at what one pack really costs, so "2 for 10" beats a 5.50 price.
 * Stores with no live offer fall back to their last usual price, labelled with its date.
 */
export async function comparePrices(hits: OfferHit[], dir: Directory, prefs: Prefs): Promise<Map<string, ComparePrice[]>> {
  const result = new Map<string, ComparePrice[]>();
  if (!hits.length) return result;

  const chains = new Map(dir.chains.map((chain) => [chain.id, chain]));
  const variantIds = [...new Set(hits.map((hit) => hit.variant_id))];

  // One row per pack and chain, so two different retail groups are always available.
  const others = await searchOffers({
    filters: [`variant_id:[${variantIds.join(',')}]`, `chain_grade:<=${BIG_STORE_GRADE}`, ...itemFilters(dir, prefs)],
    sort: 'unit',
    groupBy: 'variant_id,chain_id',
    perPage: 250,
    fields: 'variant_id,chain_id,chain_name,price,promo_qty,promo_total,promo_buy,promo_get,card_required',
  });

  const byVariant = new Map<number, OfferHit[]>();
  for (const offer of others.hits) byVariant.set(offer.variant_id, [...(byVariant.get(offer.variant_id) ?? []), offer]);

  const oldest = new Date(Date.now() - dir.settings.usual_price_max_days * DAY).toISOString().slice(0, 10);

  for (const hit of hits) {
    const own = chains.get(hit.chain_id);
    const usedGroups = new Set([own?.group_id ?? `chain:${hit.chain_id}`]);
    const usedChains = new Set([hit.chain_id]);
    const prices: ComparePrice[] = [];

    const add = (chainId: number, price: ComparePrice) => {
      const group = chains.get(chainId)?.group_id ?? `chain:${chainId}`;
      if (prices.length >= COMPARE_STORES || usedChains.has(chainId) || usedGroups.has(group)) return;
      usedChains.add(chainId);
      usedGroups.add(group);
      prices.push(price);
    };

    const grade = (chainId: number) => chains.get(chainId)?.grade ?? 9;
    const live = (byVariant.get(hit.variant_id) ?? [])
      .map((offer) => ({ offer, price: packPrice({ price: offer.price, promo: promoFrom(offer) }) }))
      .sort((a, b) => grade(a.offer.chain_id) - grade(b.offer.chain_id) || a.price - b.price);
    for (const { offer, price } of live) add(offer.chain_id, { chain: offer.chain_name, price, card: offer.card_required });

    for (const [chainId, price, day] of usualPrices(hit)) {
      const chain = chains.get(chainId);
      if (chain && chain.grade <= BIG_STORE_GRADE && day >= oldest) add(chainId, { chain: chain.name, price, usualOn: day });
    }

    result.set(hit.id, prices);
  }
  return result;
}

function usualPrices(hit: OfferHit): [number, number, string][] {
  try {
    return JSON.parse(hit.usual_prices ?? '[]');
  } catch {
    return [];
  }
}

export type PackOffer = CompareOffer & { hit: OfferHit };

const usualCutoff = (dir: Directory) => new Date(Date.now() - dir.settings.usual_price_max_days * DAY).toISOString().slice(0, 10);

/** Live offers at these stores, in the shape the comparison needs. */
async function packOffers(dir: Directory, columns: CompareColumn[]): Promise<PackOffer[]> {
  const brochureIds = [...new Set(columns.flatMap((column) => column.brochureIds))];
  if (!brochureIds.length) return [];

  const { hits } = await searchOffers({ filters: [`brochure_id:[${brochureIds.join(',')}]`], sort: 'rank', perPage: 250 });
  return hits.map((hit) => ({
    hit,
    variantId: hit.variant_id,
    chainId: hit.chain_id,
    brochureId: hit.brochure_id,
    price: packPrice({ price: hit.price, promo: promoFrom(hit) }),
    rank: hit.product_rank,
    card: hit.card_required,
    usual: usualPrices(hit),
  }));
}

const columnFor = (dir: Directory, store: StoreCard): CompareColumn => ({ branchId: store.id, chainId: store.chainId, brochureIds: liveBrochures(dir, store.id) });

/** Two or more selected stores side by side. */
export async function compareStores(dir: Directory, stores: StoreCard[]) {
  const columns = stores.map((store) => columnFor(dir, store));
  const rows = compareRows(await packOffers(dir, columns), columns, { oldest: usualCutoff(dir) });
  return { rows, ...compareTotals(rows, columns.length) };
}

/** "About 3 min walk" when it's close enough to walk, otherwise the distance. */
export function howFar(km: number, dir: Directory): string {
  return km * 1000 <= dir.settings.walk_limit_m ? `about ${walkMinutes(km)} min walk` : distanceLabel(km);
}

export interface NearbyPrice {
  chain: string;
  price: number;
  far: string;
  usualOn?: string;
  /** How much less it costs than the card's store; negative when it costs more. */
  cheaperBy: number;
}

/**
 * One selected store: its offers, each beside the nearest other store's price for the same pack, so
 * the shopper can plan to buy some things next door. Distances are from the store, not the shopper.
 */
export async function storeFocus(dir: Directory, store: StoreCard, limit = 40) {
  const cards = storeCards(dir);
  const near = neighbours(store, cards);
  const columns = [store, ...near.map(({ store: other }) => other)].map((card) => columnFor(dir, card));
  const all = await packOffers(dir, columns);
  const rows = compareRows(all, columns, { oldest: usualCutoff(dir) }).filter((row) => row.cells[0] && !row.cells[0].usualOn);

  const offers = rows.slice(0, limit).map((row) => {
    const own = row.cells[0]!;
    const hit = all.filter((offer) => offer.variantId === row.offer.variantId && columns[0].brochureIds.includes(offer.brochureId)).sort((a, b) => a.price - b.price)[0].hit;
    const index = row.cells.findIndex((cell, i) => i > 0 && cell);
    const other = index > 0 ? near[index - 1] : undefined;
    const cell = index > 0 ? row.cells[index] : null;
    const nearby: NearbyPrice | undefined = other && cell
      ? { chain: other.store.chain, price: cell.price, far: howFar(other.km, dir), usualOn: cell.usualOn, cheaperBy: Math.round((own.price - cell.price) * 100) / 100 }
      : undefined;
    return { hit, nearby };
  });

  const best = near
    .map(({ store: other, km }, i) => ({ store: other, km, ...walkSaving(rows, i + 1) }))
    .filter((option) => option.saving > 0)
    .sort((a, b) => b.saving - a.saving)[0];

  return { near, offers, found: rows.length, bestWalk: best ? { chain: best.store.chain, far: howFar(best.km, dir), saving: best.saving, items: best.items } : null };
}

/** What's good around the shopper: the cheapest offer of each product, the most useful products first. */
export function cheapestNearby(dir: Directory, prefs: Prefs, options: { page?: number; perPage?: number; filters?: string[] } = {}) {
  return searchOffers({
    filters: [...itemFilters(dir, prefs), ...(options.filters ?? [])],
    sort: 'rank',
    groupBy: 'product_id',
    page: options.page,
    perPage: options.perPage ?? 20,
  });
}
