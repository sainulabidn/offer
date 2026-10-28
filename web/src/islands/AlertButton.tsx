import { useState } from 'react';
import { ApiError, call, isSignedIn, signInUrl } from '../lib/account';

/** "Alert me": emails the shopper when this product (or any brand of it) goes on offer nearby. */
export default function AlertButton({ productId, groupId, groupName, unitLabel }: { productId: number; groupId: number; groupName: string; unitLabel: string }) {
  const [open, setOpen] = useState(false);
  const [state, setState] = useState<'idle' | 'saving' | 'saved' | 'error'>('idle');
  const [message, setMessage] = useState('');

  function start() {
    if (!isSignedIn()) {
      location.href = signInUrl();
      return;
    }
    setOpen(!open);
  }

  async function save(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    let near: { lat: number; lng: number; place: string } | null = null;
    try {
      near = JSON.parse(localStorage.getItem('offer:near') ?? 'null');
    } catch {}
    const anyBrand = form.get('scope') === 'group';
    setState('saving');
    try {
      await call('POST', 'alerts', {
        type: anyBrand ? 'group' : 'product',
        id: anyBrand ? groupId : productId,
        max_unit_price: form.get('max') ? Number(form.get('max')) : null,
        ...(near && form.get('near') ? { lat: near.lat, lng: near.lng, radius_km: 10, place: near.place } : {}),
      });
      setState('saved');
    } catch (e) {
      setMessage(e instanceof ApiError ? e.message : 'Could not save the alert.');
      setState('error');
    }
  }

  if (state === 'saved') return <p className="text-sm text-brand-700">Alert saved. We will email you. <a href="/account" className="underline">Manage alerts</a></p>;

  return (
    <div className="relative inline-block">
      <button type="button" onClick={start} className="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm">Alert me</button>
      {open && (
        <form onSubmit={save} className="absolute start-0 top-full z-30 mt-1 w-72 space-y-2 rounded-lg border border-zinc-200 bg-white p-3 text-sm shadow-lg">
          <label className="flex items-center gap-2"><input type="radio" name="scope" value="product" defaultChecked /> This product</label>
          <label className="flex items-center gap-2"><input type="radio" name="scope" value="group" /> Any brand of {groupName.toLowerCase()}</label>
          <label className="block">Only when cheaper than (AED {unitLabel})
            <input name="max" type="number" min="0" step="0.01" placeholder="any price" className="mt-1 w-full rounded border px-2 py-1" />
          </label>
          <label className="flex items-center gap-2"><input type="checkbox" name="near" value="1" defaultChecked /> Only within 10 km of my chosen area</label>
          {state === 'error' && <p className="text-deal">{message}</p>}
          <button disabled={state === 'saving'} className="w-full rounded-lg bg-brand-600 py-1.5 font-medium text-white">{state === 'saving' ? 'Saving…' : 'Save alert'}</button>
        </form>
      )}
    </div>
  );
}
