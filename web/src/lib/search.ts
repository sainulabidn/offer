import { TYPESENSE_URL } from 'astro:env/server';
import { api } from './api';

/** One published offer as stored in Typesense (see backend App\Domain\Search\OfferDocument). */
export interface OfferHit {
  id: string;
  product_id: number;
  product_slug: string;
  product_name: string;
  variant_id: number;
  variant_slug: string;
  variant_label: string;
  brand?: string;
  product_group: string;
  product_group_id: number;
  chain_id: number;
  chain_name: string;
  chain_slug: string;
  chain_grade: number;
  item_grade: number;
  brochure_id: number;
  price: number;
  regular_price?: number;
  /** Other stores' usual prices, JSON: [[chain id, price, "2026-10-12"], …]. */
  usual_prices?: string;
  effective_unit_price?: number;
  unit_label?: string;
  discount_pct?: number;
  promo_type: string;
  promo_label?: string;
  promo_qty?: number;
  promo_total?: number;
  promo_buy?: number;
  promo_get?: number;
  card_required?: boolean;
  valid_to: number;
  like_count: number;
  under_review?: boolean;
  deal_score?: number;
  is_lowest_90d?: boolean;
  was_price_suspicious?: boolean;
  /** Base URL of the product photo; append "-{width}.avif|webp". */
  image?: string;
  /** Emoji for the card when there's no photo. */
  icon?: string;
}

export interface Facet {
  field: string;
  values: { value: string; count: number }[];
}

export interface OfferSearch {
  q?: string;
  filters?: string[];
  sort?: SortKey;
  page?: number;
  perPage?: number;
  facets?: string[];
  /** One result per value of this field, the first by the sort order (the cheapest, sorting by rank). */
  groupBy?: string;
  /** Only these fields, to keep a large response small. */
  fields?: string;
}

export const SORTS = {
  rank: { label: 'Most useful', by: 'product_rank:desc,effective_unit_price:asc' },
  deal: { label: 'Biggest discount', by: 'discount_pct:desc' },
  unit: { label: 'Cheapest per unit', by: 'effective_unit_price:asc' },
  price: { label: 'Lowest price', by: 'price:asc' },
  ending: { label: 'Ending soon', by: 'valid_to:asc' },
  liked: { label: 'Most liked', by: 'like_count:desc' },
} as const;
export type SortKey = keyof typeof SORTS;

let config: { key: string; collection: string; at: number } | null = null;

/** The scoped key is valid for a day; refresh it hourly per Worker isolate. */
async function searchConfig() {
  if (!config || Date.now() - config.at > 3_600_000) {
    const fresh = await api.searchConfig();
    if (!fresh) throw new Error('Search is not configured');
    config = { key: fresh.key, collection: fresh.collection, at: Date.now() };
  }
  return config;
}

/** Typesense filter values need backticks when they contain spaces or commas. */
export const filterValue = (value: string) => '`' + value.replaceAll('`', '') + '`';

export async function searchOffers({ q = '', filters = [], sort = 'deal', page = 1, perPage = 24, facets = [], groupBy, fields }: OfferSearch) {
  const { key, collection } = await searchConfig();
  const params = new URLSearchParams({
    q: q.trim() || '*',
    query_by: 'product_name,product_name_ar,brand,product_group,variant_label,chain_name',
    // Same typo rules as the search box (lib/suggest.ts), so Enter finds what the suggestions showed.
    min_len_1typo: '3',
    min_len_2typo: '6',
    sort_by: SORTS[sort]?.by ?? SORTS.deal.by,
    page: String(page),
    per_page: String(perPage),
  });
  if (filters.length) params.set('filter_by', filters.join(' && '));
  if (facets.length) {
    params.set('facet_by', facets.join(','));
    params.set('max_facet_values', '20');
  }
  if (groupBy) {
    params.set('group_by', groupBy);
    params.set('group_limit', '1');
  }
  if (fields) params.set('include_fields', fields);

  // cf: the same query from many shoppers near each other is answered by the edge for a minute.
  const response = await fetch(`${TYPESENSE_URL}/collections/${collection}/documents/search?${params}`, {
    headers: { 'X-TYPESENSE-API-KEY': key },
    cf: { cacheEverything: true, cacheTtl: 60 },
  } as RequestInit);
  if (!response.ok) throw new Error(`Search failed with ${response.status}`);
  const body = (await response.json()) as {
    found: number;
    hits?: { document: OfferHit }[];
    grouped_hits?: { hits: { document: OfferHit }[] }[];
    facet_counts?: { field_name: string; counts: { value: string; count: number }[] }[];
  };

  return {
    found: body.found,
    page,
    pages: Math.max(1, Math.ceil(body.found / perPage)),
    hits: groupBy ? (body.grouped_hits ?? []).map((group) => group.hits[0].document) : (body.hits ?? []).map((hit) => hit.document),
    facets: (body.facet_counts ?? []).map((f) => ({ field: f.field_name, values: f.counts })) as Facet[],
  };
}
