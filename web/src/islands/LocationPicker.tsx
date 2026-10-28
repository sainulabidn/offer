import { useState } from 'react';

const RADII = [1, 3, 5, 10, 20, 50];
const round = (n: number) => Math.round(n * 100) / 100; // ~1 km, so nearby shoppers share cached pages

/** Sets ?near=lat,lng&r=km&place=… on the current page, from GPS or a chosen area. Remembers the choice. */
export default function LocationPicker({ areas, current }: { areas: { slug: string; name: string; lat: number; lng: number }[]; current: { label: string; radiusKm: number } | null }) {
  const [radius, setRadius] = useState(current?.radiusKm ?? 5);
  const [status, setStatus] = useState('');

  function go(lat: number, lng: number, place: string, r = radius) {
    const url = new URL(location.href);
    url.searchParams.set('near', `${round(lat)},${round(lng)}`);
    url.searchParams.set('r', String(r));
    url.searchParams.set('place', place);
    url.searchParams.delete('page');
    try {
      localStorage.setItem('offer:near', JSON.stringify({ lat: round(lat), lng: round(lng), place }));
    } catch {
      // Storage blocked: the URL still carries the location.
    }
    location.href = url.toString();
  }

  function useGps() {
    if (!navigator.geolocation) return setStatus('Location is not available on this device.');
    setStatus('Finding you…');
    navigator.geolocation.getCurrentPosition(
      (p) => go(p.coords.latitude, p.coords.longitude, 'your location'),
      () => setStatus('Location permission denied. Choose an area instead.'),
      { enableHighAccuracy: false, timeout: 10_000, maximumAge: 600_000 },
    );
  }

  function clear() {
    const url = new URL(location.href);
    ['near', 'r', 'place', 'page'].forEach((k) => url.searchParams.delete(k));
    try {
      localStorage.removeItem('offer:near');
    } catch {}
    location.href = url.toString();
  }

  function changeRadius(r: number) {
    setRadius(r);
    const url = new URL(location.href);
    if (url.searchParams.get('near')) {
      url.searchParams.set('r', String(r));
      location.href = url.toString();
    }
  }

  return (
    <fieldset className="space-y-2">
      <legend className="font-semibold">Near</legend>
      {current && (
        <p className="text-xs text-zinc-600">
          Within {current.radiusKm} km of {current.label}.{' '}
          <button type="button" onClick={clear} className="text-brand-700 underline">All UAE</button>
        </p>
      )}
      <button type="button" onClick={useGps} className="w-full rounded-lg border border-zinc-300 bg-white py-1.5">Use my location</button>
      <select
        aria-label="Choose an area"
        className="w-full rounded-lg border border-zinc-300 bg-white px-2 py-1.5"
        value=""
        onChange={(e) => {
          const area = areas.find((a) => a.slug === e.target.value);
          if (area) go(area.lat, area.lng, area.name);
        }}
      >
        <option value="">Choose an area…</option>
        {areas.map((a) => <option key={a.slug} value={a.slug}>{a.name}</option>)}
      </select>
      <select aria-label="Radius" className="w-full rounded-lg border border-zinc-300 bg-white px-2 py-1.5" value={radius} onChange={(e) => changeRadius(Number(e.target.value))}>
        {RADII.map((r) => <option key={r} value={r}>Within {r} km</option>)}
      </select>
      {status && <p className="text-xs text-zinc-500" role="status">{status}</p>}
    </fieldset>
  );
}
