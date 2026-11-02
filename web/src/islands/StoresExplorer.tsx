import { Suspense, lazy, useEffect, useMemo, useState } from 'react';
import { Check, List, Map as MapIcon, Star } from 'lucide-react';
import { MAX_STORES, isOpen, type OpeningHours } from '@offer/rank';
import { distanceLabel, initials, marketNow, tintFor } from '../lib/format';
import { savePrefs, syncPins } from '../lib/places';
import { pinnedStores, setPinnedStores } from '../lib/profile';
import { readPrefs } from '../lib/prefs';

// The map library is large, so it loads only when someone switches to the map.
const StoresMap = lazy(() => import('./StoresMap'));

export interface ExplorerStore {
  id: number;
  chain: string;
  chainSlug: string;
  name: string;
  slug: string;
  grade: number;
  logo: string | null;
  lat: number;
  lng: number;
  offers: number;
  km: number | null;
  hours: OpeningHours[];
  closed_today: boolean;
}

const GRADES = [[1, 'Big'], [2, 'Medium'], [3, 'Small']] as const;

/** Every store as a list or a map, with filters, and picking up to four stores to compare. */
export default function StoresExplorer({ stores }: { stores: ExplorerStore[] }) {
  const [view, setView] = useState<'list' | 'map'>('list');
  const [grade, setGrade] = useState<number | null>(null);
  const [openOnly, setOpenOnly] = useState(false);
  const [selected, setSelected] = useState<number[]>([]);
  const [open, setOpen] = useState<Record<number, boolean | null>>({});
  const [note, setNote] = useState('');
  const [pinned, setPinned] = useState<number[]>([]);

  // Worked out in the browser: this page is cached, so a server's "open now" would go stale.
  useEffect(() => {
    const now = marketNow();
    setSelected(readPrefs().stores);
    setPinned(pinnedStores());
    setOpen(Object.fromEntries(stores.map((store) => [store.id, store.closed_today ? false : isOpen(store.hours, now)])));
  }, []);

  const shown = useMemo(
    () => stores.filter((store) => (grade === null || (grade === 3 ? store.grade >= 3 : store.grade === grade)) && (!openOnly || open[store.id] === true)),
    [stores, grade, openOnly, open],
  );

  function toggle(id: number) {
    if (!selected.includes(id) && selected.length >= MAX_STORES) return setNote(`You can compare up to ${MAX_STORES} stores.`);
    const next = selected.includes(id) ? selected.filter((other) => other !== id) : [...selected, id];
    setNote('');
    setSelected(next);
    savePrefs({ stores: next });
  }

  function togglePin(id: number) {
    const next = pinned.includes(id) ? pinned.filter((other) => other !== id) : [...pinned, id];
    setPinned(next);
    setPinnedStores(next);
    syncPins();
  }

  const pins = useMemo(
    () => shown.map((store) => ({ id: store.id, lat: store.lat, lng: store.lng, text: initials(store.chain), label: `${store.chain}, ${store.name}`, highlight: selected.includes(store.id) })),
    [shown, selected],
  );

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-center gap-2">
        <div className="flex rounded-full border border-line bg-surface p-0.5" role="group" aria-label="View">
          <button type="button" aria-pressed={view === 'list'} onClick={() => setView('list')} className={`flex h-8 items-center gap-1.5 rounded-full px-3 text-sm font-semibold ${view === 'list' ? 'bg-primary text-on-primary' : ''}`}><List className="size-4" aria-hidden="true" /> List</button>
          <button type="button" aria-pressed={view === 'map'} onClick={() => setView('map')} className={`flex h-8 items-center gap-1.5 rounded-full px-3 text-sm font-semibold ${view === 'map' ? 'bg-primary text-on-primary' : ''}`}><MapIcon className="size-4" aria-hidden="true" /> Map</button>
        </div>
        {GRADES.map(([value, label]) => (
          <button key={value} type="button" aria-pressed={grade === value} onClick={() => setGrade(grade === value ? null : value)} className={`chip ${grade === value ? 'chip-on' : ''}`}>{label}</button>
        ))}
        <button type="button" aria-pressed={openOnly} onClick={() => setOpenOnly(!openOnly)} className={`chip ${openOnly ? 'chip-on' : ''}`}>Open now</button>
      </div>

      {note && <p role="status" className="mb-2 text-sm text-muted">{note}</p>}

      {view === 'map' ? (
        <Suspense fallback={<div className="skeleton h-[60dvh]" />}>
          <StoresMap pins={pins} onPin={toggle} />
          <p className="mt-2 text-sm text-muted">Tap a pin to select that store.</p>
        </Suspense>
      ) : shown.length ? (
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {shown.map((store) => {
            const on = selected.includes(store.id);
            return (
              <li key={store.id} className={`card flex items-center gap-3 p-3 ${on ? 'border-primary ring-2 ring-primary' : ''}`}>
                <a href={`/stores/${store.chainSlug}/${store.slug}`} className="flex min-w-0 flex-1 items-center gap-3">
                  {store.logo
                    ? <img src={store.logo} alt="" loading="lazy" className="size-12 rounded-full bg-white object-contain" />
                    : <span aria-hidden="true" className={`grid size-12 shrink-0 place-items-center rounded-full text-sm font-bold ${tintFor(store.chain)}`}>{initials(store.chain)}</span>}
                  <span className="min-w-0">
                    <span className="block truncate font-semibold">{store.chain}</span>
                    <span className="block truncate text-sm text-muted">{store.name}{store.km !== null && ` · ${distanceLabel(store.km)}`}</span>
                    <span className="block text-xs text-muted">
                      {store.offers} {store.offers === 1 ? 'offer' : 'offers'}
                      {open[store.id] === true && <span className="font-semibold text-link"> · Open now</span>}
                      {open[store.id] === false && <span> · Closed</span>}
                    </span>
                  </span>
                </a>
                <button type="button" aria-pressed={pinned.includes(store.id)} aria-label={`${store.chain}, ${store.name}: favourite store`} onClick={() => togglePin(store.id)} className="grid size-10 shrink-0 place-items-center rounded-full">
                  <Star className={`size-5 ${pinned.includes(store.id) ? 'fill-current text-save' : 'text-muted'}`} aria-hidden="true" />
                </button>
                <button type="button" aria-pressed={on} aria-label={`${on ? 'Remove' : 'Select'} ${store.chain}, ${store.name}`} onClick={() => toggle(store.id)} className={`grid size-10 shrink-0 place-items-center rounded-full border ${on ? 'border-primary bg-primary text-on-primary' : 'border-line'}`}>
                  {on && <Check className="size-5" aria-hidden="true" />}
                </button>
              </li>
            );
          })}
        </ul>
      ) : (
        <p className="rounded-(--radius-card) border border-dashed border-line p-8 text-center text-muted">No stores match. Try removing a filter.</p>
      )}

      {selected.length > 0 && (
        <a href={`/?stores=${selected.join(',')}`} rel="nofollow" className="btn btn-primary sticky bottom-20 z-20 mt-4 w-full shadow-float md:bottom-4">
          {selected.length === 1 ? 'See prices at this store' : `Compare ${selected.length} stores`}
        </a>
      )}
    </div>
  );
}
