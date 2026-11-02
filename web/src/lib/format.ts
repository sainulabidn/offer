export const aed = (value: number) => `AED ${value.toFixed(2)}`;

/** "Lulu Hypermarket" → "LH". */
export const initials = (name: string) =>
  name.split(/\s+/).slice(0, 2).map((word) => word.charAt(0)).join('').toUpperCase();

/** The same soft colour (.tint-0 … .tint-5 in global.css) every time for the same name. */
export function tintFor(name: string): string {
  let hash = 0;
  for (const char of name) hash = (hash * 31 + char.charCodeAt(0)) % 997;
  return `tint-${hash % 6}`;
}

/** "12 Oct" from "2026-10-12". */
export const shortDate = (day: string) => new Date(`${day}T00:00:00Z`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' });

/** A distance for shoppers: metres when close, one decimal up to 10 km, whole km after that. */
export function distanceLabel(km: number): string {
  if (km < 1) return `${Math.max(50, Math.round(km * 20) * 50)} m`;
  return km < 10 ? `${km.toFixed(1)} km` : `${Math.round(km)} km`;
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
