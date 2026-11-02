import type { Point } from './index';

/** One row of the UAE place list, as GET /api/v1/places sends it: [type, English, Arabic, lat, lng]. */
export type PlaceRow = [type: string, english: string, arabic: string | null, lat: number, lng: number];

export interface PlaceName extends Point {
  type: string;
  name: string;
  arabic: string | null;
}

export const toPlaceName = ([type, name, arabic, lat, lng]: PlaceRow): PlaceName => ({ type, name, arabic, lat, lng });

/** Ignores case, accents and the Arabic spellings people type interchangeably. */
function normalize(text: string): string {
  return text
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ًͯ-ْ]/g, '')
    .replace(/[أإآ]/g, 'ا')
    .replace(/ة/g, 'ه')
    .replace(/ى/g, 'ي')
    .trim();
}

/** True when two words differ by at most one typed letter: "nahada" finds "nahda". */
function oneEditApart(a: string, b: string): boolean {
  if (Math.abs(a.length - b.length) > 1) return false;
  let i = 0;
  let j = 0;
  let edits = 0;
  while (i < a.length && j < b.length) {
    if (a[i] === b[j]) {
      i++;
      j++;
      continue;
    }
    if (++edits > 1) return false;
    if (a.length > b.length) i++;
    else if (b.length > a.length) j++;
    else {
      i++;
      j++;
    }
  }
  return edits + (a.length - i) + (b.length - j) <= 1;
}

/** How well a name fits a query, lower is better; null means it doesn't. */
function fit(name: string, query: string): number | null {
  if (name.startsWith(query)) return 0;
  const words = name.split(/[\s,-]+/);
  if (words.some((word) => word.startsWith(query))) return 1;
  if (name.includes(query)) return 2;
  if (query.length >= 4 && words.some((word) => oneEditApart(word.slice(0, query.length + 1), query) || oneEditApart(word, query))) return 3;
  return null;
}

/** Instant search over the place list, in the browser: no server call per keystroke. */
export function searchPlaces(places: PlaceName[], query: string, limit = 8): PlaceName[] {
  const q = normalize(query);
  if (q.length < 2) return [];

  const found: { place: PlaceName; score: number }[] = [];
  for (const place of places) {
    const english = fit(normalize(place.name), q);
    const arabic = place.arabic ? fit(normalize(place.arabic), q) : null;
    const score = Math.min(english ?? 9, arabic ?? 9);
    if (score < 9) found.push({ place, score });
  }

  return found
    .sort((a, b) => a.score - b.score || a.place.name.length - b.place.name.length)
    .slice(0, limit)
    .map((item) => item.place);
}
