export const aed = (value: number) => `AED ${value.toFixed(2)}`;

/** "ends today", "ends tomorrow", "ends in 4 days" from a unix timestamp (end of the last valid day). */
export function endsIn(validTo: number | string, now = new Date()): string {
  const end = typeof validTo === 'number' ? new Date(validTo * 1000) : new Date(`${validTo}T23:59:59+04:00`);
  const days = Math.floor((end.getTime() - now.getTime()) / 86_400_000);
  if (days <= 0) return 'ends today';
  if (days === 1) return 'ends tomorrow';
  return `ends in ${days} days`;
}

/** Edge caching for pages that are the same for every visitor; publishing purges them by tag. */
export function cacheFor(headers: Headers, seconds = 300) {
  headers.set('Cache-Control', `public, max-age=60, s-maxage=${seconds}, stale-while-revalidate=${seconds * 2}`);
}
