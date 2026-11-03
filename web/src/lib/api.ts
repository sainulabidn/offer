import { API_URL } from 'astro:env/server';
import { env } from 'cloudflare:workers';

/**
 * An /api/v1 address for the Worker's own reads. Cloudflare caches these responses, and a copy from before
 * a deploy can lack what the new code reads (a new field, a new shape); each Worker deploy has its own
 * version id, so after a deploy every read starts fresh. Laravel ignores the extra parameter.
 */
export function apiUrl(path: string): string {
  const url = new URL(`${API_URL}/api/v1/${path}`);
  url.searchParams.set('__build', env.CF_VERSION_METADATA?.id ?? 'dev');
  return url.toString();
}

/** Reads from the Laravel /api/v1 read endpoints (cached at the edge; only cache misses reach the server). */
async function get<T>(path: string): Promise<T | null> {
  const response = await fetch(apiUrl(path), { headers: { Accept: 'application/json' } });
  if (response.status === 404) return null;
  if (!response.ok) throw new Error(`API ${path} failed with ${response.status}`);
  return (await response.json()) as T;
}

export interface Category {
  id: number;
  name: string;
  slug: string;
  icon: string | null;
  children: Category[];
}

export interface StoreOffer {
  id: number;
  brochure_id: number;
  chain: { id: number; name: string; slug: string };
  price: number;
  regular_price: number | null;
  effective_unit_price: number | null;
  unit_label: string;
  discount_pct: number | null;
  deal_score: number | null;
  is_lowest_90d: boolean;
  was_price_suspicious: boolean;
  promo_type: string;
  promo_label: string | null;
  /** "Max 3 kg per customer", as the brochure words it. */
  condition_label: string | null;
  /** Only the store's own employees get this price. */
  staff_only: boolean;
  /** Timed offers: the daily window, "8:00 AM – 11:00 AM", and when today's closes (unix seconds). */
  time_window: string | null;
  window_ends_at: number | null;
  valid_to: string;
}

export interface Product {
  id: number;
  slug: string;
  name: string;
  description: string | null;
  brand: { id: number; name: string; slug: string } | null;
  category: { id: number; name: string; slug: string };
  product_group: { id: number; name: string; slug: string; unit_label: string };
  icon: string | null;
  like_count: number;
  images: { base: string; alt: string | null; width: number; height: number; credit: string | null }[];
  variants: { id: number; slug: string; label: string; is_default: boolean; gtin: string | null; offers: StoreOffer[] }[];
}

export interface Brand {
  id: number;
  name: string;
  name_ar: string | null;
  slug: string;
  logo: string | null;
  manufacturer: string | null;
  private_label: boolean;
}

export interface Sitemap {
  /** variants: empty when the product has one pack (no pack pages). */
  products: { id: number; slug: string; updated: string; variants: { id: number; slug: string }[]; image: string | null }[];
  chains: string[];
  categories: string[];
  /** "chain-slug/branch-slug" */
  branches: string[];
}

export interface Chain {
  id: number;
  name: string;
  slug: string;
  format: string;
  logo: string | null;
  website: string | null;
  online_shop: string | null;
  phone: string | null;
  /** The store's own page with its brochures. */
  offers_page: string | null;
  brochure_frequency: 'weekly' | 'fortnightly' | 'monthly' | 'irregular' | null;
  /** Emirates with a branch, in the usual order. */
  emirates: string[];
  /** When staff last checked these details, if they did. */
  verified_on: string | null;
  group: string | null;
  branches: { id: number; name: string; slug: string; area: string | null; address: string | null; lat: number; lng: number; phone: string | null; hours: { weekday: number; opens: string; closes: string }[] }[];
}

export const api = {
  categories: () => get<Category[]>('categories').then((c) => c ?? []),
  product: (slug: string) => get<Product>(`products/${encodeURIComponent(slug)}`),
  chain: (slug: string) => get<Chain>(`chains/${encodeURIComponent(slug)}`),
  sitemap: () => get<Sitemap>('sitemap'),
  brands: () => get<Brand[]>('brands').then((b) => b ?? []),
  /** variant id → per-store series of offers in the last 120 days */
  history: (slug: string) => get<Record<string, { chain: string; points: { from: string; to: string; price: number; regular_price: number | null }[] }[]>>(`products/${encodeURIComponent(slug)}/history`).then((h) => h ?? {}),
  /** Product ids rising in this area, fastest first. */
  trending: (region: number | null) => get<number[]>(`trending${region ? `?region=${region}` : ''}`).then((ids) => ids ?? []),
  searchConfig: () => get<{ url: string; collection: string; key: string }>('search-config'),
};

/** Flatten the tree so a slug can be looked up with its ancestors. */
export function findCategory(tree: Category[], slug: string): Category | undefined {
  for (const category of tree) {
    if (category.slug === slug) return category;
    const found = findCategory(category.children, slug);
    if (found) return found;
  }
}

/** The categories from the top down to this one, for breadcrumbs. */
export function categoryTrail(tree: Category[], match: (category: Category) => boolean): Category[] {
  for (const category of tree) {
    if (match(category)) return [category];
    const below = categoryTrail(category.children, match);
    if (below.length) return [category, ...below];
  }
  return [];
}

/** Brands change rarely; one copy per Worker isolate for 10 minutes. */
let brandList: { at: number; brands: Promise<Brand[]> } | null = null;
export function brands(): Promise<Brand[]> {
  if (!brandList || Date.now() - brandList.at > 600_000) {
    brandList = {
      at: Date.now(),
      brands: api.brands().catch((error) => {
        brandList = null; // try again on the next page
        throw error;
      }),
    };
  }
  return brandList.brands;
}
