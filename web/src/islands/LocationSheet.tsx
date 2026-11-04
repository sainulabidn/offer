import { Suspense, lazy, useEffect, useMemo, useRef, useState } from 'react';
import { Briefcase, Globe, Home as HomeIcon, LocateFixed, Map as MapIcon, MapPin, Pencil, Search, Star, Trash2, X } from 'lucide-react';
import { nearest, searchPlaces, type PlaceName, type Point } from '@offer/rank';
import { isSignedIn } from '../lib/account';
import { loadDirectory } from '../lib/plan';
import { MAX_GUEST_PLACES, applyAutoDefault, keepUrlChoices, loadPlaceNames, loadPlaces, newPlaceId, noteSpotUse, removePlace, savePlace, savePrefs, syncAccount, usePlace, type Place } from '../lib/places';
import { readPrefs, reloadWithPrefs } from '../lib/prefs';
import type { PickedSpot } from './MapPicker';
import RadiusPicker from './RadiusPicker';

// The map library is large, so it loads only when someone opens the map.
const MapPicker = lazy(() => import('./MapPicker'));

const DUBAI = { lat: 25.2048, lng: 55.2708 };
const MAX_ACCOUNT_PLACES = 10;

const ICONS = { home: HomeIcon, work: Briefcase, other: MapPin };

/** True after the first call in this tab, so the sheet asks once per visit. Storage blocked: never ask, rather than on every page. */
function askedThisVisit(): boolean {
  try {
    if (sessionStorage.getItem('offer:location-asked')) return true;
    sessionStorage.setItem('offer:location-asked', '1');
    return false;
  } catch {
    return true;
  }
}

/** Where the shopper is shopping: saved places, search, GPS (only after a tap), the map, and the radius. */
export default function LocationSheet() {
  const dialog = useRef<HTMLDialogElement>(null);
  const [places, setPlaces] = useState<Place[]>([]);
  const [names, setNames] = useState<PlaceName[]>([]);
  const [stores, setStores] = useState<Point[]>([]);
  const [settings, setSettings] = useState({ options: [1, 2, 5, 10, 25, 50], fallback: 10, max: 100 });
  const [radius, setRadius] = useState<number | null>(null);
  const [activePlace, setActivePlace] = useState<string | null>(null);
  const [query, setQuery] = useState('');
  const [status, setStatus] = useState('');
  const [map, setMap] = useState<{ start: Point; editing?: Place } | null>(null);

  useEffect(() => {
    keepUrlChoices();
    const settled = syncAccount().then(applyAutoDefault).catch(() => {});

    const show = () => {
      const saved = readPrefs();
      setRadius(saved.radiusKm);
      setActivePlace(saved.place);
      setPlaces(loadPlaces());
      dialog.current?.showModal();
      loadPlaceNames().then(setNames);
      loadDirectory()
        .then((dir) => {
          setStores(dir.branches);
          setSettings({ options: dir.settings.radius_options, fallback: dir.settings.radius_default, max: dir.settings.radius_max });
        })
        .catch(() => setStatus('Could not load the store list. Check your connection.'));
    };
    const open = (e: MouseEvent) => {
      if ((e.target as Element).closest('[data-open-location]')) show();
    };
    document.addEventListener('click', open);

    // A page that needs a place (the home page) asks for one, once per visit: closing the sheet means "not now".
    // After the account sync and the automatic default, either of which may set a place.
    settled.then(() => {
      const saved = readPrefs();
      if (document.querySelector('[data-ask-location]') && !saved.at && !saved.allUae && !askedThisVisit()) show();
    });
    return () => document.removeEventListener('click', open);
  }, []);

  const matches = useMemo(() => searchPlaces(names, query), [names, query]);
  const shownRadius = radius ?? settings.fallback;
  const canSave = places.length < (isSignedIn() ? MAX_ACCOUNT_PLACES : MAX_GUEST_PLACES);

  function goTo(at: Point, label: string, radiusKm = radius) {
    savePrefs({ at, label, place: null, radiusKm });
    noteSpotUse(at, label);
    reloadWithPrefs();
  }

  function useGps() {
    if (!navigator.geolocation) return setStatus('Location is not available on this device. Search or use the map instead.');
    setStatus('Finding you…');
    navigator.geolocation.getCurrentPosition(
      ({ coords }) => {
        const here = { lat: coords.latitude, lng: coords.longitude };
        goTo(here, nearest(names, here)?.name ?? 'My location');
      },
      () => setStatus('Location permission denied. Search or use the map instead.'),
      { enableHighAccuracy: false, timeout: 10_000, maximumAge: 600_000 },
    );
  }

  function changeRadius(km: number) {
    setRadius(km);
    if (readPrefs().at) {
      savePrefs({ radiusKm: km });
      reloadWithPrefs();
    }
  }

  function allUae() {
    savePrefs({ at: null, label: null, place: null, allUae: true });
    reloadWithPrefs();
  }

  async function toggleDefault(place: Place) {
    await savePlace({ ...place, is_default: !place.is_default, is_auto: false });
    setPlaces(loadPlaces());
  }

  async function remove(place: Place) {
    await removePlace(place.id);
    setPlaces(loadPlaces());
  }

  async function picked(spot: PickedSpot) {
    const round = (n: number) => Math.round(n * 1000) / 1000;
    const at = { lat: round(spot.lat), lng: round(spot.lng) };

    if (!spot.save) return goTo(at, spot.area, spot.radiusKm);

    const place: Place = {
      id: map?.editing?.id ?? newPlaceId(),
      label: spot.save.label,
      kind: spot.save.kind,
      ...at,
      area: spot.area,
      radius_km: spot.radiusKm,
      is_default: spot.save.isDefault,
      is_auto: false,
      updated_at: new Date().toISOString(),
    };
    await savePlace(place);
    usePlace(place);
  }

  const start = () => {
    const saved = readPrefs();
    return saved.at ?? nearest(names, DUBAI) ?? DUBAI;
  };

  return (
    <dialog ref={dialog} className={map ? 'sheet max-h-none !w-full md:!w-[min(46rem,94vw)]' : 'sheet'} aria-labelledby="location-title">
      {map ? (
        <Suspense fallback={<div className="grid h-dvh place-items-center text-muted md:h-[85dvh]">Loading the map…</div>}>
          <MapPicker
            start={map.start}
            stores={stores}
            places={names}
            radiusKm={map.editing?.radius_km ?? shownRadius}
            radiusOptions={settings.options}
            radiusMax={settings.max}
            editing={map.editing}
            canSave={canSave || Boolean(map.editing)}
            onBack={() => setMap(null)}
            onDone={picked}
          />
        </Suspense>
      ) : (
        <div className="space-y-4 p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))]">
          <div className="flex items-center justify-between gap-3">
            <h2 id="location-title" className="text-xl font-bold">Where are you shopping?</h2>
            <button type="button" aria-label="Close" onClick={() => dialog.current?.close()} className="grid size-10 place-items-center rounded-full hover:bg-sunken">
              <X className="size-5" aria-hidden="true" />
            </button>
          </div>

          <div className="relative">
            <Search className="pointer-events-none absolute start-3.5 top-1/2 size-5 -translate-y-1/2 text-muted" aria-hidden="true" />
            <label htmlFor="location-search" className="sr-only">Search an area, mall or landmark</label>
            <input id="location-search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search area, mall or landmark" className="input ps-11" autoComplete="off" />
          </div>

          {query.trim().length >= 2 ? (
            <ul className="divide-y divide-line/70" aria-label="Places found">
              {matches.map((place) => (
                <li key={`${place.name}${place.lat}`}>
                  <button type="button" onClick={() => goTo(place, place.name)} className="flex min-h-12 w-full items-center gap-3 text-start hover:text-link">
                    <MapPin className="size-5 shrink-0 text-muted" aria-hidden="true" />
                    <span className="min-w-0 truncate font-medium">{place.name}</span>
                    <span className="ms-auto shrink-0 text-xs text-muted">{place.type === 'mall' ? 'Mall' : place.type === 'station' ? 'Metro' : ''}</span>
                  </button>
                </li>
              ))}
              {matches.length === 0 && <li className="py-3 text-sm text-muted">Nothing found. Try the map; you can drag it to any spot.</li>}
            </ul>
          ) : (
            <>
              <div className="grid grid-cols-2 gap-2">
                <button type="button" onClick={useGps} className="btn btn-primary px-3">
                  <LocateFixed className="size-5" aria-hidden="true" /> My location
                </button>
                <button type="button" onClick={() => setMap({ start: start() })} className="btn btn-quiet px-3">
                  <MapIcon className="size-5" aria-hidden="true" /> Use the map
                </button>
              </div>

              {status && <p role="status" className="text-sm text-muted">{status}</p>}

              {places.length > 0 && (
                <section aria-label="Saved places">
                  <h3 className="mb-1 font-semibold">Saved places</h3>
                  <ul className="divide-y divide-line/70">
                    {places.map((place) => {
                      const Icon = ICONS[place.kind];
                      return (
                        <li key={place.id} className="flex items-center gap-1">
                          <button type="button" onClick={() => usePlace(place)} aria-current={activePlace === place.id ? 'true' : undefined} className="flex min-h-14 min-w-0 flex-1 items-center gap-3 text-start">
                            <span className={`grid size-10 shrink-0 place-items-center rounded-full ${activePlace === place.id ? 'bg-primary text-on-primary' : 'bg-primary-soft text-link'}`}>
                              <Icon className="size-5" aria-hidden="true" />
                            </span>
                            <span className="min-w-0">
                              <span className="block truncate font-semibold">{place.label}</span>
                              <span className="block truncate text-xs text-muted">{[place.area, place.radius_km && `${place.radius_km} km`].filter(Boolean).join(' · ')}</span>
                            </span>
                          </button>
                          <button type="button" aria-pressed={place.is_default} aria-label={place.is_default ? `${place.label} is your default place` : `Make ${place.label} your default place`} onClick={() => toggleDefault(place)} className="grid size-11 shrink-0 place-items-center rounded-full hover:bg-sunken">
                            <Star className={`size-5 ${place.is_default ? 'fill-current text-save' : 'text-muted'}`} aria-hidden="true" />
                          </button>
                          <button type="button" aria-label={`Move ${place.label} on the map`} onClick={() => setMap({ start: place, editing: place })} className="grid size-11 shrink-0 place-items-center rounded-full text-muted hover:bg-sunken">
                            <Pencil className="size-5" aria-hidden="true" />
                          </button>
                          <button type="button" aria-label={`Delete ${place.label}`} onClick={() => remove(place)} className="grid size-11 shrink-0 place-items-center rounded-full text-muted hover:bg-sunken">
                            <Trash2 className="size-5" aria-hidden="true" />
                          </button>
                        </li>
                      );
                    })}
                  </ul>
                </section>
              )}

              <RadiusPicker legend="Show stores within" value={shownRadius} options={settings.options} max={settings.max} onChange={changeRadius} />

              <button type="button" onClick={allUae} className="flex min-h-11 items-center gap-2 text-sm font-semibold text-link">
                <Globe className="size-4" aria-hidden="true" /> Show all of the UAE
              </button>
            </>
          )}
        </div>
      )}
    </dialog>
  );
}
