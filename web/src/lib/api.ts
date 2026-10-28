import { API_URL } from 'astro:env/server';

/** Reads from the Laravel /api/v1 read endpoints (cached at the edge; only cache misses reach the server). */
async function get<T>(path: string): Promise<T | null> {
  const response = await fetch(`${API_URL}/api/v1/${path}`, { headers: { Accept: 'application/json' } });
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
  valid_to: string;
}

export interface Product {
  id: number;
  slug: string;
  name: string;
  description: string | null;
  brand: { name: string; slug: string } | null;
  category: { id: number; name: string; slug: string };
  product_group: { id: number; name: string; slug: string; unit_label: string };
  like_count: number;
  images: { base: string; alt: string | null; width: number; height: number }[];
  variants: { id: number; slug: string; label: string; is_default: boolean; offers: StoreOffer[] }[];
}

export interface Chain {
  id: number;
  name: string;
  slug: string;
  format: string;
  website: string | null;
  branches: { id: number; name: string; slug: string; area: string | null; lat: number; lng: number; phone: string | null }[];
}

export const api = {
  categories: () => get<Category[]>('categories').then((c) => c ?? []),
  product: (slug: string) => get<Product>(`products/${encodeURIComponent(slug)}`),
  chain: (slug: string) => get<Chain>(`chains/${encodeURIComponent(slug)}`),
  sitemap: () => get<{ products: { slug: string; updated: string }[]; chains: string[]; categories: string[] }>('sitemap'),
  /** variant id → per-store series of offers in the last 120 days */
  history: (slug: string) => get<Record<string, { chain: string; points: { from: string; to: string; price: number; regular_price: number | null }[] }[]>>(`products/${encodeURIComponent(slug)}/history`).then((h) => h ?? {}),
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
