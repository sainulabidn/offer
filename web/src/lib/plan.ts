import { PUBLIC_API_URL } from 'astro:env/client';
import type { CandidateOffer, PlanItem, Store } from '@offer/basket';
import { ApiError, call, isSignedIn } from './account';

/**
 * Browser-side plan data: the plan lives in localStorage (no account needed), offers come straight
 * from Typesense with the public scoped key, stores from the cached directory. No Laravel round trips.
 */
export interface PlanSettings {
  lat: number | null;
  lng: number | null;
  place: string;
  radiusKm: number;
  maxStores: 1 | 2 | 3;
  extraStoreCost: number;
}

export interface Plan {
  /** Made on the device, so a plan keeps its id when it first reaches the account. */
  id: string;
  name: string;
  items: PlanItem[];
  settings: PlanSettings;
  /** ISO time of the last local change; the account keeps the newest version. */
  updatedAt: string;
}

const KEY = 'offer:plan';

const newId = () => (crypto.randomUUID ? crypto.randomUUID() : `${Date.now().toString(16)}-0000-4000-8000-${Math.random().toString(16).slice(2, 14).padEnd(12, '0')}`);

export function loadPlan(): Plan {
  const fallback: Plan = { id: newId(), name: 'My plan', items: [], settings: { lat: null, lng: null, place: '', radiusKm: 10, maxStores: 2, extraStoreCost: 10 }, updatedAt: new Date(0).toISOString() };
  try {
    const saved = JSON.parse(localStorage.getItem(KEY) ?? 'null') as Partial<Plan> | null;
    const near = JSON.parse(localStorage.getItem('offer:near') ?? 'null') as { lat: number; lng: number; place: string } | null;
    // Plans saved before accounts sync had no id/name/updatedAt.
    const plan: Plan = { ...fallback, ...(saved ?? {}), settings: { ...fallback.settings, ...(saved?.settings ?? {}) } };
    if (!saved?.id) storeLocally(plan);
    if (plan.settings.lat === null && near) Object.assign(plan.settings, { lat: near.lat, lng: near.lng, place: near.place });
    return plan;
  } catch {
    return fallback;
  }
}

function storeLocally(plan: Plan) {
  try {
    localStorage.setItem(KEY, JSON.stringify(plan));
    window.dispatchEvent(new CustomEvent('offer:plan-changed', { detail: plan.items.length }));
  } catch {
    // Storage blocked: the plan lives for this tab only.
  }
}

let pushTimer: ReturnType<typeof setTimeout> | undefined;

/** Saves on this device now and, when signed in, to the account a moment later (one request per burst of edits). */
export function savePlan(plan: Plan, onConflict?: (server: Plan) => void) {
  plan.updatedAt = new Date().toISOString();
  storeLocally(plan);
  if (!isSignedIn()) return;
  clearTimeout(pushTimer);
  pushTimer = setTimeout(() => pushPlan(plan).then((server) => server && onConflict?.(server)), 800);
}

interface ServerPlan {
  id: string;
  name: string;
  items: PlanItem[];
  settings: Partial<PlanSettings>;
  updated_at: string;
  is_owner: boolean;
  members: number;
}

const fromServer = (p: ServerPlan, local: Plan): Plan => ({
  id: p.id,
  name: p.name,
  items: p.items,
  settings: { ...local.settings, ...p.settings },
  updatedAt: p.updated_at,
});

/** Returns the account's newer copy when it wins a conflict. */
async function pushPlan(plan: Plan): Promise<Plan | null> {
  try {
    await call('PUT', `plans/${plan.id}`, { name: plan.name, items: plan.items, settings: plan.settings, updated_at: plan.updatedAt });
    return null;
  } catch (e) {
    if (e instanceof ApiError && e.status === 409) {
      const server = fromServer((e.body as { plan: ServerPlan }).plan, plan);
      storeLocally(server);
      return server;
    }
    return null; // offline or server trouble: the next save retries
  }
}

/**
 * On opening the plan page while signed in: take the account's copy when it is newer (edited on
 * another device or by family), otherwise push this device's copy. Also lists the shopper's plans.
 */
export async function syncPlan(local: Plan): Promise<{ plan: Plan; plans: ServerPlan[] }> {
  if (!isSignedIn()) return { plan: local, plans: [] };
  try {
    const plans = await call<ServerPlan[]>('GET', 'plans');
    const server = plans.find((p) => p.id === local.id) ?? (local.items.length === 0 ? plans[0] : undefined);
    if (server && server.updated_at > local.updatedAt) {
      const adopted = fromServer(server, local);
      storeLocally(adopted);
      return { plan: adopted, plans };
    }
    if (local.items.length > 0) await pushPlan(local);
    return { plan: local, plans };
  } catch {
    return { plan: local, plans: [] };
  }
}

export async function switchPlan(id: string, local: Plan): Promise<Plan> {
  const plans = await call<ServerPlan[]>('GET', 'plans');
  const target = plans.find((p) => p.id === id);
  if (!target) return local;
  const plan = fromServer(target, local);
  storeLocally(plan);
  return plan;
}

export async function sharePlan(plan: Plan): Promise<string> {
  await pushPlan(plan);
  const { token } = await call<{ token: string }>('POST', `plans/${plan.id}/share`);
  return `${location.origin}/plan/shared/${token}`;
}

export function startNewPlan(): Plan {
  const current = loadPlan();
  const plan: Plan = { ...current, id: newId(), name: 'My plan', items: [], updatedAt: new Date().toISOString() };
  storeLocally(plan);
  return plan;
}

export type { ServerPlan };

export function addToPlan(item: PlanItem) {
  const plan = loadPlan();
  const existing = plan.items.find((i) => i.key === item.key);
  if (existing) existing.quantity += item.quantity;
  else plan.items.push(item);
  savePlan(plan);
}

interface Directory {
  branches: { id: number; chain_id: number; chain: string; name: string; lat: number; lng: number }[];
  brochures: { id: number; chain_id: number; valid_from: string; valid_to: string; branch_ids: number[] }[];
  areas: { slug: string; name: string; lat: number; lng: number }[];
}

let directoryCache: Promise<Directory> | null = null;
export const loadDirectory = () =>
  (directoryCache ??= fetch(`${PUBLIC_API_URL}/api/v1/directory`).then((r) => r.json() as Promise<Directory>));

let searchCache: Promise<{ url: string; collection: string; key: string }> | null = null;
const searchConfig = () => (searchCache ??= fetch(`${PUBLIC_API_URL}/api/v1/search-config`).then((r) => r.json()));

export interface Hit {
  id: string;
  product_name: string;
  variant_id: number;
  variant_label: string;
  product_group_id: number;
  product_group: string;
  brand?: string;
  chain_id: number;
  brochure_id: number;
  price: number;
  regular_price?: number;
  effective_unit_price?: number;
  unit_label?: string;
  promo_qty?: number;
  promo_total?: number;
  promo_buy?: number;
  promo_get?: number;
  max_per_customer?: number;
}

export async function searchHits(params: Record<string, string>): Promise<Hit[]> {
  const { url, collection, key } = await searchConfig();
  const query = new URLSearchParams({ query_by: 'product_name,brand,product_group,variant_label', per_page: '250', ...params });
  const response = await fetch(`${url}/collections/${collection}/documents/search?${query}`, { headers: { 'X-TYPESENSE-API-KEY': key } });
  if (!response.ok) throw new Error(`Search failed (${response.status})`);
  return ((await response.json()) as { hits: { document: Hit }[] }).hits.map((h) => h.document);
}

const rad = Math.PI / 180;
function km(a: { lat: number; lng: number }, b: { lat: number; lng: number }) {
  const h = Math.sin(((b.lat - a.lat) * rad) / 2) ** 2 + Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(((b.lng - a.lng) * rad) / 2) ** 2;
  return 12_742 * Math.asin(Math.sqrt(h));
}

/** Stores (one per chain, its nearest branch) and the brochures live within the radius. */
export function nearbyStores(dir: Directory, s: PlanSettings): { stores: Store[]; brochureIds: number[] } {
  const today = new Date().toISOString().slice(0, 10);
  const nearest = new Map<number, Store>();
  const branchIds = new Set<number>();
  for (const b of dir.branches) {
    const d = s.lat === null || s.lng === null ? 0 : km({ lat: s.lat, lng: s.lng }, b);
    if (s.lat !== null && d > s.radiusKm) continue;
    branchIds.add(b.id);
    if (!nearest.has(b.chain_id) || d < nearest.get(b.chain_id)!.distanceKm) {
      nearest.set(b.chain_id, { chainId: b.chain_id, chainName: b.chain, distanceKm: d, branch: b.name });
    }
  }
  const brochureIds = dir.brochures
    .filter((b) => b.valid_from <= today && b.valid_to >= today && b.branch_ids.some((id) => branchIds.has(id)))
    .map((b) => b.id);
  return { stores: [...nearest.values()], brochureIds };
}

export function toCandidate(hit: Hit): CandidateOffer {
  return {
    id: hit.id,
    chainId: hit.chain_id,
    variantId: hit.variant_id,
    productGroupId: hit.product_group_id,
    label: `${hit.product_name} · ${hit.variant_label}`,
    price: hit.price,
    regularPrice: hit.regular_price,
    unitPrice: hit.effective_unit_price,
    maxPerCustomer: hit.max_per_customer,
    promo: hit.promo_qty && hit.promo_total
      ? { type: 'multibuy', qty: hit.promo_qty, total: hit.promo_total }
      : hit.promo_buy && hit.promo_get
        ? { type: 'bogo', buy: hit.promo_buy, get: hit.promo_get }
        : undefined,
  };
}
