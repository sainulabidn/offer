import { DEFAULT_ACTIVITY, noteBought, recordEvent, type ActivityEvent, type ActivitySettings, type BuyingRhythm, type Profile } from '@offer/rank';
import { call, isSignedIn } from './account';

/**
 * The shopper's activity, in this browser. Nothing is kept until they agree, it stays on the device
 * for guests, and it reaches the account only when they say so. Page changes respond at once, because
 * the score is updated here first.
 */
const PROFILE = 'offer:activity';
const CONSENT = 'offer:personalize';
const ACCOUNT = 'offer:account-activity';
const PINS = 'offer:pinned';
const SHOWN = 'offer:shown';
const RHYTHM = 'offer:rhythm';
const SETTINGS = 'offer:activity-settings';
const COHORT = 'offer:cohort';
const OUTBOX = 'offer:activity-outbox';

/** One shopper in ten keeps the shared order, to show whether personal ranking really helps. */
const HOLDOUT_SHARE = 0.1;

export function recall<T>(key: string, fallback: T): T {
  try {
    return (JSON.parse(localStorage.getItem(key) ?? 'null') as T) ?? fallback;
  } catch {
    return fallback;
  }
}

export function keep(key: string, value: unknown) {
  try {
    if (value === null) localStorage.removeItem(key);
    else localStorage.setItem(key, JSON.stringify(value));
  } catch {
    // Storage blocked: this device can't remember, and the page falls back to the shared order.
  }
}

/** True or false once the shopper has answered; null until then. */
export const consent = (): boolean | null => recall<boolean | null>(CONSENT, null);
export const accountKeepsActivity = () => recall(ACCOUNT, false);
export const setAccountKeepsActivity = (on: boolean) => keep(ACCOUNT, on);
export const loadProfile = () => recall<Profile>(PROFILE, {});
export const saveProfile = (profile: Profile) => keep(PROFILE, profile);
export const activitySettings = () => recall<ActivitySettings>(SETTINGS, DEFAULT_ACTIVITY);
export const buyingRhythm = () => recall<BuyingRhythm>(RHYTHM, {});
export const pinnedStores = () => recall<number[]>(PINS, []);
export const setPinnedStores = (ids: number[]) => keep(PINS, ids);

/** Turning this off stops recording and deletes what this device kept. */
export function setConsent(on: boolean) {
  keep(CONSENT, on);
  if (!on) forgetActivity();
}

export function forgetActivity() {
  keep(PROFILE, null);
  keep(SHOWN, null);
  keep(OUTBOX, null);
  keep(RHYTHM, null);
}

/** Admin changes to weights and half-lives reach the browser with the store directory. */
export function rememberSettings(settings: { store_half_life_days: number; item_half_life_days: number; event_weights: ActivitySettings['weights'] }) {
  keep(SETTINGS, { storeHalfLife: settings.store_half_life_days, itemHalfLife: settings.item_half_life_days, weights: settings.event_weights });
}

export function inHoldout(): boolean {
  let cohort = recall<string | null>(COHORT, null);
  if (!cohort) {
    cohort = Math.random() < HOLDOUT_SHARE ? 'holdout' : 'personal';
    keep(COHORT, cohort);
  }
  return cohort === 'holdout';
}

export const personalOn = () => consent() === true && !inHoldout();

interface OutboxItem {
  kind: string;
  id: number;
  score: number;
  at: string;
}

let flushTimer: ReturnType<typeof setTimeout> | undefined;

export function flushActivity() {
  clearTimeout(flushTimer);
  const items = recall<OutboxItem[]>(OUTBOX, []);
  if (!items.length || !isSignedIn() || !accountKeepsActivity()) return;
  keep(OUTBOX, null);
  call('POST', 'me/activity', { items: items.slice(0, 500) }).catch(() => null);
}

/** Records an event on targets such as "product:12". Does nothing without the shopper's agreement. */
export function recordActivity(event: ActivityEvent, targets: string[]) {
  if (consent() !== true || !targets.length) return;
  const settings = activitySettings();
  const now = Date.now();
  saveProfile(recordEvent(loadProfile(), event, targets, now, settings));
  if (event === 'plan') keep(RHYTHM, noteBought(buyingRhythm(), targets.filter((target) => target.startsWith('group:')).map((target) => Number(target.slice(6))), now));

  if (isSignedIn() && accountKeepsActivity()) {
    const at = new Date(now).toISOString();
    const added = targets.flatMap((target) => {
      const [kind, id] = target.split(':');
      const weight = settings.weights[event][kind === 'chain' || kind === 'branch' ? 'store' : 'item'];
      return weight ? [{ kind, id: Number(id), score: weight, at }] : [];
    });
    keep(OUTBOX, [...recall<OutboxItem[]>(OUTBOX, []), ...added]);
    clearTimeout(flushTimer);
    flushTimer = setTimeout(flushActivity, 4000);
  }
}

/** The whole device history, for moving to the account once the shopper agrees. */
export function profileItems(): OutboxItem[] {
  return Object.entries(loadProfile()).map(([key, entry]) => {
    const [kind, id] = key.split(':');
    return { kind, id: Number(id), score: entry.s, at: new Date(entry.t).toISOString() };
  });
}

/** Takes the account's scores where they are newer than this device's. */
export function mergeServerActivity(items: { kind: string; id: number; score: number; at: string }[]) {
  const profile = loadProfile();
  for (const { kind, id, score, at } of items) {
    const t = Date.parse(at);
    const key = `${kind}:${id}`;
    if (!profile[key] || profile[key].t < t) profile[key] = { s: score, t };
  }
  saveProfile(profile);
}

export const shownCounts = () => recall<Record<number, number>>(SHOWN, {});

/** How often each product was shown, so items ignored again and again make room for others. */
export function markShown(productIds: number[]) {
  const shown = shownCounts();
  for (const id of productIds) shown[id] = (shown[id] ?? 0) + 1;
  keep(SHOWN, shown);
}
