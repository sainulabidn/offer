import { useState } from 'react';
import { addToPlan } from '../lib/plan';
import { count } from '../lib/track';

/** "Add to plan" on product pages: this exact pack, or any brand of the product group. */
export default function AddToPlan({ targets, variantId, label, groupId, groupName }: { targets: string; variantId: number; label: string; groupId: number; groupName: string }) {
  const [added, setAdded] = useState('');

  const add = (any: boolean) => {
    addToPlan(any
      ? { key: `g:${groupId}`, name: `Any ${groupName}`, quantity: 1, match: { productGroupId: groupId } }
      : { key: `v:${variantId}`, name: label, quantity: 1, match: { variantId } });
    count('plan', targets);
    setAdded(any ? `Any ${groupName}` : label);
  };

  return (
    <div className="flex flex-wrap items-center gap-2 text-sm">
      <button type="button" onClick={() => add(false)} className="btn btn-primary">Add to plan</button>
      <button type="button" onClick={() => add(true)} className="btn btn-quiet">Any brand</button>
      {added && <a href="/plan" className="text-link underline">Added {added} · view plan</a>}
    </div>
  );
}
