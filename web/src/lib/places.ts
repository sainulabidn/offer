import { PUBLIC_API_URL } from 'astro:env/client';
import { resolvePrefs, toPlaceName, type PlaceName, type PlaceRow } from '@offer/rank';
import { ApiError, call, isSignedIn } from './account';
import { clearPrefs, readPrefs, reloadWithPrefs, writePrefs, type SavedPrefs } from './prefs';

/**
 * Saved places such as Home and Office. They live in the browser, as plans do, and reach the account
 * when signed in. The active place is in the cookie, so a page can be drawn for it without asking us.
 */
export interface Place {
  /** Made on the device, so a guest's place keeps it at sign-in. */
  id: string;
  label: string;
  kind: 'home' | 'work' | 'other';
  lat: number;
  lng: number;
  area: string | null;
  radius_km: number | null;
  is_default: boolean;
  is_auto: boolean;
  updated_at: string;
}

const KEY = 'offer:places';
const PREFS_AT = 'offer:prefs-at';
const SYNCED = 'offer:account-synced';
export const MAX_GUEST_PLACES = 5;

const read = <T,>(key: string, fallback: T, storage: () => Storage = () => localStorage): T => {
  try {
    return (JSON.parse(storage().getItem(key) ?? 'null') as T) ?? fallback;
  } catch {
    return fallback;
  }
};

function write(key: string, value: unknown, storage: () => Storage = () => localStorage) {
  try {
    storage().setItem(key, JSON.stringify(value));
  } catch {
    // Storage blocked: this lasts until the tab closes.
  }
}

export const loadPlaces = (): Place[] => read<Place[]>(KEY, []);

export const newPlaceId = () => crypto.randomUUID();

function withDefault(places: Place[], chosen: Place): Place[] {
  return places.map((place) => (chosen.is_default && place.id !== chosen.id ? { ...place, is_default: false, is_auto: false } : place));
}

export async function savePlace(place: Place): Promise<void> {
  const saved = { ...place, updated_at: new Date().toISOString() };
  write(KEY, withDefault([...loadPlaces().filter((p) => p.id !== place.id), saved], saved));
  if (isSignedIn()) await call('PUT', `me/places/${place.id}`, saved).catch(() => null);
}

export async function removePlace(id: string): Promise<void> {
  write(KEY, loadPlaces().filter((place) => place.id !== id));
  if (readPrefs().place === id) writePrefs({ place: null });
  if (isSignedIn()) await call('DELETE', `me/places/${id}`).catch(() => null);
}

/** Pages are drawn from the cookie, so choosing a place reloads with it. */
export function usePlace(place: Place) {
  savePrefs({ at: { lat: place.lat, lng: place.lng }, label: place.label, place: place.id, radiusKm: place.radius_km });
  reloadWithPrefs();
}

let placeNames: Promise<PlaceName[]> | null = null;

/** The UAE place list for instant search; fetched once, when the location panel first opens. */
export const loadPlaceNames = () =>
  (placeNames ??= fetch(`${PUBLIC_API_URL}/api/v1/places`)
    .then((r) => (r.ok ? (r.json() as Promise<PlaceRow[]>) : Promise.reject(new Error(`Places failed with ${r.status}`))))
    .then((rows) => rows.map(toPlaceName))
    .catch(() => {
      placeNames = null; // try again next time the panel opens
      return [];
    }));

interface ServerPrefs {
  radius_km: number | null;
  item_radius_km: number | null;
  item_radius_off: boolean;
  branch_ids: number[];
  updated_at: string | null;
}

const pushable = (prefs: SavedPrefs) => ({
  radius_km: prefs.radiusKm,
  item_radius_km: prefs.itemRadiusKm,
  item_radius_off: prefs.allUae,
  branch_ids: prefs.stores,
  updated_at: read<string | null>(PREFS_AT, null) ?? new Date().toISOString(),
});

/** Saves changed settings to the cookie and, when signed in, to the account. */
export function savePrefs(changes: Partial<SavedPrefs>) {
  writePrefs(changes);
  write(PREFS_AT, new Date().toISOString());
  if (isSignedIn()) call('PUT', 'me/preferences', pushable(readPrefs())).catch(() => null);
}

/** Store chips and the distance chip are plain links, so they work without JavaScript; this remembers what they chose. */
export function keepUrlChoices() {
  const url = new URL(location.href);
  const choice = resolvePrefs({ url });
  const saved = readPrefs();
  if (url.searchParams.has('stores') && choice.stores.join() !== saved.stores.join()) savePrefs({ stores: choice.stores });
  if (url.searchParams.has('items') && (choice.allUae !== saved.allUae || choice.itemRadiusKm !== saved.itemRadiusKm)) {
    savePrefs({ allUae: choice.allUae, itemRadiusKm: choice.itemRadiusKm });
  }
}

/**
 * The account is the master copy. Runs right after sign-in, and once per tab for shoppers already
 * signed in, so a change made on another device (or by staff) arrives. Newest wins. If that changes
 * this page, it reloads at most once per tab. Right after sign-in the page is about to change anyway,
 * so nothing reloads.
 */
export async function syncAccount({ afterSignIn = false } = {}) {
  if (!isSignedIn() || (!afterSignIn && read(SYNCED, false, () => sessionStorage))) return;
  write(SYNCED, true, () => sessionStorage);

  try {
    const [server, places] = await Promise.all([call<ServerPrefs>('GET', 'me/preferences'), call<Place[]>('GET', 'me/places')]);

    // Places made as a guest move up; the account's replace this device's copies.
    const onServer = new Set(places.map((place) => place.id));
    const onlyHere = loadPlaces().filter((place) => !onServer.has(place.id));
    await Promise.all(onlyHere.map((place) => call('PUT', `me/places/${place.id}`, place).catch(() => null)));
    write(KEY, [...places, ...onlyHere]);

    const local = readPrefs();
    const localAt = read<string | null>(PREFS_AT, null);
    let changed = false;

    if (server.updated_at && (!localAt || server.updated_at > localAt)) {
      const next = { radiusKm: server.radius_km, itemRadiusKm: server.item_radius_km, allUae: server.item_radius_off, stores: server.branch_ids };
      changed = next.radiusKm !== local.radiusKm || next.itemRadiusKm !== local.itemRadiusKm || next.allUae !== local.allUae || next.stores.join() !== local.stores.join();
      writePrefs(next);
      write(PREFS_AT, server.updated_at);
    } else if (localAt) {
      await call('PUT', 'me/preferences', pushable(local)).catch((e) => {
        if (!(e instanceof ApiError) || e.status !== 409) throw e;
      });
    }

    // A new device starts at the account's default place.
    const home = places.find((place) => place.is_default);
    if (home && !readPrefs().at) {
      writePrefs({ at: { lat: home.lat, lng: home.lng }, label: home.label, place: home.id, radiusKm: home.radius_km ?? readPrefs().radiusKm });
      changed = true;
    }

    if (changed && !afterSignIn) reloadWithPrefs();
  } catch {
    // Offline or server trouble: this device keeps working, and the next tab tries again.
  }
}

/** Signing out on a shared device: the next person shouldn't see where this shopper lives or works. */
export function forgetThisDevice() {
  clearPrefs();
  try {
    localStorage.removeItem(KEY);
    localStorage.removeItem(PREFS_AT);
    sessionStorage.removeItem(SYNCED);
  } catch {
    // Storage blocked: there was nothing kept.
  }
}
