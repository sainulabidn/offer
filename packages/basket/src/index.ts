/**
 * Shopping-plan comparison. Pure functions, no DOM: runs in the browser today and can run in a
 * Cloudflare Worker for the Flutter app later.
 */

export type Promo =
  | { type: 'multibuy'; qty: number; total: number } // "2 for AED 10"
  | { type: 'bogo'; buy: number; get: number }; // "buy 2 get 1 free"

export interface CandidateOffer {
  id: string;
  chainId: number;
  variantId: number;
  productGroupId: number;
  label: string;
  price: number;
  regularPrice?: number;
  /** Price per comparison unit (per kg, per L…) after the promotion, when known. */
  unitPrice?: number;
  promo?: Promo;
  maxPerCustomer?: number;
}

export interface PlanItem {
  key: string;
  name: string;
  quantity: number;
  /** An exact pack, or "any brand/size" of a product group (cheapest per unit wins). */
  match: { variantId: number } | { productGroupId: number };
}

export interface Store {
  chainId: number;
  chainName: string;
  distanceKm: number;
  branch: string;
}

export interface Line {
  item: PlanItem;
  offer: CandidateOffer;
  cost: number;
  saving: number;
}

export interface StoreResult {
  store: Store;
  total: number;
  saving: number;
  lines: Line[];
  missing: PlanItem[];
}

export interface SplitResult {
  stores: Store[];
  total: number;
  /** total plus the travel cost of the extra stores */
  effectiveTotal: number;
  lines: (Line & { store: Store })[];
  missing: PlanItem[];
}

export interface Options {
  maxStores?: 1 | 2 | 3;
  /** AED added per extra store visited, so a split must save more than the trip costs. */
  extraStoreCost?: number;
}

const money = (n: number) => Math.round(n * 100) / 100;

/** What `quantity` items cost with this offer, applying multi-buy, buy-n-get-m and per-customer limits. */
export function lineCost(offer: CandidateOffer, quantity: number): number {
  if (quantity <= 0) return 0;
  let promoQty = quantity;
  let cost = 0;

  if (offer.maxPerCustomer && quantity > offer.maxPerCustomer) {
    promoQty = offer.maxPerCustomer;
    cost += (quantity - promoQty) * (offer.regularPrice ?? offer.price);
  }

  const promo = offer.promo;
  if (promo?.type === 'multibuy' && promo.qty > 1) {
    cost += Math.floor(promoQty / promo.qty) * promo.total + (promoQty % promo.qty) * offer.price;
  } else if (promo?.type === 'bogo' && promo.buy > 0) {
    const group = promo.buy + promo.get;
    const paid = Math.floor(promoQty / group) * promo.buy + Math.min(promoQty % group, promo.buy);
    cost += paid * offer.price;
  } else {
    cost += promoQty * offer.price;
  }

  return money(cost);
}

function matches(item: PlanItem, offer: CandidateOffer): boolean {
  return 'variantId' in item.match ? offer.variantId === item.match.variantId : offer.productGroupId === item.match.productGroupId;
}

/** The best line for one item at one chain: exact items by cost, "any" items by unit price. */
function bestLine(item: PlanItem, offers: CandidateOffer[]): Line | null {
  let best: Line | null = null;
  for (const offer of offers) {
    if (!matches(item, offer)) continue;
    const cost = lineCost(offer, item.quantity);
    const better = !best
      ? true
      : 'productGroupId' in item.match && offer.unitPrice !== undefined && best.offer.unitPrice !== undefined
        ? offer.unitPrice < best.offer.unitPrice || (offer.unitPrice === best.offer.unitPrice && cost < best.cost)
        : cost < best.cost;
    if (better) {
      const regular = offer.regularPrice !== undefined ? offer.regularPrice * item.quantity : cost;
      best = { item, offer, cost, saving: money(Math.max(0, regular - cost)) };
    }
  }
  return best;
}

function* combinations<T>(list: T[], size: number, start = 0, prefix: T[] = []): Generator<T[]> {
  if (prefix.length === size) {
    yield prefix;
    return;
  }
  for (let i = start; i < list.length; i++) yield* combinations(list, size, i + 1, [...prefix, list[i]]);
}

/**
 * Per-store totals (complete baskets first, then cheapest, then nearest) and, when maxStores > 1,
 * the cheapest split across up to 3 stores that beats every single store after travel cost.
 */
export function compare(items: PlanItem[], offers: CandidateOffer[], stores: Store[], options: Options = {}) {
  const { maxStores = 1, extraStoreCost = 10 } = options;
  const byChain = new Map<number, CandidateOffer[]>();
  for (const offer of offers) byChain.set(offer.chainId, [...(byChain.get(offer.chainId) ?? []), offer]);

  // Cost matrix: chain → item key → best line.
  const matrix = new Map<number, Map<string, Line>>();
  for (const store of stores) {
    const row = new Map<string, Line>();
    for (const item of items) {
      const line = bestLine(item, byChain.get(store.chainId) ?? []);
      if (line) row.set(item.key, line);
    }
    matrix.set(store.chainId, row);
  }

  const singles: StoreResult[] = stores
    .map((store) => {
      const row = matrix.get(store.chainId)!;
      const lines = items.flatMap((item) => (row.has(item.key) ? [row.get(item.key)!] : []));
      return {
        store,
        lines,
        total: money(lines.reduce((sum, l) => sum + l.cost, 0)),
        saving: money(lines.reduce((sum, l) => sum + l.saving, 0)),
        missing: items.filter((item) => !row.has(item.key)),
      };
    })
    .filter((r) => r.lines.length > 0)
    .sort((a, b) => a.missing.length - b.missing.length || a.total - b.total || a.store.distanceKm - b.store.distanceKm);

  let split: SplitResult | null = null;
  if (maxStores > 1 && singles.length > 1) {
    const pool = singles.slice(0, 12).map((s) => s.store);
    for (let size = 2; size <= maxStores; size++) {
      for (const combo of combinations(pool, size)) {
        const lines: SplitResult['lines'] = [];
        const missing: PlanItem[] = [];
        for (const item of items) {
          let best: (Line & { store: Store }) | null = null;
          for (const store of combo) {
            const line = matrix.get(store.chainId)!.get(item.key);
            if (line && (!best || line.cost < best.cost)) best = { ...line, store };
          }
          best ? lines.push(best) : missing.push(item);
        }
        const used = combo.filter((s) => lines.some((l) => l.store.chainId === s.chainId));
        if (used.length < combo.length) continue; // a store adds nothing; the smaller combo covers it
        const total = money(lines.reduce((sum, l) => sum + l.cost, 0));
        const candidate = { stores: combo, lines, missing, total, effectiveTotal: money(total + (combo.length - 1) * extraStoreCost) };
        if (!split || candidate.missing.length < split.missing.length || (candidate.missing.length === split.missing.length && candidate.effectiveTotal < split.effectiveTotal)) {
          split = candidate;
        }
      }
    }
    // Only worth suggesting when it covers more items, or is cheaper than the best single store even after travel.
    const top = singles[0];
    if (split && !(split.missing.length < top.missing.length || (split.missing.length === top.missing.length && split.effectiveTotal < top.total))) {
      split = null;
    }
  }

  return { stores: singles, split };
}
