import type { Point } from './index';

/**
 * What a page is drawn for: where, how far and which stores. Worked out once per request from the
 * URL, the shopper's cookie and, on a first visit, their emirate. The edge cache key is made from
 * this same object, so a cached page can never show a different shopper's area.
 *
 * The cookie also holds a label such as "Home". Only the browser reads it: free text from a cookie
 * must never reach HTML that other shoppers are served.
 */
export interface Prefs {
  /** Rounded to about 1 km, so nearby shoppers share cached pages. */
  at: Point | null;
  /** Rough emirate from the network on a first visit. Used for this page only, never stored. */
  emirate: string | null;
  radiusKm: number | null;
  itemRadiusKm: number | null;
  /** The shopper removed the item radius: show all UAE. */
  allUae: boolean;
  /** Selected branch ids, sorted. */
  stores: number[];
}

export const PREFS_COOKIE = 'offer_prefs';
export const MAX_STORES = 4;

const MAX_RADIUS_KM = 500; // a sanity limit; the site's own limit is applied when drawing

export const NO_PREFS: Prefs = { at: null, emirate: null, radiusKm: null, itemRadiusKm: null, allUae: false, stores: [] };

/** Cloudflare's region codes for the emirates → their region slugs. */
const EMIRATES: Record<string, string> = {
  DU: 'dubai',
  AZ: 'abu-dhabi',
  SH: 'sharjah',
  AJ: 'ajman',
  UQ: 'umm-al-quwain',
  RK: 'ras-al-khaimah',
  FU: 'fujairah',
};

const round = (n: number, decimals = 2) => Number(n.toFixed(decimals));

function point(value: string | null | undefined): Point | null {
  const [lat, lng, extra] = (value ?? '').split(',').map((part) => (part.trim() === '' ? NaN : Number(part)));
  if (extra !== undefined || !Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) return null;
  return { lat: round(lat), lng: round(lng) };
}

function radius(value: string | null | undefined): number | null {
  const km = Math.round(Number(value));
  return value && Number.isFinite(km) && km >= 1 && km <= MAX_RADIUS_KM ? km : null;
}

function storeIds(value: string | null | undefined): number[] {
  const ids = (value ?? '').split(/[.,]/).map(Number).filter((id) => Number.isInteger(id) && id > 0);
  return [...new Set(ids)].slice(0, MAX_STORES).sort((a, b) => a - b);
}

function parseCookie(cookie: string | undefined): URLSearchParams {
  try {
    return new URLSearchParams(cookie ?? '');
  } catch {
    return new URLSearchParams();
  }
}

/** The URL wins field by field, so a shared link shows what its sender saw. Never throws. */
export function resolvePrefs({ url, cookie, regionCode }: { url: URL; cookie?: string; regionCode?: string }): Prefs {
  const saved = parseCookie(cookie);
  const q = url.searchParams;

  const at = point(q.get('near')) ?? point(saved.get('at'));
  const items = q.get('items');

  return {
    at,
    emirate: at ? null : (EMIRATES[regionCode ?? ''] ?? null),
    radiusKm: radius(q.get('r')) ?? radius(saved.get('r')),
    itemRadiusKm: radius(items) ?? radius(saved.get('i')),
    allUae: items === null ? saved.get('all') === '1' : items === 'all',
    // has(): an empty ?stores= means "none selected", not "use the saved ones".
    stores: storeIds(q.has('stores') ? q.get('stores') : saved.get('s')),
  };
}

/** The part of the edge cache key that depends on the shopper. */
export function prefsKey(prefs: Prefs): string {
  const at = prefs.at ? `${prefs.at.lat},${prefs.at.lng}` : (prefs.emirate ?? '');
  return [at, prefs.radiusKm ?? '', prefs.itemRadiusKm ?? '', prefs.allUae ? 1 : 0, prefs.stores.join('.')].join('|');
}

/** Everything the browser keeps in the cookie. `label` and `place` are for the browser alone. */
export function serializePrefs(prefs: Omit<Prefs, 'emirate'> & { label?: string; place?: string }): string {
  const out = new URLSearchParams();
  if (prefs.at) out.set('at', `${round(prefs.at.lat)},${round(prefs.at.lng)}`);
  if (prefs.radiusKm) out.set('r', String(prefs.radiusKm));
  if (prefs.itemRadiusKm) out.set('i', String(prefs.itemRadiusKm));
  if (prefs.allUae) out.set('all', '1');
  if (prefs.stores.length) out.set('s', prefs.stores.join('.'));
  if (prefs.label) out.set('l', prefs.label);
  if (prefs.place) out.set('p', prefs.place);
  return out.toString();
}

/** The label and saved place id the browser put in the cookie. */
export function cookieLabel(cookie: string | undefined): { label: string | null; place: string | null } {
  const saved = parseCookie(cookie);
  return { label: saved.get('l'), place: saved.get('p') };
}
