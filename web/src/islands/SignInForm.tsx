import { useState } from 'react';
import { ApiError, call, markSignedIn } from '../lib/account';

/** Email → 6-digit code → signed in. No passwords. */
export default function SignInForm({ next = '/' }: { next?: string }) {
  const [step, setStep] = useState<'email' | 'code'>('email');
  const [email, setEmail] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  async function run(action: () => Promise<void>) {
    setBusy(true);
    setError('');
    try {
      await action();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Something went wrong. Please try again.');
    } finally {
      setBusy(false);
    }
  }

  const sendCode = (event: React.FormEvent) => {
    event.preventDefault();
    run(async () => {
      await call('POST', 'auth/code', { email });
      setStep('code');
    });
  };

  const verify = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const code = String(new FormData(event.currentTarget).get('code') ?? '');
    run(async () => {
      await call('POST', 'auth/verify', { email, code });
      markSignedIn();
      // Only same-site paths, never an external redirect.
      location.href = next.startsWith('/') && !next.startsWith('//') ? next : '/';
    });
  };

  return step === 'email' ? (
    <form onSubmit={sendCode} className="space-y-3">
      <label className="block text-sm font-medium" htmlFor="email">Email</label>
      <input id="email" type="email" required autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} className="w-full rounded-lg border border-zinc-300 px-3 py-2" />
      {error && <p className="text-sm text-deal">{error}</p>}
      <button disabled={busy} className="w-full rounded-lg bg-brand-600 py-2 font-medium text-white disabled:opacity-60">
        {busy ? 'Sending…' : 'Email me a sign-in code'}
      </button>
    </form>
  ) : (
    <form onSubmit={verify} className="space-y-3">
      <p className="text-sm text-zinc-600">We sent a 6-digit code to <strong>{email}</strong>. It expires in 10 minutes.</p>
      <label className="block text-sm font-medium" htmlFor="code">Code</label>
      <input id="code" name="code" inputMode="numeric" pattern="\d{6}" maxLength={6} required autoComplete="one-time-code" className="w-full rounded-lg border border-zinc-300 px-3 py-2 text-center text-xl tracking-[0.5em]" />
      {error && <p className="text-sm text-deal">{error}</p>}
      <button disabled={busy} className="w-full rounded-lg bg-brand-600 py-2 font-medium text-white disabled:opacity-60">
        {busy ? 'Checking…' : 'Sign in'}
      </button>
      <button type="button" onClick={() => setStep('email')} className="w-full text-sm text-zinc-500">Use another email</button>
    </form>
  );
}
