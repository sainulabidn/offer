import { PUBLIC_API_URL } from 'astro:env/client';

/**
 * Browser-side calls to /api/v1 with the Sanctum session cookie.
 * Guests make no API calls: a local flag remembers whether this browser has signed in.
 */
const SIGNED_IN = 'offer:signed-in';

export class ApiError extends Error {
  constructor(public status: number, public errors: Record<string, string[]> = {}) {
    super(Object.values(errors)[0]?.[0] ?? `Request failed (${status})`);
  }
}

function xsrfToken(): string {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
  return match ? decodeURIComponent(match[1]) : '';
}

let csrfReady: Promise<void> | null = null;
const ensureCsrf = () =>
  (csrfReady ??= fetch(`${PUBLIC_API_URL}/sanctum/csrf-cookie`, { credentials: 'include' }).then(() => undefined));

export async function call<T>(method: 'GET' | 'POST', path: string, body?: unknown): Promise<T> {
  if (method !== 'GET') await ensureCsrf();
  const response = await fetch(`${PUBLIC_API_URL}/api/v1/${path}`, {
    method,
    credentials: 'include',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  if (response.status === 401) markSignedOut();
  if (!response.ok) {
    const data = await response.json().catch(() => ({}));
    throw new ApiError(response.status, data.errors ?? (data.message ? { message: [data.message] } : {}));
  }
  return (await response.json()) as T;
}

const storage = {
  get: (key: string) => {
    try {
      return localStorage.getItem(key);
    } catch {
      return null;
    }
  },
  set: (key: string, value: string | null) => {
    try {
      value === null ? localStorage.removeItem(key) : localStorage.setItem(key, value);
    } catch {
      // Storage blocked (private mode): the session cookie still works for this tab.
    }
  },
};

export const isSignedIn = () => storage.get(SIGNED_IN) === '1';
export const markSignedIn = () => storage.set(SIGNED_IN, '1');
export function markSignedOut() {
  storage.set(SIGNED_IN, null);
  likesCache = null;
}

export function signInUrl() {
  return `/account/sign-in?next=${encodeURIComponent(location.pathname + location.search)}`;
}

type LikeSets = { product: Set<number>; offer: Set<number> };
let likesCache: Promise<LikeSets> | null = null;

/** One request per page for signed-in shoppers, shared by every like button on it. */
export function myLikes(): Promise<LikeSets> {
  if (!isSignedIn()) return Promise.resolve({ product: new Set(), offer: new Set() });
  return (likesCache ??= call<{ product: number[]; offer: number[] }>('GET', 'likes')
    .then((l) => ({ product: new Set(l.product), offer: new Set(l.offer) }))
    .catch(() => ({ product: new Set<number>(), offer: new Set<number>() })));
}
