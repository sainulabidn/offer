import type { Prefs } from '@offer/rank';
import { filterValue, searchOffers, type SortKey } from './search';
import { directory, itemFilters, itemRadius, nearestBranches, placeOf, staffFilters } from './near';

/** Facets for the "browse" links and page texts: per emirate/area, category, brand and store. */
export const BROWSE_FACETS = ['region_ids', 'category_ids', 'brand_id', 'chain_id'];

export interface ListingOptions {
  /** What the page itself is about: its category, brand, location or saved search. */
  baseFilters?: string[];
  /** Words a landing page searches for; the URL's own q wins. */
  q?: string;
  /** The order when the URL doesn't ask for one. */
  sort?: SortKey;
  /**
   * Keep to the shopper's distance (their saved place). Location pages list their emirate or area for
   * everyone instead, so the page is the same for shoppers and search engines.
   */
  nearShopper?: boolean;
  /** Also count offers per region, category, brand and store. */
  browse?: boolean;
}

/**
 * The search behind every list of offers (/search, categories, brands, locations, landing pages): the page's
 * own filters plus the shopper's (the sidebar form: a plain GET form, so it works without JavaScript).
 */
export async function runListing(url: URL, prefs: Prefs, { baseFilters = [], q: fixedQ = '', sort: fixedSort, nearShopper = true, browse = false }: ListingOptions = {}) {
  const params = url.searchParams;
  const q = params.get('q') ?? fixedQ;
  const sort = (params.get('sort') ?? fixedSort ?? (q ? 'unit' : 'deal')) as SortKey;
  const page = Math.max(1, Number(params.get('page')) || 1);
  const chains = params.getAll('store');
  const brands = params.getAll('brand');
  const cardOnly = params.get('card') === '1';
  const minDiscount = Number(params.get('min_discount')) || 0;
  // From a home page card: every tomato, whatever the origin or variety.
  const groupId = Number(params.get('group')) || 0;
  const hideStaffOnly = prefs.hideStaffOnly;
  const activeFilters = chains.length + brands.length + (cardOnly ? 1 : 0) + (minDiscount ? 1 : 0) + (hideStaffOnly ? 1 : 0);

  const filters = [...baseFilters];
  if (groupId) filters.push(`product_group_id:=${groupId}`);
  if (chains.length) filters.push(`chain_name:[${chains.map(filterValue).join(',')}]`);
  if (brands.length) filters.push(`brand:[${brands.map(filterValue).join(',')}]`);
  if (cardOnly) filters.push('card_required:true');
  if (minDiscount) filters.push(`discount_pct:>=${minDiscount}`);

  // "Near me": keep only offers at a branch inside the shopper's item radius.
  const dir = await directory();
  const place = nearShopper ? placeOf(dir, prefs) : { at: null, name: null, rough: false };
  const radiusKm = nearShopper ? itemRadius(dir, prefs) : null;
  filters.push(...(nearShopper ? itemFilters(dir, prefs) : staffFilters(prefs)));
  const nearby = place.at && !place.rough ? nearestBranches(dir, place.at, radiusKm) : undefined;

  const result = await searchOffers({
    q,
    filters,
    sort,
    page,
    facets: ['chain_name', 'brand', ...(browse ? BROWSE_FACETS : [])],
    maxFacetValues: browse ? 100 : 20,
  });
  const facet = (field: string) => result.facets.find((f) => f.field === field)?.values ?? [];
  /** id → live offers, from a numeric facet. */
  const counts = (field: string) => new Map(facet(field).map((v) => [Number(v.value), v.count]));

  return { q, sort, page, chains, brands, cardOnly, minDiscount, groupId, hideStaffOnly, activeFilters, place, radiusKm, nearby, result, facet, counts };
}

export type Listing = Awaited<ReturnType<typeof runListing>>;
