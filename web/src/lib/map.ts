import { Map as MapLibreMap, setWorkerUrl, type LngLatBoundsLike } from 'maplibre-gl';
import mapStylesUrl from 'maplibre-gl/dist/maplibre-gl.css?url';
import mapWorkerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';
import { PUBLIC_MAP_STYLE_URL } from 'astro:env/client';
import type { Point } from '@offer/rank';

// The library finds its worker by a path the bundler rewrites, so it has to be told the real one.
setWorkerUrl(mapWorkerUrl);

/** A plain import would link this stylesheet on every page; only maps need it. */
function loadMapStyles() {
  if (document.querySelector(`link[href="${mapStylesUrl}"]`)) return;
  document.head.append(Object.assign(document.createElement('link'), { rel: 'stylesheet', href: mapStylesUrl }));
}

export const prefersDark = () => matchMedia('(prefers-color-scheme: dark)').matches;

/** Recolours the free map to match the site, and labels it in English. */
export function restyle(map: MapLibreMap) {
  const look = prefersDark()
    ? { land: '#14201b', water: '#16323a', green: '#1a2e25', road: '#2f4239', building: '#1d2b25', text: '#c9d3cc', halo: '#0d1512' }
    : { land: '#f6f1e6', water: '#c9e2e6', green: '#d9ebdc', road: '#ffffff', building: '#ece5d6', text: '#3b4a42', halo: '#fbf8f1' };

  for (const layer of map.getStyle().layers) {
    const { id, type } = layer;
    if (type === 'background') map.setPaintProperty(id, 'background-color', look.land);
    else if (type === 'fill' && /water/.test(id)) map.setPaintProperty(id, 'fill-color', look.water);
    else if (type === 'fill' && /park|wood|grass|landcover/.test(id)) map.setPaintProperty(id, 'fill-color', look.green);
    else if (type === 'fill' && /building/.test(id)) map.setPaintProperty(id, 'fill-color', look.building);
    else if (type === 'fill') map.setPaintProperty(id, 'fill-color', look.land);
    else if (type === 'line' && /water/.test(id)) map.setPaintProperty(id, 'line-color', look.water);
    else if (type === 'line' && /highway|road|tunnel|bridge/.test(id)) map.setPaintProperty(id, 'line-color', look.road);
    else if (type === 'symbol' && map.getLayoutProperty(id, 'text-field')) {
      map.setLayoutProperty(id, 'text-field', ['coalesce', ['get', 'name:en'], ['get', 'name_en'], ['get', 'name:latin'], ['get', 'name']]);
      map.setPaintProperty(id, 'text-color', look.text);
      map.setPaintProperty(id, 'text-halo-color', look.halo);
    }
  }
}

/** Bounds around a set of points, or a circle's when given one point and a distance. */
export function boundsOf(points: Point[], paddingKm = 1): LngLatBoundsLike {
  const dLat = paddingKm / 111.32;
  const dLng = paddingKm / (111.32 * Math.cos(((points[0]?.lat ?? 25) * Math.PI) / 180));
  const lats = points.map((point) => point.lat);
  const lngs = points.map((point) => point.lng);
  return [[Math.min(...lngs) - dLng, Math.min(...lats) - dLat], [Math.max(...lngs) + dLng, Math.max(...lats) + dLat]];
}

/** A map in our style. Throws when the device has no WebGL. */
export function createMap(container: HTMLElement, bounds: LngLatBoundsLike): MapLibreMap {
  loadMapStyles();
  const map = new MapLibreMap({
    container,
    style: PUBLIC_MAP_STYLE_URL,
    bounds,
    fitBoundsOptions: { padding: 24 },
    attributionControl: { compact: false },
    dragRotate: false,
    pitchWithRotate: false,
    touchPitch: false,
  });
  map.touchZoomRotate.disableRotation();
  return map;
}
