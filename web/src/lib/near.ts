import { API_URL } from 'astro:env/server';

/** "Near me": radius filtering against the store directory, done in the Worker without any geo API. */
export interface Directory {
  branches: { id: number; chain_id: number; chain: string; name: string; area: string | null; lat: number; lng: number }[];
  brochures: { id: number; chain_id: number; valid_from: string; valid_to: string; branch_ids: number[] }[];
  areas: { slug: string; name: string; lat: number; lng: number }[];
}

export interface Near {
  lat: number;
  lng: number;
  radiusKm: number;
  label: string;
}

export const RADII = [1, 3, 5, 10, 20, 50];

let cached: { at: number; directory: Directory } | null = null;

export async function directory(): Promise<Directory> {
  if (!cached || Date.now() - cached.at > 300_000) {
    const response = await fetch(`${API_URL}/api/v1/directory`, { headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error(`Directory failed with ${response.status}`);
    cached = { at: Date.now(), directory: (await response.json()) as Directory };
  }
  return cached.directory;
}

/** Great-circle distance in km. */
export function distanceKm(a: { lat: number; lng: number }, b: { lat: number; lng: number }): number {
  const rad = Math.PI / 180;
  const dLat = (b.lat - a.lat) * rad;
  const dLng = (b.lng - a.lng) * rad;
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLng / 2) ** 2;
  return 12_742 * Math.asin(Math.sqrt(h));
}

/** Reads ?near=25.29,55.37&r=5&place=Al+Nahda (coordinates are rounded to ~1 km so pages cache well). */
export function parseNear(url: URL): Near | null {
  const [lat, lng] = (url.searchParams.get('near') ?? '').split(',').map(Number);
  if (!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) return null;
  const r = Number(url.searchParams.get('r'));
  return { lat, lng, radiusKm: RADII.includes(r) ? r : 5, label: url.searchParams.get('place') || 'your location' };
}

/** Brochures live at a branch within the radius → that brochure's nearest branch and distance. */
export function nearbyBrochures(dir: Directory, near: Near) {
  const inRange = new Map<number, { km: number; branch: Directory['branches'][number] }>();
  for (const branch of dir.branches) {
    const km = distanceKm(near, branch);
    if (km <= near.radiusKm) inRange.set(branch.id, { km, branch });
  }

  const result = new Map<number, { km: number; branch: string }>();
  for (const brochure of dir.brochures) {
    for (const id of brochure.branch_ids) {
      const hit = inRange.get(id);
      if (hit && (!result.has(brochure.id) || hit.km < result.get(brochure.id)!.km)) {
        result.set(brochure.id, { km: hit.km, branch: hit.branch.name });
      }
    }
  }
  return result;
}
