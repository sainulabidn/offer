import { distanceKm, type Point } from '@offer/rank';

/**
 * Emirates and areas as the website's location pages use them (/dubai, /dubai/al-barsha/c/dairy-eggs).
 * No server imports: the location panel uses this in the browser to move to the page for a new place.
 */

/** ISO 3166-2 codes of the emirates, for geo meta tags and schema.org addresses. */
export const EMIRATE_CODES: Record<string, string> = {
  dubai: 'AE-DU',
  'abu-dhabi': 'AE-AZ',
  sharjah: 'AE-SH',
  ajman: 'AE-AJ',
  'umm-al-quwain': 'AE-UQ',
  'ras-al-khaimah': 'AE-RK',
  fujairah: 'AE-FU',
};

/** A row of the directory's area list (backend DirectoryController). */
export interface AreaRow {
  id: number;
  slug: string;
  /** "Al Barsha, Dubai" for an area. */
  name: string;
  type: string;
  parent_id: number | null;
  lat: number;
  lng: number;
}

export interface Region {
  id: number;
  slug: string;
  /** In the URL: an area's slug without its emirate's ("al-nahda-sharjah" → "al-nahda" under /sharjah). */
  urlSlug: string;
  name: string;
  type: 'emirate' | 'area';
  lat: number;
  lng: number;
  emirate: Region | null;
}

/** Emirates and their areas from the store directory. */
export function regions(dir: { areas: AreaRow[] }): Region[] {
  const byId = new Map<number, Region>();
  for (const row of dir.areas) {
    byId.set(row.id, { id: row.id, slug: row.slug, urlSlug: row.slug, name: row.name.split(',')[0].trim(), type: row.type === 'emirate' ? 'emirate' : 'area', lat: row.lat, lng: row.lng, emirate: null });
  }
  for (const row of dir.areas) {
    const region = byId.get(row.id)!;
    const parent = row.parent_id ? byId.get(row.parent_id) : undefined;
    if (region.type === 'area' && parent) {
      region.emirate = parent;
      region.urlSlug = region.slug.endsWith(`-${parent.slug}`) ? region.slug.slice(0, -parent.slug.length - 1) : region.slug;
    }
  }
  return [...byId.values()];
}

/** The emirate (and area) in a path such as /dubai/al-barsha; null when either isn't one we list. */
export function findLocation(all: Region[], emirateSlug: string, areaSlug?: string): { emirate: Region; area: Region | null } | null {
  const emirate = all.find((region) => region.type === 'emirate' && region.slug === emirateSlug);
  if (!emirate) return null;
  if (!areaSlug) return { emirate, area: null };
  const area = all.find((region) => region.emirate?.id === emirate.id && region.urlSlug === areaSlug);
  return area ? { emirate, area } : null;
}

export interface Scope {
  emirate?: Region | null;
  area?: Region | null;
  category?: { slug: string } | null;
  brand?: { slug: string } | null;
}

/** /dubai/al-barsha/c/dairy-eggs, /dubai/brands/almarai, /c/dairy-eggs (all UAE), /dubai… */
export function scopePath({ emirate, area, category, brand }: Scope): string {
  const place = emirate ? `/${emirate.slug}${area ? `/${area.urlSlug}` : ''}` : '';
  if (category) return `${place}/c/${category.slug}`;
  if (brand) return `${place}/brands/${brand.slug}`;
  return place || '/';
}

/** "Al Barsha, Dubai", "Dubai" or "the UAE". */
export const placeName = (emirate?: Region | null, area?: Region | null) => (area && emirate ? `${area.name}, ${emirate.name}` : (emirate?.name ?? 'the UAE'));

/** An area counts as the shopper's when its middle is this close. */
const AREA_KM = 4;

/**
 * The location page for a point: the emirate of the nearest store (emirate borders run through towns,
 * so a store tells better than the nearest emirate's middle), and the area when the point is in one.
 * `rest` is what the page is about ("/c/dairy-eggs", or "" for everything).
 */
export function locationPathFor(at: Point, rest: string, dir: { areas: AreaRow[]; branches: { lat: number; lng: number; emirate: string | null }[] }): string {
  const all = regions(dir);
  const store = dir.branches.filter((b) => b.emirate).reduce<{ km: number; emirate: string } | null>((best, b) => {
    const km = distanceKm(at, b);
    return !best || km < best.km ? { km, emirate: b.emirate! } : best;
  }, null);
  const emirate = all.find((region) => region.type === 'emirate' && region.slug === store?.emirate);
  if (!emirate) return rest || '/';

  const area = all
    .filter((region) => region.emirate?.id === emirate.id)
    .map((region) => ({ region, km: distanceKm(at, region) }))
    .filter(({ km }) => km <= AREA_KM)
    .sort((a, b) => a.km - b.km)[0]?.region;
  return `${scopePath({ emirate, area })}${rest}`;
}
