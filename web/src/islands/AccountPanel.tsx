import { useEffect, useState } from 'react';
import { call, isSignedIn, markSignedOut, signInUrl } from '../lib/account';

interface AlertRow {
  id: number;
  name: string;
  max_unit_price: number | null;
  place: string | null;
  radius_km: number | null;
  last_notified_at: string | null;
}

/** Signed-in shopper: who am I, my alerts (with delete), sign out. */
export default function AccountPanel() {
  const [user, setUser] = useState<{ name: string; email: string } | null>(null);
  const [alerts, setAlerts] = useState<AlertRow[]>([]);

  useEffect(() => {
    if (!isSignedIn()) {
      location.href = signInUrl();
      return;
    }
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
    location.href = '/';
  }

  if (!user) return <p className="text-zinc-500">Loading…</p>;

  return (
    <div className="space-y-6">
      <p>Signed in as <strong>{user.email}</strong>. <button type="button" onClick={signOut} className="text-brand-700 underline">Sign out</button></p>
      <section>
        <h2 className="mb-2 text-lg font-semibold">My alerts</h2>
        {alerts.length === 0 ? (
          <p className="text-sm text-zinc-500">No alerts yet. Use “Alert me” on any product. Products you like are watched too.</p>
        ) : (
          <ul className="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white text-sm">
            {alerts.map((a) => (
              <li key={a.id} className="flex items-center gap-3 p-3">
                <div className="flex-1">
                  <p className="font-medium">{a.name}</p>
                  <p className="text-xs text-zinc-500">
                    {a.max_unit_price ? `under AED ${a.max_unit_price}` : 'any price'}
                    {a.place && ` · within ${a.radius_km} km of ${a.place}`}
                    {a.last_notified_at && ` · last email ${new Date(a.last_notified_at).toLocaleDateString()}`}
                  </p>
                </div>
                <button type="button" onClick={() => remove(a.id)} className="text-xs text-deal underline">Delete</button>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
