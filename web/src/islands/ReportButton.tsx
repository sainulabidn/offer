import { useRef, useState } from 'react';
import { ApiError, call } from '../lib/account';

const REASONS = [
  ['wrong_price', 'Wrong price'],
  ['expired', 'Expired / not in store'],
  ['wrong_product', 'Wrong product or image'],
  ['wrong_store', 'Wrong store'],
  ['other', 'Other'],
] as const;

/** Report a wrong price, expired deal, etc. Guests can report too (Turnstile is checked by the API when enabled). */
export default function ReportButton({ type, id, label = 'Report' }: { type: 'offer' | 'product' | 'variant' | 'branch'; id: number; label?: string }) {
  const dialog = useRef<HTMLDialogElement>(null);
  const [state, setState] = useState<'idle' | 'sending' | 'done' | 'error'>('idle');
  const [message, setMessage] = useState('');

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setState('sending');
    try {
      const result = await call<{ duplicate: boolean }>('POST', 'reports', {
        type,
        id,
        reason: form.get('reason'),
        comment: form.get('comment') || null,
      });
      setMessage(result.duplicate ? 'You already reported this; we are on it.' : 'Thanks! Our team will check it.');
      setState('done');
    } catch (e) {
      setMessage(e instanceof ApiError ? e.message : 'Could not send the report. Please try again.');
      setState('error');
    }
  }

  return (
    <>
      <button type="button" onClick={() => dialog.current?.showModal()} className="text-xs text-zinc-500 underline-offset-2 hover:underline">
        {label}
      </button>
      <dialog ref={dialog} className="m-auto w-[min(26rem,90vw)] rounded-xl p-0 backdrop:bg-black/40">
        {state === 'done' ? (
          <div className="p-5 text-sm">
            <p>{message}</p>
            <button type="button" onClick={() => dialog.current?.close()} className="mt-4 rounded-lg bg-brand-600 px-4 py-2 text-white">
              Close
            </button>
          </div>
        ) : (
          <form onSubmit={submit} className="space-y-3 p-5 text-sm">
            <h2 className="text-base font-semibold">What is wrong?</h2>
            {REASONS.map(([value, text], i) => (
              <label key={value} className="flex items-center gap-2">
                <input type="radio" name="reason" value={value} defaultChecked={i === 0} required /> {text}
              </label>
            ))}
            <textarea name="comment" maxLength={1000} placeholder="Details (optional), e.g. shelf price 49.95" className="w-full rounded-lg border border-zinc-300 p-2" rows={3} />
            {state === 'error' && <p className="text-deal">{message}</p>}
            <div className="flex justify-end gap-2">
              <button type="button" onClick={() => dialog.current?.close()} className="rounded-lg border px-4 py-2">
                Cancel
              </button>
              <button disabled={state === 'sending'} className="rounded-lg bg-brand-600 px-4 py-2 text-white disabled:opacity-60">
                {state === 'sending' ? 'Sending…' : 'Send report'}
              </button>
            </div>
          </form>
        )}
      </dialog>
    </>
  );
}
