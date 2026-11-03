import { API_URL } from 'astro:env/server';
import { EMIRATE_CODES, placeName, type Region } from './locations';

// Location helpers live in ./locations (the browser uses them too); server code may import them from here.
export * from './locations';

/**
 * Search engine and social data for pages: the site-wide settings and the SEO pages staff make in the admin
 * (backend App\Domain\Seo). A page under /deals/ is a landing page built from its saved filters; any other
 * path replaces what that page writes by itself.
 */
export interface Translated {
  en?: string;
  ar?: string;
}

export interface LandingFilters {
  q?: string;
  category_id?: number;
  product_group_id?: number;
  brand_ids?: number[];
  chain_ids?: number[];
  region_id?: number;
  min_discount?: number;
  card_only?: boolean;
  sort?: string;
}

export interface SeoPage {
  path: string;
  title: Translated | null;
  description: Translated | null;
  heading: Translated | null;
  intro: Translated | null;
  image: string | null;
  /** Landing pages only. */
  filters: LandingFilters | null;
  noindex: boolean;
  updated: string | null;
}

export interface SeoSettings {
  site_name: string;
  tagline: string;
  description: string;
  share_image: string | null;
  x_handle: string | null;
  facebook_app_id: string | null;
  social_profiles: string[];
  google_verification: string | null;
  bing_verification: string | null;
  yandex_verification: string | null;
  facebook_verification: string | null;
  pinterest_verification: string | null;
}

const DEFAULT_SETTINGS: SeoSettings = {
  site_name: 'offer',
  tagline: 'Supermarket deals near you',
  description: 'Compare supermarket offers across the UAE by true unit price.',
  share_image: null,
  x_handle: null,
  facebook_app_id: null,
  social_profiles: [],
  google_verification: null,
  bing_verification: null,
  yandex_verification: null,
  facebook_verification: null,
  pinterest_verification: null,
};

/** Fewer live offers than this on a location × category or location × brand page: too thin to list in search engines. */
export const MIN_INDEXABLE_OFFERS = 3;

let cached: { at: number; settings: SeoSettings; pages: Map<string, SeoPage> } | null = null;

/** Every five minutes per Worker isolate. If the API is down, pages keep their own texts. */
export async function seoData() {
  if (!cached || Date.now() - cached.at > 300_000) {
    try {
      const response = await fetch(`${API_URL}/api/v1/seo`, { headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error(`SEO failed with ${response.status}`);
      const body = (await response.json()) as { settings: SeoSettings; pages: SeoPage[] };
      cached = { at: Date.now(), settings: { ...DEFAULT_SETTINGS, ...body.settings }, pages: new Map(body.pages.map((page) => [page.path, page])) };
    } catch (error) {
      console.warn('SEO settings unavailable; pages use their own texts.', error);
      return cached ?? { at: 0, settings: DEFAULT_SETTINGS, pages: new Map<string, SeoPage>() };
    }
  }
  return cached;
}

/** "/Dubai/" → "/dubai", as the admin saves paths. */
export const cleanPath = (path: string) => `/${path.toLowerCase().replace(/^\/+|\/+$/g, '')}`;

export const seoPage = async (path: string) => (await seoData()).pages.get(cleanPath(path));

/** The site is in English for now; Arabic texts are kept for when it isn't. */
export const en = (text: Translated | null | undefined) => text?.en?.trim() || undefined;

// Query parameters that don't change what a page shows. Anything else (filters, sort) makes a variant
// of the page that search engines shouldn't list on its own.
const NEUTRAL_PARAMS = /^(page|utm_[a-z]+|gclid|fbclid|msclkid|ref)$/;

export const isFilteredUrl = (url: URL) => [...url.searchParams.keys()].some((key) => !NEUTRAL_PARAMS.test(key));

/** The address search engines should index: the path, plus the page number past page 1. */
export function canonicalUrl(url: URL, origin: string, path = url.pathname): string {
  const canonical = new URL(path, origin);
  const page = Number(url.searchParams.get('page'));
  if (page > 1) canonical.searchParams.set('page', String(page));
  return canonical.href;
}

export const absoluteUrl = (value: string, origin: string) => new URL(value, origin).href;

/** Short text for a meta description: whole words, about 160 characters. */
export function clip(text: string, max = 160): string {
  const flat = text.replace(/\s+/g, ' ').trim();
  if (flat.length <= max) return flat;
  return `${flat.slice(0, max - 1).replace(/\s+\S*$/, '')}…`;
}

/* Share pictures -------------------------------------------------------------------------------------- */

/** A short fingerprint of what a picture shows; social sites cache by address, so a new price gets a new one. */
function version(parts: unknown[]): string {
  let hash = 2166136261;
  for (const char of JSON.stringify(parts)) hash = Math.imul(hash ^ char.charCodeAt(0), 16777619) >>> 0;
  return hash.toString(36);
}

const SHARE_SIZE = { width: 1200, height: 630 };

/** The picture for a product page: its photo, cheapest price and store (backend ShareImages). */
export function productShareImage(product: { slug: string; name: string }, pack: string | null, region: { slug: string } | null, shown: unknown[]) {
  const params = new URLSearchParams({ ...(pack && { pack }), ...(region && { region: region.slug }), v: version(shown) });
  return { url: `/og/product/${product.slug}.jpg?${params}`, alt: product.name, ...SHARE_SIZE };
}

/** The picture for a list of offers: its place, category or brand, count and best discount. */
export function listShareImage(scope: { region?: { slug: string } | null; category?: { slug: string } | null; brand?: { slug: string } | null; deal?: string | null }, alt: string, shown: unknown[]) {
  const params = new URLSearchParams({
    ...(scope.region && { region: scope.region.slug }),
    ...(scope.category && { category: scope.category.slug }),
    ...(scope.brand && { brand: scope.brand.slug }),
    ...(scope.deal && { deal: scope.deal }),
    v: version(shown),
  });
  return { url: `/og/list.jpg?${params}`, alt, ...SHARE_SIZE };
}

/** JSON for a script tag: "<" can't end it early. */
export const jsonForScript = (value: unknown) => JSON.stringify(value).replaceAll('<', '\\u003c');

export interface Crumb {
  name: string;
  path: string;
}

export function breadcrumbList(crumbs: Crumb[], origin: string) {
  return {
    '@type': 'BreadcrumbList',
    itemListElement: crumbs.map((crumb, index) => ({ '@type': 'ListItem', position: index + 1, name: crumb.name, item: absoluteUrl(crumb.path, origin) })),
  };
}

/** schema.org Place for a location page: the area inside its emirate, inside the UAE. */
export function placeSchema(emirate: Region, area: Region | null, origin: string) {
  const country = { '@type': 'Country', name: 'United Arab Emirates', identifier: 'AE' };
  const emirateNode = { '@type': 'AdministrativeArea', name: emirate.name, identifier: EMIRATE_CODES[emirate.slug], url: absoluteUrl(`/${emirate.slug}`, origin), containedInPlace: country };
  if (!area) return { ...emirateNode, geo: { '@type': 'GeoCoordinates', latitude: emirate.lat, longitude: emirate.lng } };
  return {
    '@type': 'Place',
    name: area.name,
    url: absoluteUrl(`/${emirate.slug}/${area.urlSlug}`, origin),
    geo: { '@type': 'GeoCoordinates', latitude: area.lat, longitude: area.lng },
    containedInPlace: emirateNode,
  };
}

/** Geo meta tags for a location page. */
export const geoMeta = (emirate: Region, area: Region | null) => ({
  region: EMIRATE_CODES[emirate.slug] ?? 'AE',
  place: placeName(emirate, area),
  lat: (area ?? emirate).lat,
  lng: (area ?? emirate).lng,
});

