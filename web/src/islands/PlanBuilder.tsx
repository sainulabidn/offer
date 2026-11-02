import { useEffect, useMemo, useState } from 'react';
import { compare, type PlanItem } from '@offer/basket';
import { isSignedIn, signInUrl } from '../lib/account';
import { loadDirectory, loadPlan, nearbyStores, savePlan, searchHits, sharePlan, startNewPlan, switchPlan, syncPlan, toCandidate, tripCost, type Hit, type Plan, type ServerPlan } from '../lib/plan';
import { loadPlaces, type Place } from '../lib/places';
import { readPrefs } from '../lib/prefs';
import RadiusPicker from './RadiusPicker';

const aed = (n: number) => `AED ${n.toFixed(2)}`;

/** The shopping plan: items, settings, and every nearby store's basket total plus the best split. */
export default function PlanBuilder() {
  const [plan, setPlan] = useState<Plan>(() => loadPlan());
  const [query, setQuery] = useState('');
  const [suggestions, setSuggestions] = useState<Hit[]>([]);
  const [hits, setHits] = useState<Hit[] | null>(null);
  const [scope, setScope] = useState<ReturnType<typeof nearbyStores> | null>(null);
  const [places, setPlaces] = useState<Place[]>([]);
  const [radii, setRadii] = useState({ options: [5, 10, 25, 50], max: 100, walkLimitM: 600 });
  const [error, setError] = useState('');
  const [plans, setPlans] = useState<ServerPlan[]>([]);
  const [shareUrl, setShareUrl] = useState('');

  const update = (next: Plan) => {
    setPlan(next);
    savePlan(next, setPlan); // the account's newer copy replaces ours on a conflict
  };

  // Signed in: bring in the newest copy from the account (another device, or family edits).
  useEffect(() => {
    syncPlan(plan).then(({ plan: synced, plans: list }) => {
      setPlan(synced);
      setPlans(list);
    });
  }, []);

  async function share() {
    if (!isSignedIn()) {
      location.href = signInUrl();
      return;
    }
    setShareUrl(await sharePlan(plan));
  }
  const setSetting = <K extends keyof Plan['settings']>(key: K, value: Plan['settings'][K]) =>
    update({ ...plan, settings: { ...plan.settings, [key]: value } });

  // Suggestions while typing (debounced).
  useEffect(() => {
    if (query.trim().length < 2) return setSuggestions([]);
    const t = setTimeout(() => searchHits({ q: query, per_page: '30' }).then(setSuggestions).catch(() => setSuggestions([])), 250);
    return () => clearTimeout(t);
  }, [query]);

  // Offers for the plan's items in nearby brochures: one request per change of items or location.
  const itemsKey = plan.items.map((i) => i.key).join('|');
  const { lat, lng, radiusKm } = plan.settings;
  useEffect(() => {
    if (!plan.items.length) return setHits([]);
    let alive = true;
    (async () => {
      try {
        const dir = await loadDirectory();
        const near = nearbyStores(dir, plan.settings);
        setScope(near);
        if (!near.brochureIds.length) return alive && setHits([]);
        const variants = plan.items.flatMap((i) => ('variantId' in i.match ? [i.match.variantId] : []));
        const groups = plan.items.flatMap((i) => ('productGroupId' in i.match ? [i.match.productGroupId] : []));
        const which = [variants.length && `variant_id:[${variants.join(',')}]`, groups.length && `product_group_id:[${groups.join(',')}]`].filter(Boolean).join(' || ');
        const result = await searchHits({ q: '*', filter_by: `brochure_id:[${near.brochureIds.join(',')}] && (${which})` });
        if (alive) setHits(result);
      } catch {
        if (alive) setError('Could not load prices. Check your connection.');
      }
    })();
    return () => {
      alive = false;
    };
  }, [itemsKey, lat, lng, radiusKm]);

  useEffect(() => {
    setPlaces(loadPlaces());
    loadDirectory().then((d) => setRadii({ options: d.settings.radius_options, max: d.settings.radius_max, walkLimitM: d.settings.walk_limit_m })).catch(() => {});
  }, []);

  /** Planning for Home from the office: a plan keeps its own place, whatever the rest of the site shows. */
  function planFor(id: string) {
    if (id === 'all') return update({ ...plan, settings: { ...plan.settings, lat: null, lng: null, place: '' } });
    if (id === 'here') {
      const { at, label } = readPrefs();
      return at && update({ ...plan, settings: { ...plan.settings, lat: at.lat, lng: at.lng, place: label ?? 'your area' } });
    }
    const place = places.find((p) => p.id === id);
    if (place) update({ ...plan, settings: { ...plan.settings, lat: place.lat, lng: place.lng, place: place.label, radiusKm: place.radius_km ?? plan.settings.radiusKm } });
  }

  const chosenPlace = plan.settings.lat === null ? 'all' : (places.find((p) => p.label === plan.settings.place && p.lat === plan.settings.lat && p.lng === plan.settings.lng)?.id ?? 'here');

  const result = useMemo(() => {
    if (!hits || !scope) return null;
    return compare(plan.items, hits.map(toCandidate), scope.stores, { maxStores: plan.settings.maxStores, tripCost: tripCost(plan.settings.extraStoreCost, radii.walkLimitM) });
  }, [hits, scope, plan, radii]);

  function add(item: PlanItem) {
    const items = [...plan.items];
    const existing = items.find((i) => i.key === item.key);
    if (existing) existing.quantity += 1;
    else items.push(item);
    update({ ...plan, items });
    setQuery('');
    setSuggestions([]);
  }

  const setQty = (key: string, quantity: number) =>
    update({ ...plan, items: quantity <= 0 ? plan.items.filter((i) => i.key !== key) : plan.items.map((i) => (i.key === key ? { ...i, quantity } : i)) });

  const uniqueGroups = [...new Map(suggestions.map((h) => [h.product_group_id, h])).values()];
  const uniqueVariants = [...new Map(suggestions.map((h) => [h.variant_id, h])).values()];

  return (
    <div className="grid gap-6 lg:grid-cols-[22rem_1fr]">
      <section className="space-y-4">
        <div className="flex flex-wrap items-center gap-2 text-sm">
          {plans.length > 1 ? (
            <select aria-label="Choose a plan" className="btn btn-quiet" value={plan.id} onChange={(e) => switchPlan(e.target.value, plan).then(setPlan)}>
              {plans.map((p) => <option key={p.id} value={p.id}>{p.name}{p.is_owner ? '' : ' (shared with me)'}</option>)}
            </select>
          ) : (
            <span className="font-medium">{plan.name}</span>
          )}
          <button type="button" onClick={share} className="btn btn-quiet">Share plan</button>
          <button type="button" onClick={() => setPlan(startNewPlan())} className="btn btn-quiet">New plan</button>
          {!isSignedIn() && <a href={signInUrl()} className="text-link underline">Sign in to use it on all your devices</a>}
        </div>
        {shareUrl && (
          <div className="rounded-lg border border-primary bg-primary-soft p-3 text-sm">
            <p className="mb-1">Anyone with this link can see the list; signed-in family members can join and edit it.</p>
            <input readOnly value={shareUrl} onFocus={(e) => e.target.select()} className="input" />
            <div className="mt-2 flex gap-3">
              <a href={`https://wa.me/?text=${encodeURIComponent(`Our shopping plan: ${shareUrl}`)}`} target="_blank" rel="noopener" className="text-link underline">Send on WhatsApp</a>
              <button type="button" onClick={() => navigator.clipboard.writeText(shareUrl)} className="text-link underline">Copy link</button>
            </div>
          </div>
        )}
        <div className="relative">
          <label htmlFor="plan-add" className="mb-1 block font-semibold">Add to your list</label>
          <input id="plan-add" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="rice, milk 2L, Almarai…" className="input" autoComplete="off" />
          {suggestions.length > 0 && (
            <ul className="absolute z-20 mt-1 max-h-80 w-full overflow-auto rounded-xl border border-line bg-surface text-sm shadow-lg">
              {uniqueGroups.slice(0, 4).map((h) => (
                <li key={`g${h.product_group_id}`}>
                  <button type="button" className="w-full px-3 py-2 text-start hover:bg-sunken" onClick={() => add({ key: `g:${h.product_group_id}`, name: `Any ${h.product_group}`, quantity: 1, match: { productGroupId: h.product_group_id } })}>
                    <strong>Any {h.product_group}</strong> <span className="text-muted">cheapest per unit</span>
                  </button>
                </li>
              ))}
              {uniqueVariants.slice(0, 8).map((h) => (
                <li key={`v${h.variant_id}`}>
                  <button type="button" className="w-full px-3 py-2 text-start hover:bg-sunken" onClick={() => add({ key: `v:${h.variant_id}`, name: `${h.product_name} · ${h.variant_label}`, quantity: 1, match: { variantId: h.variant_id } })}>
                    {h.product_name} <span className="text-muted">· {h.variant_label}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>

        {plan.items.length === 0 ? (
          <p className="rounded-xl border border-dashed border-line p-4 text-sm text-muted">Your list is empty. Add items above or tap “Add to plan” on any product.</p>
        ) : (
          <ul className="divide-y divide-line/70 card text-sm">
            {plan.items.map((item) => (
              <li key={item.key} className="flex items-center gap-2 p-3">
                <span className="flex-1">{item.name}</span>
                <button type="button" aria-label={`Fewer ${item.name}`} onClick={() => setQty(item.key, item.quantity - 1)} className="size-7 rounded border">−</button>
                <span className="w-6 text-center">{item.quantity}</span>
                <button type="button" aria-label={`More ${item.name}`} onClick={() => setQty(item.key, item.quantity + 1)} className="size-7 rounded border">+</button>
              </li>
            ))}
          </ul>
        )}

        <fieldset className="space-y-2 card p-3 text-sm">
          <legend className="px-1 font-semibold">Where and how</legend>
          <label className="block">Plan for
            <select aria-label="Plan for" className="input mt-1" value={chosenPlace} onChange={(e) => planFor(e.target.value)}>
              <option value="here">{chosenPlace === 'here' && plan.settings.place ? `Near ${plan.settings.place}` : 'Where I am browsing'}</option>
              {places.map((p) => <option key={p.id} value={p.id}>{p.label}{p.area ? ` (${p.area})` : ''}</option>)}
              <option value="all">All of the UAE</option>
            </select>
          </label>
          <button type="button" data-open-location className="text-sm font-semibold text-link">Add or change a place</button>
          {plan.settings.lat !== null && <RadiusPicker legend="Stores within" value={plan.settings.radiusKm} options={radii.options} max={radii.max} onChange={(km) => setSetting('radiusKm', km)} />}
          <label className="flex items-center justify-between">Stores I'll visit
            <select value={plan.settings.maxStores} onChange={(e) => setSetting('maxStores', Number(e.target.value) as 1 | 2 | 3)} className="rounded border px-2 py-1">
              <option value={1}>1</option><option value={2}>Up to 2</option><option value={3}>Up to 3</option>
            </select>
          </label>
          <label className="flex items-center justify-between">Each extra store is worth
            <select value={plan.settings.extraStoreCost} onChange={(e) => setSetting('extraStoreCost', Number(e.target.value))} className="rounded border px-2 py-1">
              {[0, 5, 10, 20, 30].map((c) => <option key={c} value={c}>AED {c}</option>)}
            </select>
          </label>
        </fieldset>
      </section>

      <section aria-live="polite">
        {error && <p className="text-save">{error}</p>}
        {plan.items.length > 0 && !result && !error && <p className="text-muted">Comparing prices…</p>}
        {result && result.stores.length === 0 && <p className="rounded-xl border border-dashed border-line p-6 text-muted">No nearby store has these items on offer right now. Try a bigger radius.</p>}

        {result?.split && (
          <div className="mb-4 rounded-xl border-2 border-primary bg-primary-soft p-4">
            <p className="font-semibold">
              Best: split between {result.split.stores.map((s) => s.chainName).join(' + ')} for {aed(result.split.total)}
            </p>
            <ul className="mt-2 text-sm">
              {result.split.lines.map((l) => <li key={l.item.key}>{l.item.quantity} × {l.offer.label} at <strong>{l.store.chainName}</strong>: {aed(l.cost)}</li>)}
            </ul>
          </div>
        )}

        <ol className="space-y-3">
          {result?.stores.map((r, i) => (
            <li key={r.store.chainId} className="card p-4">
              <div className="flex items-baseline justify-between gap-2">
                <p className="font-semibold">
                  {i === 0 && r.missing.length === 0 && <span className="me-2 rounded bg-primary-soft px-1.5 text-xs text-link">Cheapest complete basket</span>}
                  {r.store.chainName} <span className="text-sm font-normal text-muted">· {r.store.branch}{plan.settings.lat !== null && `, ${r.store.distanceKm.toFixed(1)} km`}</span>
                </p>
                <p className="text-lg font-bold">{aed(r.total)}</p>
              </div>
              <p className="text-sm text-muted">
                {plan.items.length - r.missing.length} of {plan.items.length} items{r.saving > 0 && ` · you save ${aed(r.saving)}`}
              </p>
              {r.missing.length > 0 && <p className="text-xs text-muted">Not on offer here: {r.missing.map((m) => m.name).join(', ')}</p>}
            </li>
          ))}
        </ol>
      </section>
    </div>
  );
}
