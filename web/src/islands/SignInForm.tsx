import { useEffect, useState } from 'react';
import { ApiError, call, markSignedIn } from '../lib/account';
import { addPasskey, deviceCanCreatePasskey, listPasskeys, passkeyAutofillSupported, passkeyError, passkeysSupported, signInWithPasskey } from '../lib/passkeys';

const OFFER_DISMISSED = 'offer:passkey-offer-dismissed';

/**
 * No passwords: a passkey (fingerprint, face or screen lock), or email → 6-digit code.
 * After a code sign-in on a device that can hold a passkey, offers to create one for next time.
 */
export default function SignInForm({ next = '/' }: { next?: string }) {
  const [step, setStep] = useState<'email' | 'code' | 'offer-passkey'>('email');
  const [email, setEmail] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [canPasskey, setCanPasskey] = useState(false);

  // Only same-site paths, never an external redirect.
  const done = () => (location.href = next.startsWith('/') && !next.startsWith('//') ? next : '/');

  useEffect(() => {
    setCanPasskey(passkeysSupported());
    // Saved passkeys appear in the email field's suggestions; picking one signs straight in.
    passkeyAutofillSupported().then((supported) => {
      if (!supported) return;
      signInWithPasskey({ autofill: true }).then(done, (e) => {
        const message = passkeyError(e);
        if (message) setError(message);
      });
    });
  }, []);

  async function run(action: () => Promise<void>) {
    setBusy(true);
    setError('');
    try {
      await action();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : (passkeyError(e) ?? ''));
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
      if (await shouldOfferPasskey()) setStep('offer-passkey');
      else done();
    });
  };

  if (step === 'offer-passkey') {
    return (
      <div className="space-y-3">
        <h2 className="text-lg font-semibold">Sign in faster next time</h2>
        <p className="text-sm text-zinc-600">
          Create a passkey and use your fingerprint, face or screen lock instead of waiting for an email code.
        </p>
        {error && <p className="text-sm text-deal">{error}</p>}
        <button type="button" disabled={busy} onClick={() => run(async () => { await addPasskey(); done(); })} className="w-full rounded-lg bg-brand-600 py-2 font-medium text-white disabled:opacity-60">
          {busy ? 'Waiting for your device…' : 'Create a passkey'}
        </button>
        <button type="button" onClick={() => { remember(OFFER_DISMISSED, String(Date.now())); done(); }} className="w-full text-sm text-zinc-500">
          Not now
        </button>
      </div>
    );
  }

  return step === 'email' ? (
    <div className="space-y-4">
      {canPasskey && (
        <>
          <button type="button" disabled={busy} onClick={() => run(async () => { await signInWithPasskey(); done(); })} className="flex w-full items-center justify-center gap-2 rounded-lg border border-zinc-300 bg-white py-2 font-medium disabled:opacity-60">
            <KeyIcon /> {busy ? 'Waiting for your device…' : 'Sign in with a passkey'}
          </button>
          <p className="flex items-center gap-3 text-xs text-zinc-400 before:h-px before:flex-1 before:bg-zinc-200 after:h-px after:flex-1 after:bg-zinc-200">or</p>
        </>
      )}
      <form onSubmit={sendCode} className="space-y-3">
        <label className="block text-sm font-medium" htmlFor="email">Email</label>
        {/* "webauthn" lets the browser list saved passkeys under this field. */}
        <input id="email" type="email" required autoComplete="email webauthn" value={email} onChange={(e) => setEmail(e.target.value)} className="w-full rounded-lg border border-zinc-300 px-3 py-2" />
        {error && <p className="text-sm text-deal">{error}</p>}
        <button disabled={busy} className="w-full rounded-lg bg-brand-600 py-2 font-medium text-white disabled:opacity-60">
          {busy ? 'Sending…' : 'Email me a sign-in code'}
        </button>
      </form>
    </div>
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

/** Offer once a month at most, only on devices with their own unlock, and only to shoppers without a passkey. */
async function shouldOfferPasskey() {
  if (Date.now() - Number(recall(OFFER_DISMISSED) ?? 0) < 30 * 86_400_000) return false;
  try {
    return (await deviceCanCreatePasskey()) && (await listPasskeys()).length === 0;
  } catch {
    return false;
  }
}

function recall(key: string) {
  try {
    return localStorage.getItem(key);
  } catch {
    return null;
  }
}

function remember(key: string, value: string) {
  try {
    localStorage.setItem(key, value);
  } catch {
    // Storage blocked: the offer may show again next time.
  }
}

const KeyIcon = () => (
  <svg aria-hidden="true" viewBox="0 0 24 24" className="size-5" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
    <circle cx="8" cy="15" r="4" />
    <path d="m10.85 12.15 8.65-8.65M17 6l2.5 2.5M14.5 8.5 17 11" />
  </svg>
);
