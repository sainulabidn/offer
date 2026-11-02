import { useEffect, useMemo, useRef, useState } from 'react';
import { ArrowLeft, Briefcase, Home as HomeIcon, LocateFixed, MapPin, Search } from 'lucide-react';
import type { GeoJSONSource, Map as MapLibreMap } from 'maplibre-gl';
/** Whatever a map source takes; maplibre's own type, so this needs no extra package. */
type Shape = Parameters<GeoJSONSource['setData']>[0];
import { distanceKm, nearest, searchPlaces, type PlaceName, type Point } from '@offer/rank';
import { createMap, prefersDark, restyle } from '../lib/map';
import type { Place } from '../lib/places';
import RadiusPicker from './RadiusPicker';

/** A map that hasn't appeared by now isn't coming; say so and leave the search to work without it. */
const LOAD_TIMEOUT_MS = 12_000;

export interface PickedSpot extends Point {
  area: string;
  radiusKm: number;
  save: { label: string; kind: Place['kind']; isDefault: boolean } | null;
}

interface Props {
  start: Point;
  stores: Point[];
  places: PlaceName[];
  radiusKm: number;
  radiusOptions: number[];
  radiusMax: number;
  /** Editing a saved place rather than choosing a new spot. */
  editing?: Place;
  canSave: boolean;
  onBack: () => void;
  onDone: (spot: PickedSpot) => void;
}

/** A circle as a map shape; precise enough for the distances people choose here. */
function circle({ lat, lng }: Point, km: number): Shape {
  const dLat = km / 111.32;
  const dLng = km / (111.32 * Math.cos((lat * Math.PI) / 180));
  const ring = Array.from({ length: 65 }, (_, i) => {
    const angle = (i / 64) * 2 * Math.PI;
    return [lng + dLng * Math.cos(angle), lat + dLat * Math.sin(angle)];
  });
  return { type: 'Polygon', coordinates: [ring] };
}

function circleBounds({ lat, lng }: Point, km: number): [[number, number], [number, number]] {
  const dLat = km / 111.32;
  const dLng = km / (111.32 * Math.cos((lat * Math.PI) / 180));
  return [[lng - dLng, lat - dLat], [lng + dLng, lat + dLat]];
}

const KINDS = [
  { kind: 'home', label: 'Home', Icon: HomeIcon },
  { kind: 'work', label: 'Office', Icon: Briefcase },
  { kind: 'other', label: 'Other', Icon: MapPin },
] as const;

/**
 * Choose a spot on the map: the pin stays in the middle and the map moves under it, which is easier
 * with one thumb than dragging a pin. Only opened on request, because the map library is large.
 */
export default function MapPicker({ start, stores, places, radiusKm, radiusOptions, radiusMax, editing, canSave, onBack, onDone }: Props) {
  const box = useRef<HTMLDivElement>(null);
  const mapRef = useRef<MapLibreMap | null>(null);
  const radiusRef = useRef(radiusKm);
  const [center, setCenter] = useState<Point>(start);
  const [radius, setRadius] = useState(radiusKm);
  const [moving, setMoving] = useState(false);
  const [failed, setFailed] = useState(false);
  const [query, setQuery] = useState('');
  const [note, setNote] = useState('');
  const [kind, setKind] = useState<Place['kind'] | null>(editing?.kind ?? null);
  const [label, setLabel] = useState(editing?.label ?? '');
  const [isDefault, setIsDefault] = useState(editing?.is_default ?? false);

  const area = useMemo(() => nearest(places, center)?.name ?? 'Selected spot', [places, center]);
  const inRange = useMemo(() => stores.filter((store) => distanceKm(center, store) <= radius).length, [stores, center, radius]);
  const matches = useMemo(() => searchPlaces(places, query, 6), [places, query]);

  // The map's handlers outlive this render, and the store list can arrive after the map opens.
  const storesRef = useRef(stores);
  storesRef.current = stores;

  const storeShapes = (at: Point, km: number): Shape => ({
    type: 'FeatureCollection',
    features: storesRef.current.map((store) => ({
      type: 'Feature',
      properties: { near: distanceKm(at, store) <= km ? 1 : 0 },
      geometry: { type: 'Point', coordinates: [store.lng, store.lat] },
    })),
  });

  useEffect(() => {
    if (!box.current) return;
    const dark = prefersDark();
    let map: MapLibreMap;
    try {
      map = createMap(box.current, circleBounds(start, radiusKm));
    } catch {
      setFailed(true); // no WebGL on this device
      return;
    }
    mapRef.current = map;

    const primary = dark ? '#41c08c' : '#0f5c3f';
    const giveUp = setTimeout(() => setFailed(true), LOAD_TIMEOUT_MS);

    map.on('load', () => {
      clearTimeout(giveUp);
      restyle(map);
      map.addSource('radius', { type: 'geojson', data: circle(start, radiusKm) });
      map.addLayer({ id: 'radius-fill', type: 'fill', source: 'radius', paint: { 'fill-color': primary, 'fill-opacity': 0.1 } });
      map.addLayer({ id: 'radius-line', type: 'line', source: 'radius', paint: { 'line-color': primary, 'line-width': 2, 'line-opacity': 0.7 } });
      map.addSource('stores', { type: 'geojson', data: storeShapes(start, radiusKm) });
      map.addLayer({
        id: 'stores',
        type: 'circle',
        source: 'stores',
        paint: {
          'circle-radius': ['case', ['==', ['get', 'near'], 1], 6, 4],
          'circle-color': ['case', ['==', ['get', 'near'], 1], '#e8593c', dark ? '#5d6b63' : '#a9b0a6'],
          'circle-stroke-width': 2,
          'circle-stroke-color': dark ? '#16211c' : '#ffffff',
        },
      });
    });

    const here = () => {
      const { lat, lng } = map.getCenter();
      return { lat, lng };
    };
    map.on('movestart', () => setMoving(true));
    // The circle follows the map as it moves; the name and count update when it stops, so they don't flicker.
    map.on('move', () => (map.getSource('radius') as GeoJSONSource | undefined)?.setData(circle(here(), radiusRef.current)));
    map.on('moveend', () => {
      setMoving(false);
      setCenter(here());
      (map.getSource('stores') as GeoJSONSource | undefined)?.setData(storeShapes(here(), radiusRef.current));
    });

    return () => {
      clearTimeout(giveUp);
      map.remove();
    };
  }, []);

  useEffect(() => {
    (mapRef.current?.getSource('stores') as GeoJSONSource | undefined)?.setData(storeShapes(center, radius));
  }, [stores]);

  function changeRadius(km: number) {
    setRadius(km);
    radiusRef.current = km;
    const map = mapRef.current;
    if (!map) return;
    (map.getSource('radius') as GeoJSONSource | undefined)?.setData(circle(center, km));
    (map.getSource('stores') as GeoJSONSource | undefined)?.setData(storeShapes(center, km));
    map.fitBounds(circleBounds(center, km), { padding: 24, duration: 400 });
  }

  function flyTo(place: Point) {
    setQuery('');
    mapRef.current?.flyTo({ center: [place.lng, place.lat], zoom: 13, duration: 700 });
    if (failed || !mapRef.current) setCenter(place);
  }

  function goToMe() {
    if (!navigator.geolocation) return setNote('Location is not available on this device.');
    setNote('Finding you…');
    navigator.geolocation.getCurrentPosition(
      ({ coords }) => {
        setNote('');
        flyTo({ lat: coords.latitude, lng: coords.longitude });
      },
      () => setNote('Location permission denied. Move the map or search instead.'),
      { enableHighAccuracy: false, timeout: 10_000, maximumAge: 600_000 },
    );
  }

  function pickKind(next: Place['kind']) {
    setKind(kind === next ? null : next);
    if (next !== 'other') setLabel(KINDS.find((k) => k.kind === next)?.label ?? '');
    else if (['Home', 'Office'].includes(label)) setLabel('');
  }

  const name = (kind === 'other' ? label.trim() : KINDS.find((k) => k.kind === kind)?.label) || area;

  function done() {
    onDone({ ...center, area, radiusKm: radius, save: kind ? { label: name, kind, isDefault } : null });
  }

  return (
    <div className="flex h-dvh flex-col bg-surface md:h-[85dvh]">
      <div className="relative min-h-0 flex-1">
        {/* MapLibre's stylesheet makes its own box position: relative, so the size has to come from a parent. */}
        <div className="absolute inset-0 bg-sunken">
          <div ref={box} className="size-full" role="application" aria-label="Map. Move it to put the pin on your spot." />
        </div>

        {failed ? (
          <p className="absolute inset-x-4 top-20 rounded-xl bg-surface p-3 text-center text-sm shadow-float">The map could not load. Search for your area above; you can still save it.</p>
        ) : (
          <div aria-hidden="true" className={`pointer-events-none absolute start-1/2 top-1/2 -ms-5 -mt-12 transition-transform duration-150 ${moving ? '-translate-y-2.5' : ''}`}>
            <svg viewBox="0 0 40 48" className="size-12 drop-shadow-lg">
              <path fill="#e8593c" stroke="#fff" strokeWidth="2.5" d="M20 2C10.6 2 3 9.5 3 18.8 3 31 20 46 20 46s17-15 17-27.2C37 9.5 29.4 2 20 2Z" />
              <circle cx="20" cy="18.5" r="6.5" fill="#fff" />
            </svg>
          </div>
        )}

        <div className="absolute inset-x-3 top-3">
          <div className="relative flex items-center gap-2">
            <button type="button" aria-label="Back" onClick={onBack} className="grid size-12 shrink-0 place-items-center rounded-full bg-surface shadow-float">
              <ArrowLeft className="size-5" aria-hidden="true" />
            </button>
            <div className="relative flex-1">
              <Search className="pointer-events-none absolute start-4 top-1/2 size-5 -translate-y-1/2 text-muted" aria-hidden="true" />
              <label htmlFor="map-search" className="sr-only">Search an area, mall or landmark</label>
              <input id="map-search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search area, mall or landmark" autoComplete="off" className="input min-h-12 rounded-full border-transparent ps-12 shadow-float" />
            </div>
          </div>
          {matches.length > 0 && (
            <ul className="mt-2 overflow-hidden rounded-2xl bg-surface shadow-float">
              {matches.map((place) => (
                <li key={`${place.name}${place.lat}`}>
                  <button type="button" onClick={() => flyTo(place)} className="flex min-h-11 w-full items-center gap-3 px-4 py-2 text-start hover:bg-sunken">
                    <MapPin className="size-4 shrink-0 text-muted" aria-hidden="true" />
                    <span className="min-w-0 truncate font-medium">{place.name}</span>
                    <span className="ms-auto shrink-0 text-xs text-muted">{place.type === 'mall' ? 'Mall' : place.type === 'station' ? 'Metro' : ''}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>

        <button type="button" onClick={goToMe} aria-label="Go to my location" className="absolute end-3 bottom-8 grid size-12 place-items-center rounded-full bg-surface text-link shadow-float">
          <LocateFixed className="size-5" aria-hidden="true" />
        </button>
      </div>

      <div className="max-h-[56dvh] space-y-3 overflow-y-auto rounded-t-3xl bg-surface p-4 pb-[max(1rem,env(safe-area-inset-bottom))] shadow-float">
        <div aria-live="polite">
          <p className="text-lg font-bold">{moving ? 'Finding the area…' : area}</p>
          <p className="text-sm text-muted">{inRange} {inRange === 1 ? 'store' : 'stores'} within {radius} km</p>
          {note && <p role="status" className="mt-1 text-sm text-muted">{note}</p>}
        </div>

        <RadiusPicker legend="Show stores within" value={radius} options={radiusOptions} max={radiusMax} onChange={changeRadius} />

        {canSave && (
          <fieldset>
            <legend className="mb-2 font-semibold">Save this place <span className="font-normal text-muted">(optional)</span></legend>
            <div className="flex flex-wrap gap-2">
              {KINDS.map(({ kind: k, label: text, Icon }) => (
                <button key={k} type="button" aria-pressed={kind === k} onClick={() => pickKind(k)} className={`chip ${kind === k ? 'chip-on' : ''}`}>
                  <Icon className="size-4" aria-hidden="true" /> {text}
                </button>
              ))}
            </div>
            {kind === 'other' && (
              <label className="mt-2 block">
                <span className="sr-only">Name this place</span>
                <input value={label} maxLength={40} onChange={(e) => setLabel(e.target.value)} placeholder="Name, such as Gym or Mum's" className="input" />
              </label>
            )}
            {kind && (
              <label className="mt-2 flex min-h-11 items-center gap-2.5">
                <input type="checkbox" checked={isDefault} onChange={(e) => setIsDefault(e.target.checked)} className="size-5 accent-[var(--color-primary)]" />
                Make this my default place
              </label>
            )}
          </fieldset>
        )}

        <button type="button" onClick={done} className="btn btn-primary w-full">{editing ? 'Save changes' : 'Show deals here'}</button>
      </div>
    </div>
  );
}
