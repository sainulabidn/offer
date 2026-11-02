import { useEffect, useState } from 'react';
import { PUBLIC_API_URL } from 'astro:env/client';
import { call, isSignedIn, markSignedOut, signInUrl } from '../lib/account';
import { addPasskey, listPasskeys, passkeyError, passkeysSupported, removePasskey, type PasskeyRow } from '../lib/passkeys';
import { deleteAllPlaces, forgetThisDevice, keepActivityOnAccount, loadPlaces } from '../lib/places';
import { accountKeepsActivity, consent, forgetActivity, setConsent } from '../lib/profile';

interface AlertRow {
  id: number;
  name: string;
  max_unit_price: number | null;
  place: string | null;
  radius_km: number | null;
  last_notified_at: string | null;
}

/** Who am I, passkeys, my alerts and my data. Guests see the sign-in prompt and their data choices. */
export default function AccountPanel() {
  const [signedIn, setSignedIn] = useState<boolean | null>(null);
  const [user, setUser] = useState<{ name: string; email: string } | null>(null);
  const [alerts, setAlerts] = useState<AlertRow[]>([]);

  useEffect(() => {
    const known = isSignedIn();
    setSignedIn(known);
    if (!known) return;
    call<{ user: { name: string; email: string } }>('GET', 'me').then((r) => setUser(r.user)).catch(() => (location.href = signInUrl()));
    call<AlertRow[]>('GET', 'alerts').then(setAlerts).catch(() => {});
  }, []);

  async function remove(id: number) {
    await call('DELETE', `alerts/${id}`).catch(() => null);
    setAlerts(alerts.filter((a) => a.id !== id));
  }

  async function signOut() {
    await call('POST', 'auth/logout').catch(() => null);
    markSignedOut();
    forgetThisDevice();
    location.href = '/';
  }

  if (signedIn === false) {
    return (
      <div className="space-y-6">
        <section className="card p-4">
          <h2 className="text-lg font-semibold">Sign in</h2>
          <p className="mt-1 text-sm text-muted">Keep your plans and places on all your devices, and get an email when a price drops.</p>
          <a href={signInUrl()} className="btn btn-primary mt-3">Sign in</a>
        </section>
        <MyData />
      </div>
    );
  }

  if (!user) return <p className="text-muted">Loading…</p>;

  return (
    <div className="space-y-6">
      <p>Signed in as <strong>{user.email}</strong>. <button type="button" onClick={signOut} className="text-link underline">Sign out</button></p>
      {passkeysSupported() && <Passkeys />}
      <section>
        <h2 className="mb-2 text-lg font-semibold">My alerts</h2>
        {alerts.length === 0 ? (
          <p className="text-sm text-muted">No alerts yet. Use “Alert me” on any product. Products you like are watched too.</p>
        ) : (
          <ul className="divide-y divide-line/70 card text-sm">
            {alerts.map((a) => (
              <li key={a.id} className="flex items-center gap-3 p-3">
                <div className="flex-1">
                  <p className="font-medium">{a.name}</p>
                  <p className="text-xs text-muted">
                    {a.max_unit_price ? `under AED ${a.max_unit_price}` : 'any price'}
                    {a.place && ` · within ${a.radius_km} km of ${a.place}`}
                    {a.last_notified_at && ` · last email ${new Date(a.last_notified_at).toLocaleDateString()}`}
                  </p>
                </div>
                <button type="button" onClick={() => remove(a.id)} className="text-xs text-save underline">Delete</button>
              </li>
            ))}
          </ul>
        )}
      </section>
      <MyData />
    </div>
  );
}

/** The shopper's own data: personal deals on or off, forget activity, delete places, download everything. */
function MyData() {
  const [on, setOn] = useState(false);
  const [places, setPlaces] = useState(0);
  const [note, setNote] = useState('');

  useEffect(() => {
    setOn(consent() === true);
    setPlaces(loadPlaces().length);
  }, []);

  async function toggle(next: boolean) {
    setOn(next);
    setConsent(next);
    if (!next && isSignedIn() && accountKeepsActivity()) await keepActivityOnAccount(false);
    setNote(next ? 'Personal deals are on.' : 'Personal deals are off, and your activity was deleted.');
  }

  async function forget() {
    forgetActivity();
    if (isSignedIn()) await call('DELETE', 'me/activity').catch(() => null);
    setNote('Your activity was deleted.');
  }

  async function removePlaces() {
    await deleteAllPlaces();
    setPlaces(0);
    setNote('Your saved places were deleted.');
  }

  return (
    <section>
      <h2 className="mb-2 text-lg font-semibold">My data</h2>
      <div className="card space-y-3 p-4 text-sm">
        <label className="flex min-h-11 items-center gap-3">
          <input type="checkbox" checked={on} onChange={(e) => toggle(e.target.checked)} className="size-5 accent-[var(--color-primary)]" />
          <span>
            <span className="block font-semibold">Personal deals</span>
            <span className="block text-muted">Use what I view, plan and like to order stores and show “For you”.</span>
          </span>
        </label>
        <div className="flex flex-wrap gap-2">
          <button type="button" onClick={forget} className="btn btn-quiet">Forget my activity</button>
          <button type="button" onClick={removePlaces} disabled={places === 0} className="btn btn-quiet">Delete my places</button>
          {isSignedIn() && <a href={`${PUBLIC_API_URL}/api/v1/me/export`} className="btn btn-quiet">Download my data</a>}
        </div>
        {note && <p role="status" className="text-muted">{note}</p>}
      </div>
    </section>
  );
}

/** Passkeys on this account: sign in with fingerprint, face or screen lock instead of an email code. */
function Passkeys() {
  const [passkeys, setPasskeys] = useState<PasskeyRow[] | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const load = () => listPasskeys().then(setPasskeys).catch(() => setPasskeys([]));
  useEffect(() => {
    load();
  }, []);

  async function add() {
    setBusy(true);
    setError('');
    try {
      await addPasskey();
      await load();
    } catch (e) {
      setError(passkeyError(e) ?? '');
    } finally {
      setBusy(false);
    }
  }

  async function remove(passkey: PasskeyRow) {
    if (!confirm(`Remove “${passkey.name}”? You can still sign in with an email code.`)) return;
    await removePasskey(passkey.id).catch(() => null);
    setPasskeys((list) => list?.filter((p) => p.id !== passkey.id) ?? null);
  }

  const date = (iso: string) => new Date(iso).toLocaleDateString();

  return (
    <section>
      <h2 className="mb-1 text-lg font-semibold">Passkeys</h2>
      <p className="mb-2 text-sm text-muted">Sign in with your fingerprint, face or screen lock. Nothing to type, nothing to forget.</p>
      {passkeys && passkeys.length > 0 && (
        <ul className="mb-3 divide-y divide-line/70 card text-sm">
          {passkeys.map((p) => (
            <li key={p.id} className="flex items-center gap-3 p-3">
              <div className="flex-1">
                <p className="font-medium">{p.name}</p>
                <p className="text-xs text-muted">
                  {p.authenticator && `${p.authenticator} · `}added {date(p.created_at)}
                  {p.last_used_at ? ` · last used ${date(p.last_used_at)}` : ' · not used yet'}
                </p>
              </div>
              <button type="button" onClick={() => remove(p)} className="text-xs text-save underline">Remove</button>
            </li>
          ))}
        </ul>
      )}
      {error && <p className="mb-2 text-sm text-save">{error}</p>}
      <button type="button" disabled={busy || passkeys === null} onClick={add} className="btn btn-quiet">
        {busy ? 'Waiting for your device…' : passkeys?.length ? 'Add another passkey' : 'Create a passkey'}
      </button>
    </section>
  );
}
