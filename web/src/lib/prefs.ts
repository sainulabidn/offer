import { PREFS_COOKIE, cookieLabel, resolvePrefs, serializePrefs, type Prefs } from '@offer/rank';
import { locationPathFor } from './locations';
import { loadDirectory } from './plan';

/**
 * The shopper's saved settings, in the browser. One cookie for everyone, because the edge needs it
 * to draw the right page. Only the browser writes it, so no response carries Set-Cookie and pages
 * stay cacheable.
 */
export interface SavedPrefs extends Prefs {
  label: string | null;
  place: string | null;
}

const YEAR = 31_536_000;

function cookieValue(): string | undefined {
  const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${PREFS_COOKIE}=([^;]*)`));
  if (!match) return undefined;
  try {
    return decodeURIComponent(match[1]);
  } catch {
    return undefined;
  }
}

/** What is saved, whatever this page's URL says. */
export function readPrefs(): SavedPrefs {
  const cookie = cookieValue();
  return { ...resolvePrefs({ url: new URL(location.origin), cookie }), ...cookieLabel(cookie) };
}

export function writePrefs(changes: Partial<SavedPrefs>) {
  const next = { ...readPrefs(), ...changes };
  const value = encodeURIComponent(serializePrefs({ ...next, label: next.label ?? undefined, place: next.place ?? undefined }));
  document.cookie = `${PREFS_COOKIE}=${value}; Path=/; Max-Age=${YEAR}; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}`;

  // The shopping plan and alerts read this.
  try {
    if (next.at) localStorage.setItem('offer:near', JSON.stringify({ ...next.at, place: next.label ?? 'your location' }));
    else localStorage.removeItem('offer:near');
  } catch {
    // Storage blocked: those features ask for the location themselves.
  }
}

export function clearPrefs() {
  document.cookie = `${PREFS_COOKIE}=; Path=/; Max-Age=0; SameSite=Lax`;
  try {
    localStorage.removeItem('offer:near');
  } catch {
    // Storage blocked: nothing was kept there.
  }
}

/**
 * Reloads without the URL settings that the saved ones just replaced. A location page (/dubai/c/dairy-eggs)
 * names its place in the URL, so there the shopper moves to the page for their new place instead
 * (/sharjah/al-majaz/c/dairy-eggs), or to the all-UAE page when they cleared their place.
 */
export function reloadWithPrefs() {
  const url = new URL(location.href);
  ['near', 'r', 'place', 'page', 'items'].forEach((key) => url.searchParams.delete(key));

  const scope = document.querySelector<HTMLElement>('[data-location-scope]')?.dataset.locationScope;
  if (!scope) {
    location.href = url.toString();
    return;
  }
  const { rest } = JSON.parse(scope) as { rest: string };
  const { at } = readPrefs();
  (at ? loadDirectory().then((dir) => locationPathFor(at, rest, dir)) : Promise.resolve(rest || '/'))
    .catch(() => url.pathname)
    .then((path) => {
      url.pathname = path;
      location.href = url.toString();
    });
}
