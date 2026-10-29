import { searchConfig } from './plan';

/**
 * Search-as-you-type against Typesense: one request per (debounced) keystroke returns the top
 * products, one per product with its cheapest offer, plus the brands, stores and product types
 * among all matches. Typesense tolerates up to 2 typos per word, so "basmathi" finds basmati.
 */
export interface ProductSuggestion {
  name: string;
  highlighted: string;
  href: string;
  variant: string;
  chain: string;
  price: number;
  unitPrice?: number;
  unitLabel?: string;
  image?: string;
  stores: number;
}

export interface Suggestions {
  query: string;
  products: ProductSuggestion[];
  brands: string[];
  stores: string[];
  groups: string[];
  found: number;
}

const cache = new Map<string, Suggestions>();

export async function suggest(query: string, signal?: AbortSignal): Promise<Suggestions> {
  const q = query.trim();
  const cached = cache.get(q.toLowerCase());
  if (cached) return cached;

  const { url, collection, key } = await searchConfig();
  const params = new URLSearchParams({
    q,
    query_by: 'product_name,brand,product_group,chain_name,variant_label',
    query_by_weights: '5,4,3,2,1',
    prefix: 'true,true,true,true,false', // "basm" already finds basmati
    num_typos: '2,2,2,1,0',
    min_len_1typo: '3', // short grocery words: "rce" → rice, "egs" → eggs
    min_len_2typo: '6',
    typo_tokens_threshold: '1',
    drop_tokens_threshold: '1',
    group_by: 'product_id',
    group_limit: '1',
    sort_by: '_text_match:desc,effective_unit_price:asc',
    per_page: '6',
    facet_by: 'brand,chain_name,product_group',
    max_facet_values: '4',
    highlight_fields: 'product_name',
    include_fields: 'product_name,product_slug,variant_slug,variant_label,brand,chain_name,price,effective_unit_price,unit_label,image',
  });

  const response = await fetch(`${url}/collections/${collection}/documents/search?${params}`, {
    headers: { 'X-TYPESENSE-API-KEY': key },
    signal,
  });
  if (!response.ok) throw new Error(`Suggest failed (${response.status})`);
  const body = (await response.json()) as {
    found: number;
    grouped_hits?: { found: number; hits: { document: Record<string, any>; highlight?: { product_name?: { matched_tokens?: string[] } } }[] }[];
    facet_counts?: { field_name: string; counts: { value: string }[] }[];
  };

  const facet = (name: string) => body.facet_counts?.find((f) => f.field_name === name)?.counts.map((c) => c.value) ?? [];
  const result: Suggestions = {
    query: q,
    found: body.found,
    products: (body.grouped_hits ?? []).map((group) => {
      const { document: d, highlight } = group.hits[0];
      return {
        name: d.product_name,
        highlighted: highlightTokens(d.product_name, highlight?.product_name?.matched_tokens ?? []),
        href: `/products/${d.product_slug}?variant=${encodeURIComponent(d.variant_slug)}`,
        variant: d.variant_label,
        chain: d.chain_name,
        price: d.price,
        unitPrice: d.effective_unit_price,
        unitLabel: d.unit_label,
        image: d.image,
        stores: group.found,
      };
    }),
    brands: facet('brand'),
    stores: facet('chain_name'),
    groups: facet('product_group'),
  };

  cache.set(q.toLowerCase(), result);
  return result;
}

/** Escapes the name, then wraps the words Typesense matched (including typo matches) in <mark>. */
export function highlightTokens(text: string, tokens: string[]): string {
  const safe = escapeHtml(text);
  const words = tokens.map((t) => escapeHtml(t).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).filter(Boolean);
  return words.length ? safe.replace(new RegExp(`(${words.join('|')})`, 'gi'), '<mark>$1</mark>') : safe;
}

export function escapeHtml(text: string): string {
  return text.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);
}

const RECENT = 'offer:recent-searches';

export function recentSearches(): string[] {
  try {
    return JSON.parse(localStorage.getItem(RECENT) ?? '[]');
  } catch {
    return [];
  }
}

export function rememberSearch(query: string) {
  const q = query.trim();
  if (!q) return;
  try {
    localStorage.setItem(RECENT, JSON.stringify([q, ...recentSearches().filter((r) => r.toLowerCase() !== q.toLowerCase())].slice(0, 6)));
  } catch {
    // Storage blocked: no recent searches.
  }
}

export function clearRecentSearches() {
  try {
    localStorage.removeItem(RECENT);
  } catch {}
}
