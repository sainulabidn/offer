import { useEffect, useRef, useState } from 'react';
import { Marker, type Map as MapLibreMap } from 'maplibre-gl';
import type { Point } from '@offer/rank';
import { boundsOf, createMap, restyle } from '../lib/map';

export interface MapPin extends Point {
  id: number;
  /** Shown on the pin: initials or a price. */
  text: string;
  label: string;
  /** Selected, or the cheapest. */
  highlight: boolean;
}

/** Pins on a map. Loaded only when opened, because the map library is large. */
export default function StoresMap({ pins, onPin }: { pins: MapPin[]; onPin?: (id: number) => void }) {
  const box = useRef<HTMLDivElement>(null);
  const mapRef = useRef<MapLibreMap | null>(null);
  const markers = useRef<Marker[]>([]);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    if (!box.current || !pins.length) return;
    let map: MapLibreMap;
    try {
      map = createMap(box.current, boundsOf(pins));
    } catch {
      setFailed(true); // no WebGL on this device
      return;
    }
    mapRef.current = map;
    map.on('load', () => restyle(map));
    return () => map.remove();
  }, []);

  useEffect(() => {
    const map = mapRef.current;
    if (!map) return;
    markers.current.forEach((marker) => marker.remove());
    markers.current = pins.map((pin) => {
      const el = document.createElement('button');
      el.type = 'button';
      el.textContent = pin.text;
      el.setAttribute('aria-label', pin.label);
      el.setAttribute('aria-pressed', String(pin.highlight));
      el.className = `rounded-full border-2 border-white px-2.5 py-1 text-xs font-bold tabular-nums shadow-float ${pin.highlight ? 'bg-primary text-on-primary' : 'bg-surface text-ink'}`;
      if (onPin) el.addEventListener('click', () => onPin(pin.id));
      return new Marker({ element: el }).setLngLat([pin.lng, pin.lat]).addTo(map);
    });
  }, [pins]);

  if (failed) return <p className="rounded-xl bg-sunken p-4 text-center text-sm text-muted">The map could not load on this device.</p>;

  return (
    <div className="relative h-[60dvh] overflow-hidden rounded-(--radius-card) bg-sunken">
      <div ref={box} className="size-full" />
    </div>
  );
}
