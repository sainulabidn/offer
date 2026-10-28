import { useState } from 'react';
import { addToPlan } from '../lib/plan';

/** "Add to plan" on product pages: this exact pack, or any brand of the product group. */
export default function AddToPlan({ variantId, label, groupId, groupName }: { variantId: number; label: string; groupId: number; groupName: string }) {
  const [added, setAdded] = useState('');

  const add = (any: boolean) => {
    addToPlan(any
      ? { key: `g:${groupId}`, name: `Any ${groupName}`, quantity: 1, match: { productGroupId: groupId } }
      : { key: `v:${variantId}`, name: label, quantity: 1, match: { variantId } });
    setAdded(any ? `Any ${groupName}` : label);
  };

  return (
    <div className="flex flex-wrap items-center gap-2 text-sm">
      <button type="button" onClick={() => add(false)} className="rounded-lg bg-brand-600 px-3 py-1.5 font-medium text-white">Add to plan</button>
      <button type="button" onClick={() => add(true)} className="rounded-lg border border-zinc-300 bg-white px-3 py-1.5">Any brand</button>
      {added && <a href="/plan" className="text-brand-700 underline">Added {added} · view plan</a>}
    </div>
  );
}
