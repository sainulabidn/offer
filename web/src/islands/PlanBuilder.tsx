import { useEffect, useMemo, useState } from 'react';
import { compare, type PlanItem } from '@offer/basket';
import { loadDirectory, loadPlan, nearbyStores, savePlan, searchHits, toCandidate, type Hit, type Plan } from '../lib/plan';

const aed = (n: number) => `AED ${n.toFixed(2)}`;

/** The shopping plan: items, settings, and every nearby store's basket total plus the best split. */
export default function PlanBuilder() {
  const [plan, setPlan] = useState<Plan>(() => loadPlan());
  const [query, setQuery] = useState('');
  const [suggestions, setSuggestions] = useState<Hit[]>([]);
  const [hits, setHits] = useState<Hit[] | null>(null);
  const [scope, setScope] = useState<ReturnType<typeof nearbyStores> | null>(null);
  const [areas, setAreas] = useState<{ slug: string; name: string; lat: number; lng: number }[]>([]);
  const [error, setError] = useState('');

  const update = (next: Plan) => {
    setPlan(next);
    savePlan(next);
  };
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
        setAreas(dir.areas);
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
    loadDirectory().then((d) => setAreas(d.areas)).catch(() => {});
  }, []);

  const result = useMemo(() => {
    if (!hits || !scope) return null;
    return compare(plan.items, hits.map(toCandidate), scope.stores, { maxStores: plan.settings.maxStores, extraStoreCost: plan.settings.extraStoreCost });
  }, [hits, scope, plan]);

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
        <div className="relative">
          <label htmlFor="plan-add" className="mb-1 block font-semibold">Add to your list</label>
          <input id="plan-add" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="rice, milk 2L, Almarai…" className="w-full rounded-lg border border-zinc-300 px-3 py-2" autoComplete="off" />
          {suggestions.length > 0 && (
            <ul className="absolute z-20 mt-1 max-h-80 w-full overflow-auto rounded-lg border border-zinc-200 bg-white text-sm shadow-lg">
              {uniqueGroups.slice(0, 4).map((h) => (
                <li key={`g${h.product_group_id}`}>
                  <button type="button" className="w-full px-3 py-2 text-start hover:bg-zinc-100" onClick={() => add({ key: `g:${h.product_group_id}`, name: `Any ${h.product_group}`, quantity: 1, match: { productGroupId: h.product_group_id } })}>
                    <strong>Any {h.product_group}</strong> <span className="text-zinc-500">cheapest per unit</span>
                  </button>
                </li>
              ))}
              {uniqueVariants.slice(0, 8).map((h) => (
                <li key={`v${h.variant_id}`}>
                  <button type="button" className="w-full px-3 py-2 text-start hover:bg-zinc-100" onClick={() => add({ key: `v:${h.variant_id}`, name: `${h.product_name} · ${h.variant_label}`, quantity: 1, match: { variantId: h.variant_id } })}>
                    {h.product_name} <span className="text-zinc-500">· {h.variant_label}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>

        {plan.items.length === 0 ? (
          <p className="rounded-xl border border-dashed border-zinc-300 p-4 text-sm text-zinc-500">Your list is empty. Add items above or tap “Add to plan” on any product.</p>
        ) : (
          <ul className="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white text-sm">
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

        <fieldset className="space-y-2 rounded-xl border border-zinc-200 bg-white p-3 text-sm">
          <legend className="px-1 font-semibold">Where and how</legend>
          <select className="w-full rounded-lg border border-zinc-300 px-2 py-1.5" value="" onChange={(e) => {
            const a = areas.find((x) => x.slug === e.target.value);
            if (a) update({ ...plan, settings: { ...plan.settings, lat: a.lat, lng: a.lng, place: a.name } });
          }}>
            <option value="">{plan.settings.place ? `Near ${plan.settings.place}` : 'All UAE: choose an area…'}</option>
            {areas.map((a) => <option key={a.slug} value={a.slug}>{a.name}</option>)}
          </select>
          <button type="button" className="w-full rounded-lg border border-zinc-300 py-1.5" onClick={() => navigator.geolocation?.getCurrentPosition((p) => update({ ...plan, settings: { ...plan.settings, lat: Math.round(p.coords.latitude * 100) / 100, lng: Math.round(p.coords.longitude * 100) / 100, place: 'your location' } }))}>Use my location</button>
          <label className="flex items-center justify-between">Radius
            <select value={plan.settings.radiusKm} onChange={(e) => setSetting('radiusKm', Number(e.target.value))} className="rounded border px-2 py-1">
              {[3, 5, 10, 20, 50].map((r) => <option key={r} value={r}>{r} km</option>)}
            </select>
          </label>
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
        {error && <p className="text-deal">{error}</p>}
        {plan.items.length > 0 && !result && !error && <p className="text-zinc-500">Comparing prices…</p>}
        {result && result.stores.length === 0 && <p className="rounded-xl border border-dashed border-zinc-300 p-6 text-zinc-500">No nearby store has these items on offer right now. Try a bigger radius.</p>}

        {result?.split && (
          <div className="mb-4 rounded-xl border-2 border-brand-600 bg-brand-50 p-4">
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
            <li key={r.store.chainId} className="rounded-xl border border-zinc-200 bg-white p-4">
              <div className="flex items-baseline justify-between gap-2">
                <p className="font-semibold">
                  {i === 0 && r.missing.length === 0 && <span className="me-2 rounded bg-brand-50 px-1.5 text-xs text-brand-700">Cheapest complete basket</span>}
                  {r.store.chainName} <span className="text-sm font-normal text-zinc-500">· {r.store.branch}{plan.settings.lat !== null && `, ${r.store.distanceKm.toFixed(1)} km`}</span>
                </p>
                <p className="text-lg font-bold">{aed(r.total)}</p>
              </div>
              <p className="text-sm text-zinc-600">
                {plan.items.length - r.missing.length} of {plan.items.length} items{r.saving > 0 && ` · you save ${aed(r.saving)}`}
              </p>
              {r.missing.length > 0 && <p className="text-xs text-zinc-500">Not on offer here: {r.missing.map((m) => m.name).join(', ')}</p>}
            </li>
          ))}
        </ol>
      </section>
    </div>
  );
}
