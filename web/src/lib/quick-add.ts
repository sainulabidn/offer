import type { PlanItem } from '@offer/basket';
import { addToPlan } from './plan';
import { count } from './track';

/**
 * Add to plan from an offer card, and the plan count in the menus. One listener for the whole page,
 * so a page of cards doesn't need a React island each.
 */
const PLAN_KEY = 'offer:plan';

/** A short message at the bottom of the screen. */
export function toast(message: string) {
  const host = document.getElementById('toast');
  if (!host) return;
  const note = document.createElement('p');
  note.textContent = message;
  note.className = 'rounded-full bg-ink px-4 py-2.5 text-sm font-semibold text-canvas shadow-float';
  host.replaceChildren(note);
  setTimeout(() => note.remove(), 2500);
}

function showCount(items: number) {
  document.querySelectorAll<HTMLElement>('[data-plan-count]').forEach((badge) => {
    badge.textContent = String(items);
    badge.classList.toggle('hidden', items === 0);
    badge.classList.toggle('inline-block', items > 0);
  });
}

function savedCount(): number {
  try {
    return (JSON.parse(localStorage.getItem(PLAN_KEY) ?? 'null') as { items?: unknown[] } | null)?.items?.length ?? 0;
  } catch {
    return 0;
  }
}

/** Runs once per page, from layouts/Base.astro. */
export function startPlanBadge() {
  showCount(savedCount());
  window.addEventListener('offer:plan-changed', (e) => showCount((e as CustomEvent<number>).detail));

  document.addEventListener('click', (e) => {
    const button = (e.target as Element).closest<HTMLElement>('[data-add-plan]');
    if (!button) return;
    const { variant, label, product, group } = button.dataset;
    const item: PlanItem = { key: `v:${variant}`, name: label ?? '', quantity: 1, match: { variantId: Number(variant) } };
    addToPlan(item);
    count('plan', `product:${product} group:${group}`);
    button.dataset.added = '';
    toast(`Added to your plan: ${label}`);
  });
}
