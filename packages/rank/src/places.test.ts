import { describe, expect, it } from 'vitest';
import { searchPlaces, toPlaceName, type PlaceRow } from './places';

const rows: PlaceRow[] = [
  ['area', 'Al Nahda', 'النهدة', 25.29, 55.37],
  ['area', 'Al Nahda Sharjah', null, 25.3, 55.37],
  ['mall', 'Sahara Centre', 'صحارى سنتر', 25.297, 55.374],
  ['mall', 'Mall of the Emirates', null, 25.118, 55.2],
  ['station', 'Union', 'الاتحاد', 25.266, 55.314],
  ['area', 'Deira', 'ديرة', 25.27, 55.31],
];
const places = rows.map(toPlaceName);
const names = (query: string) => searchPlaces(places, query).map((place) => place.name);

describe('searchPlaces', () => {
  it('finds names from their start first, shortest first', () => {
    expect(names('al na')).toEqual(['Al Nahda', 'Al Nahda Sharjah']);
  });

  it('finds a word inside a name', () => {
    expect(names('sahara')).toEqual(['Sahara Centre']);
    expect(names('emirates')).toEqual(['Mall of the Emirates']);
  });

  it('ignores case', () => {
    expect(names('DEIRA')).toEqual(['Deira']);
  });

  it('forgives one typing mistake', () => {
    expect(names('nahada')).toContain('Al Nahda');
    expect(names('diera')).toEqual([]); // swapped letters are two edits
    expect(names('deirra')).toEqual(['Deira']);
  });

  it('finds Arabic names, however alef and ta marbuta are typed', () => {
    expect(names('النهده')).toContain('Al Nahda');
    expect(names('الإتحاد')).toEqual(['Union']);
    expect(names('ديره')).toEqual(['Deira']);
  });

  it('waits for two letters', () => {
    expect(names('a')).toEqual([]);
    expect(names('  ')).toEqual([]);
  });

  it('respects the limit', () => {
    expect(searchPlaces(places, 'al', 1)).toHaveLength(1);
  });
});
