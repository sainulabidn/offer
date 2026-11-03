import { describe, expect, it } from 'vitest';
import { cookieLabel, prefsKey, resolvePrefs, serializePrefs } from './prefs';

const url = (path = '/') => new URL(`https://offer.test${path}`);

describe('resolvePrefs', () => {
  it('reads a saved location and rounds it to about 1 km', () => {
    const prefs = resolvePrefs({ url: url(), cookie: 'at=25.2894,55.3704&r=25&i=10&s=45.12' });

    expect(prefs).toMatchObject({ at: { lat: 25.29, lng: 55.37 }, radiusKm: 25, itemRadiusKm: 10, stores: [12, 45] });
  });

  it('lets the URL win field by field, so a shared link shows what its sender saw', () => {
    const prefs = resolvePrefs({ url: url('/?near=24.45,54.37&r=5'), cookie: 'at=25.29,55.37&r=25&i=10' });

    expect(prefs).toMatchObject({ at: { lat: 24.45, lng: 54.37 }, radiusKm: 5, itemRadiusKm: 10 });
  });

  it('treats an empty ?stores= as none selected, not the saved stores', () => {
    expect(resolvePrefs({ url: url('/?stores='), cookie: 's=12.45' }).stores).toEqual([]);
    expect(resolvePrefs({ url: url('/'), cookie: 's=12.45' }).stores).toEqual([12, 45]);
  });

  it('removes and restores the item limit from the URL', () => {
    expect(resolvePrefs({ url: url('/?items=all') }).allUae).toBe(true);
    expect(resolvePrefs({ url: url('/?items=25'), cookie: 'all=1' })).toMatchObject({ allUae: false, itemRadiusKm: 25 });
    expect(resolvePrefs({ url: url('/'), cookie: 'all=1' }).allUae).toBe(true);
  });

  it('hides and shows store-employee offers from the URL or the cookie', () => {
    expect(resolvePrefs({ url: url('/') }).hideStaffOnly).toBe(false);
    expect(resolvePrefs({ url: url('/?staff=0') }).hideStaffOnly).toBe(true);
    expect(resolvePrefs({ url: url('/'), cookie: 'so=0' }).hideStaffOnly).toBe(true);
    expect(resolvePrefs({ url: url('/?staff=1'), cookie: 'so=0' }).hideStaffOnly).toBe(false);
    // The search form: a hidden 0, then the ticked checkbox's 1.
    expect(resolvePrefs({ url: url('/search?staff=0&staff=1') }).hideStaffOnly).toBe(false);
  });

  it('never throws on a broken cookie or URL', () => {
    const bad = ['%', '%%%', 'at=abc', 'at=91,200', 'at=25', 'at=1,2,3', 'r=-5', 'r=99999', 's=a.b', 'at=,', '\u0000', 'a=b&&&==='];
    for (const cookie of bad) {
      expect(() => resolvePrefs({ url: url(), cookie })).not.toThrow();
      expect(resolvePrefs({ url: url(), cookie })).toMatchObject({ at: null, radiusKm: null, stores: [] });
    }
    expect(resolvePrefs({ url: url('/?near=x,y&r=abc&stores=x') })).toMatchObject({ at: null, radiusKm: null, stores: [] });
  });

  it('limits selected stores', () => {
    expect(resolvePrefs({ url: url('/?stores=1,2,3,4,5,6') }).stores).toHaveLength(4);
    expect(resolvePrefs({ url: url('/?stores=7,7,7') }).stores).toEqual([7]);
  });

  it('uses the emirate only when no location is known', () => {
    expect(resolvePrefs({ url: url(), regionCode: 'DU' }).emirate).toBe('dubai');
    expect(resolvePrefs({ url: url(), regionCode: 'DU', cookie: 'at=25.29,55.37' }).emirate).toBeNull();
    expect(resolvePrefs({ url: url(), regionCode: 'XX' }).emirate).toBeNull();
    expect(resolvePrefs({ url: url(), cookie: '' }).emirate).toBeNull();
  });
});

describe('prefsKey', () => {
  it('differs for visitors from different emirates, whose pages differ', () => {
    const dubai = resolvePrefs({ url: url(), regionCode: 'DU' });
    const abuDhabi = resolvePrefs({ url: url(), regionCode: 'AZ' });

    expect(prefsKey(dubai)).not.toBe(prefsKey(abuDhabi));
  });

  it('differs for anything that changes the page', () => {
    const base = 'at=25.29,55.37&r=10&i=50';
    const keys = [base, 'at=25.3,55.37&r=10&i=50', 'at=25.29,55.37&r=5&i=50', 'at=25.29,55.37&r=10&i=25', `${base}&all=1`, `${base}&s=12`, `${base}&so=0`].map((cookie) =>
      prefsKey(resolvePrefs({ url: url(), cookie })),
    );

    expect(new Set(keys).size).toBe(keys.length);
  });

  it('is shared by nearby shoppers', () => {
    const a = resolvePrefs({ url: url(), cookie: 'at=25.2894,55.3704' });
    const b = resolvePrefs({ url: url(), cookie: 'at=25.2912,55.3688' });

    expect(prefsKey(a)).toBe(prefsKey(b));
  });

  it('ignores the private label, which must not reach shared pages', () => {
    const plain = resolvePrefs({ url: url(), cookie: 'at=25.29,55.37' });
    const labelled = resolvePrefs({ url: url(), cookie: 'at=25.29,55.37&l=<script>alert(1)</script>&p=abc' });

    expect(prefsKey(plain)).toBe(prefsKey(labelled));
    expect(JSON.stringify(labelled)).not.toContain('script');
  });
});

describe('serializePrefs', () => {
  it('round-trips through the cookie', () => {
    const cookie = serializePrefs({ at: { lat: 25.2894, lng: 55.3704 }, radiusKm: 25, itemRadiusKm: 10, allUae: true, stores: [12, 45], hideStaffOnly: true, label: 'Home & work', place: 'abc' });

    expect(resolvePrefs({ url: url(), cookie })).toEqual({ at: { lat: 25.29, lng: 55.37 }, emirate: null, radiusKm: 25, itemRadiusKm: 10, allUae: true, stores: [12, 45], hideStaffOnly: true });
    expect(cookieLabel(cookie)).toEqual({ label: 'Home & work', place: 'abc' });
  });
});
