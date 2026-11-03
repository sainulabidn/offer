export const aed = (value: number) => `AED ${value.toFixed(2)}`;

/** A product's page, or one pack's: /products/almarai-milk/2-l; with a place first, its prices there (/dubai/products/…). */
export const productPath = (productSlug: string, variantSlug?: string | null, place = '') =>
  `${place}/products/${productSlug}${variantSlug ? `/${encodeURIComponent(variantSlug)}` : ''}`;

/** The page an offer links to: its pack's, or the product's when the product has only one pack. */
export const offerPath = (offer: { product_slug: string; variant_slug: string; pack_count?: number }, place = '') =>
  productPath(offer.product_slug, offer.pack_count === 1 ? null : offer.variant_slug, place);

/** "Lulu Hypermarket" → "LH". */
export const initials = (name: string) =>
  name.split(/\s+/).slice(0, 2).map((word) => word.charAt(0)).join('').toUpperCase();

/** The same soft colour (.tint-0 … .tint-5 in global.css) every time for the same name. */
export function tintFor(name: string): string {
  let hash = 0;
  for (const char of name) hash = (hash * 31 + char.charCodeAt(0)) % 997;
  return `tint-${hash % 6}`;
}

/** What an offer is about, for counting and for the shopper's activity: "product:1 group:2 category:3". */
export const offerTargets = (offer: { product_id: number; product_group_id: number; brand_id?: number; category_ids?: number[] }) =>
  [`product:${offer.product_id}`, `group:${offer.product_group_id}`, ...(offer.brand_id ? [`brand:${offer.brand_id}`] : []), ...(offer.category_ids ?? []).map((id) => `category:${id}`)].join(' ');

/** The day and time in the UAE, which has one offset all year, wherever the shopper's device is set. */
export function marketNow() {
  const uae = new Date(Date.now() + 4 * 3_600_000);
  return { weekday: uae.getUTCDay(), time: uae.toISOString().slice(11, 16) };
}

/** "12 Oct" from "2026-10-12". */
export const shortDate = (day: string) => new Date(`${day}T00:00:00Z`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' });

/** A distance for shoppers: metres when close, one decimal up to 10 km, whole km after that. */
export function distanceLabel(km: number): string {
  if (km < 1) return `${Math.max(50, Math.round(km * 20) * 50)} m`;
  return km < 10 ? `${km.toFixed(1)} km` : `${Math.round(km)} km`;
}

/** A timed offer's countdown: "2h 15m left", "42m 10s left", "ended", from a unix timestamp. */
export function timeLeft(endsAt: number, now = Date.now()): string {
  const seconds = Math.floor((endsAt * 1000 - now) / 1000);
  if (seconds <= 0) return 'ended';
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = seconds % 60;
  return h > 0 ? `${h}h ${m}m left` : `${m}m ${String(s).padStart(2, '0')}s left`;
}

/** "ends today", "ends tomorrow", "ends in 4 days" from a unix timestamp (end of the last valid day). */
export function endsIn(validTo: number | string, now = new Date()): string {
  const end = typeof validTo === 'number' ? new Date(validTo * 1000) : new Date(`${validTo}T23:59:59+04:00`);
  const days = Math.floor((end.getTime() - now.getTime()) / 86_400_000);
  if (days <= 0) return 'ends today';
  if (days === 1) return 'ends tomorrow';
  return `ends in ${days} days`;
}

/**
 * Edge caching for pages that are the same for everyone with the same settings; publishing purges
 * them by tag. Browsers always re-check: a page they kept would hide a change of location.
 */
export function cacheFor(headers: Headers, seconds = 300) {
  headers.set('Cache-Control', `public, max-age=0, s-maxage=${seconds}, stale-while-revalidate=${seconds * 2}`);
}
