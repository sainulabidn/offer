import { PUBLIC_API_URL } from 'astro:env/client';

/**
 * Anonymous counts for "most viewed": no account, id or cookie, just "this product was viewed".
 * Pages mark what they show with data-views, and links with data-click or data-directions, each
 * holding targets such as "product:12 group:3". Counts wait in this tab and leave in small batches,
 * and each thing counts once per tab, so one person browsing can't inflate it.
 */
type CountType = 'view' | 'click' | 'plan' | 'directions';

interface CountEvent {
  kind: string;
  id: number;
  type: CountType;
}

interface Queue {
  events: CountEvent[];
  /** When the oldest waiting event was added. */
  since: number;
}

const QUEUE = 'offer:count-queue';
const SEEN = 'offer:counted';
const BATCH = 10; // send once this many are waiting…
const MAX_WAIT = 30_000; // …or the oldest has waited this long
const MAX_SEND = 50; // what POST /api/v1/events accepts

function read<T>(key: string, fallback: T): T {
  try {
    return (JSON.parse(sessionStorage.getItem(key) ?? 'null') as T) ?? fallback;
  } catch {
    return fallback;
  }
}

function write(key: string, value: unknown) {
  try {
    sessionStorage.setItem(key, JSON.stringify(value));
  } catch {
    // Storage blocked: this count is lost, and nothing else depends on it.
  }
}

function send() {
  const { events } = read<Queue>(QUEUE, { events: [], since: 0 });
  if (!events.length) return;
  // text/plain needs no CORS preflight, and sendBeacon survives the page closing.
  const body = new Blob([JSON.stringify({ events: events.slice(0, MAX_SEND) })], { type: 'text/plain' });
  if (navigator.sendBeacon(`${PUBLIC_API_URL}/api/v1/events`, body)) {
    write(QUEUE, { events: events.slice(MAX_SEND), since: Date.now() });
  }
}

function sendIfDue() {
  const queue = read<Queue>(QUEUE, { events: [], since: 0 });
  if (queue.events.length >= BATCH || (queue.events.length > 0 && Date.now() - queue.since >= MAX_WAIT)) send();
}

/** Counts targets such as "product:12 group:3". */
export function count(type: CountType, targets: string) {
  const seen = new Set(read<string[]>(SEEN, []));
  const queue = read<Queue>(QUEUE, { events: [], since: 0 });
  const added = [];

  for (const target of targets.split(/\s+/)) {
    const [kind, id] = target.split(':');
    const key = `${type}:${target}`;
    if (!kind || !Number(id) || seen.has(key)) continue;
    seen.add(key);
    added.push({ kind, id: Number(id), type });
  }
  if (!added.length) return;

  write(SEEN, [...seen]);
  write(QUEUE, { events: [...queue.events, ...added], since: queue.events.length ? queue.since : Date.now() });
  sendIfDue();
}

/** Page views, and clicks anywhere on the page. Runs once per page, from layouts/Base.astro. */
export function startCounting() {
  document.querySelectorAll<HTMLElement>('[data-views]').forEach((el) => count('view', el.dataset.views ?? ''));

  document.addEventListener('click', (e) => {
    const el = (e.target as Element).closest<HTMLElement>('[data-click],[data-directions]');
    if (el?.dataset.click) count('click', el.dataset.click);
    if (el?.dataset.directions) count('directions', el.dataset.directions);
  });

  // Leaving the page is the last chance to send; on a phone it may never come back.
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') sendIfDue();
  });
}
