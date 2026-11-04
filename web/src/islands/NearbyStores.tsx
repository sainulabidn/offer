import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { ArrowLeftRight, ArrowRight, Check, ChevronDown, ChevronUp } from 'lucide-react';
import { MAX_STORES, rankStores, type Point } from '@offer/rank';
import { distanceLabel, initials, tintFor } from '../lib/format';
import type { Directory } from '../lib/near';
import { personalStoreOrder } from '../lib/personal';
import { savePrefs } from '../lib/places';
import { loadDirectory } from '../lib/plan';
import { storeCards, storeTile, type StoreTile } from '../lib/store-cards';

interface Props {
  stores: StoreTile[];
  title: string;
  /** The shopper's point when it is exact enough for a radius; null on a rough or unknown location. */
  at: Point | null;
  radiusKm: number;
  radiusOptions: number[];
  radiusMax: number;
  /** The stores in the comparison the page shows now. */
  selected: number[];
}

// Lets the browser use a layout effect where it can and keeps the server quiet about it.
const useBrowserLayoutEffect = typeof window === 'undefined' ? useEffect : useLayoutEffect;

/**
 * The home page's stores: two rows (a swipeable rail on phones) that open into the full list. A card
 * goes to its store page. Changing the distance re-ranks from the browser's copy of the directory, so
 * it costs no request and no page render; only "Compare" loads a new page, which is cached like any other.
 */
export default function NearbyStores({ stores: initial, title, at, radiusKm, radiusOptions, radiusMax, selected }: Props) {
  const list = useRef<HTMLUListElement>(null);
  const [stores, setStores] = useState(initial);
  const [radius, setRadius] = useState(radiusKm);
  const [custom, setCustom] = useState(false);
  const [typed, setTyped] = useState(String(radiusKm));
  const [expanded, setExpanded] = useState(false);
  const [overflows, setOverflows] = useState(initial.length > 4);
  const [comparing, setComparing] = useState(false);
  const [picked, setPicked] = useState(selected);
  const [note, setNote] = useState('');

  useEffect(() => setStores(personalStoreOrder(initial)), [initial]);

  // "View more" only when the closed list hides something: on a phone it scrolls sideways, on a big screen it is cut at two rows.
  useBrowserLayoutEffect(() => {
    const el = list.current;
    if (!el || expanded) return;
    const measure = () => setOverflows(el.scrollHeight > el.clientHeight + 1 || el.scrollWidth > el.clientWidth + 1);
    measure();
    const observer = new ResizeObserver(measure);
    observer.observe(el);
    return () => observer.disconnect();
  }, [expanded, stores]);

  async function changeRadius(km: number) {
    if (!at || km < 1 || km > radiusMax) return;
    setRadius(km);
    setExpanded(true);
    savePrefs({ radiusKm: km });
    try {
      const dir = (await loadDirectory()) as unknown as Directory;
      const ranked = rankStores(storeCards(dir), { at, radiusKm: km, selected, weights: dir.settings.store_weights });
      setStores(personalStoreOrder(ranked.map(storeTile)));
      setNote('');
    } catch {
      setNote('Could not load the store list. Check your connection.');
    }
  }

  function pick(id: number) {
    if (picked.includes(id)) return setPicked(picked.filter((other) => other !== id));
    if (picked.length >= MAX_STORES) return setNote(`You can compare up to ${MAX_STORES} stores.`);
    setNote('');
    setPicked([...picked, id]);
  }

  function startComparing() {
    if (comparing) return stopComparing();
    setComparing(true);
    setExpanded(true);
  }

  function stopComparing() {
    setComparing(false);
    setPicked(selected);
    setNote('');
  }

  const choices = [...new Set([...radiusOptions, radius])].sort((a, b) => a - b);

  return (
    <section className="mt-5" aria-labelledby="stores-title">
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <h2 id="stores-title" className="text-xl font-bold">{title}</h2>
        <div className="flex flex-wrap items-center gap-2">
          {at && (
            <div className="relative">
              <label htmlFor="stores-radius" className="sr-only">Show stores within</label>
              <select
                id="stores-radius"
                value={custom ? 'custom' : String(radius)}
                onChange={(e) => {
                  if (e.target.value === 'custom') return setCustom(true);
                  setCustom(false);
                  changeRadius(Number(e.target.value));
                }}
                className="chip cursor-pointer appearance-none pe-8 text-base md:text-sm"
              >
                {choices.map((km) => <option key={km} value={km}>{`Within ${km} km`}</option>)}
                <option value="custom">Custom…</option>
              </select>
              <ChevronDown className="pointer-events-none absolute end-2.5 top-1/2 size-4 -translate-y-1/2" aria-hidden="true" />
            </div>
          )}
          {stores.length > 1 && (
            <button type="button" aria-pressed={comparing} onClick={startComparing} className={`chip ${comparing ? 'chip-on' : ''}`}>
              <ArrowLeftRight className="size-4" aria-hidden="true" /> Compare
            </button>
          )}
        </div>
      </div>

      {custom && at && (
        <form
          className="mb-3 flex max-w-xs items-center gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            const km = Math.round(Number(typed));
            if (km >= 1 && km <= radiusMax) {
              setCustom(false);
              changeRadius(km);
            } else setNote(`Choose 1 to ${radiusMax} km.`);
          }}
        >
          <label className="flex-1">
            <span className="sr-only">Distance in kilometres, up to {radiusMax}</span>
            <input type="number" inputMode="numeric" min={1} max={radiusMax} step={1} value={typed} onChange={(e) => setTyped(e.target.value)} className="input" />
          </label>
          <span className="text-muted">km</span>
          <button className="btn btn-quiet">Apply</button>
        </form>
      )}

      {comparing && <p className="mb-2 text-sm text-muted">Tick 2 to {MAX_STORES} stores to compare their prices.</p>}
      {note && <p role="status" className="mb-2 text-sm text-muted">{note}</p>}

      {stores.length ? (
        <ul
          ref={list}
          aria-label="Stores"
          className={expanded
            ? 'grid grid-cols-2 gap-3 md:grid-cols-[repeat(auto-fill,minmax(11.5rem,1fr))]'
            : 'rail md:-mx-1 md:grid md:max-h-[10.5rem] md:grid-cols-[repeat(auto-fill,minmax(11.5rem,1fr))] md:overflow-hidden md:px-1 md:pb-0'}
        >
          {stores.map((store) => {
            const ticked = comparing ? picked.includes(store.id) : store.selected;
            return (
              <li key={store.id} className={`relative ${expanded ? '' : 'w-[11.5rem] md:w-auto'}`}>
                <a
                  href={`/stores/${store.chainSlug}/${store.slug}`}
                  className={`card flex h-[4.75rem] items-center gap-2.5 p-2.5 transition hover:border-primary ${comparing ? 'pe-10' : ''} ${ticked ? 'border-primary ring-2 ring-primary' : ''}`}
                >
                  {store.logo
                    ? <img src={store.logo} alt="" loading="lazy" decoding="async" className="size-11 shrink-0 rounded-full bg-white object-contain" />
                    : <span aria-hidden="true" className={`grid size-11 shrink-0 place-items-center rounded-full text-xs font-bold ${tintFor(store.chain)}`}>{initials(store.chain)}</span>}
                  <span className="min-w-0">
                    <span className="block truncate text-sm/tight font-semibold">{store.chain}</span>
                    <span className="block truncate text-xs text-muted">{store.name}</span>
                    <span className="block truncate text-xs text-muted">
                      {store.km !== null ? `${distanceLabel(store.km)} · ` : ''}{store.offers} {store.offers === 1 ? 'offer' : 'offers'}
                    </span>
                  </span>
                </a>
                {comparing ? (
                  <label className="absolute end-1 top-1/2 grid size-10 -translate-y-1/2 cursor-pointer place-items-center">
                    <input type="checkbox" checked={ticked} onChange={() => pick(store.id)} aria-label={`Compare ${store.chain}, ${store.name}`} className="size-5 accent-[var(--color-primary)]" />
                  </label>
                ) : ticked && (
                  <span className="pointer-events-none absolute end-1.5 top-1.5 grid size-5 place-items-center rounded-full bg-primary text-on-primary"><Check className="size-3.5" aria-hidden="true" /></span>
                )}
              </li>
            );
          })}
        </ul>
      ) : (
        <div className="card p-5 text-center">
          <p className="font-semibold">{at ? `No stores within ${radius} km` : 'No stores found here'}</p>
          <p className="mt-1 text-sm text-muted">{at ? 'Choose a wider distance above.' : 'Try a wider area to see more stores.'}</p>
          <button type="button" data-open-location className="btn btn-quiet mt-3">Change area</button>
        </div>
      )}

      {comparing ? (
        <div className="sticky bottom-[5.25rem] z-20 mt-3 flex gap-2 rounded-2xl border border-line bg-surface p-2 shadow-float md:bottom-4">
          <button
            type="button"
            disabled={picked.length < 2}
            onClick={() => (location.href = `/?stores=${picked.join(',')}#selected-title`)}
            className="btn btn-primary flex-1 disabled:opacity-50"
          >
            {picked.length < 2 ? 'Tick at least 2 stores' : `Compare ${picked.length} stores`}
          </button>
          <button type="button" onClick={stopComparing} className="btn btn-quiet">Cancel</button>
        </div>
      ) : (
        <div className="mt-1 flex items-center justify-between gap-2">
          {stores.length > 0 && (overflows || expanded) ? (
            <button type="button" aria-expanded={expanded} onClick={() => setExpanded(!expanded)} className="flex min-h-11 items-center gap-1 text-sm font-semibold text-link">
              {expanded ? <>Show less <ChevronUp className="size-4" aria-hidden="true" /></> : <>View more <ChevronDown className="size-4" aria-hidden="true" /></>}
            </button>
          ) : <span />}
          <a href="/stores" className="flex min-h-11 items-center gap-1 text-sm font-semibold text-link">All stores <ArrowRight className="size-4" aria-hidden="true" /></a>
        </div>
      )}
    </section>
  );
}
