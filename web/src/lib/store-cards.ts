import type { Directory, RankedCard, StoreCard } from './near';

/**
 * Store cards from the directory. Kept apart from near.ts, which reads the API in the Worker, so the
 * browser can re-rank the home page's stores from its own copy of the directory.
 */

/** Today in the market's timezone; the UAE has one offset all year. */
export const marketToday = () => new Date(Date.now() + 4 * 3_600_000).toISOString().slice(0, 10);

/** Live offers per branch, from the brochures that cover it. */
function offersByBranch(dir: Directory): Map<number, number> {
  const today = marketToday();
  const counts = new Map<number, number>();
  for (const brochure of dir.brochures) {
    if (brochure.valid_from > today) continue;
    for (const id of brochure.branch_ids) counts.set(id, (counts.get(id) ?? 0) + brochure.offers);
  }
  return counts;
}

/** Every branch with what a store card shows. */
export function storeCards(dir: Directory): StoreCard[] {
  const chains = new Map(dir.chains.map((chain) => [chain.id, chain]));
  const offers = offersByBranch(dir);
  return dir.branches.map((branch) => {
    const chain = chains.get(branch.chain_id);
    return { ...branch, chainId: branch.chain_id, chainSlug: chain?.slug ?? '', groupId: chain?.group_id ?? null, grade: chain?.grade ?? 3, logo: chain?.logo ?? null, offers: offers.get(branch.id) ?? 0 };
  });
}

/** What a home page store tile needs; the island's props are written into the page, so they stay small. */
export interface StoreTile {
  id: number;
  chainId: number;
  chain: string;
  chainSlug: string;
  name: string;
  slug: string;
  logo: string | null;
  offers: number;
  km: number | null;
  score: number;
  selected: boolean;
}

export const storeTile = ({ store, km, score, selected }: RankedCard): StoreTile => ({
  id: store.id,
  chainId: store.chainId,
  chain: store.chain,
  chainSlug: store.chainSlug,
  name: store.name,
  slug: store.slug,
  logo: store.logo,
  offers: store.offers,
  km: km === null ? null : Math.round(km * 10) / 10,
  score: Math.round(score * 1000) / 1000,
  selected,
});
