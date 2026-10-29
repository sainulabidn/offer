import { useEffect, useState } from 'react';
import { PUBLIC_API_URL } from 'astro:env/client';
import type { PlanItem } from '@offer/basket';
import { ApiError, call, isSignedIn, signInUrl } from '../lib/account';
import { addToPlan, switchPlan, loadPlan } from '../lib/plan';

/** A plan someone shared: see the list, copy it into your own plan, or join to edit it together. */
export default function SharedPlan({ token }: { token: string }) {
  const [shared, setShared] = useState<{ name: string; owner: string; items: PlanItem[] } | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    fetch(`${PUBLIC_API_URL}/api/v1/shared-plans/${encodeURIComponent(token)}`, { headers: { Accept: 'application/json' } })
      .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
      .then(setShared)
      .catch(() => setError('This shared plan was not found. Ask for a new link.'));
  }, [token]);

  function copy() {
    shared?.items.forEach((item) => addToPlan(item));
    location.href = '/plan';
  }

  async function join() {
    if (!isSignedIn()) {
      location.href = signInUrl();
      return;
    }
    try {
      const plan = await call<{ id: string }>('POST', `shared-plans/${encodeURIComponent(token)}/join`);
      await switchPlan(plan.id, loadPlan());
      location.href = '/plan';
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Could not join the plan.');
    }
  }

  if (error) return <p className="text-deal">{error}</p>;
  if (!shared) return <p className="text-zinc-500">Loading…</p>;

  return (
    <div className="max-w-xl space-y-4">
      <p><strong>{shared.owner}</strong> shared “{shared.name}” with you.</p>
      <ul className="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white text-sm">
        {shared.items.map((item) => (
          <li key={item.key} className="flex justify-between p-3"><span>{item.name}</span><span className="text-zinc-500">× {item.quantity}</span></li>
        ))}
      </ul>
      <div className="flex flex-wrap gap-2 text-sm">
        <button type="button" onClick={join} className="rounded-lg bg-brand-600 px-4 py-2 font-medium text-white">Join and edit together</button>
        <button type="button" onClick={copy} className="rounded-lg border border-zinc-300 bg-white px-4 py-2">Copy to my plan</button>
      </div>
    </div>
  );
}
