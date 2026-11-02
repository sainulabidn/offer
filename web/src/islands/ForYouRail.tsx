import { useEffect, useState } from 'react';
import { X } from 'lucide-react';
import { rerank, scoreOffer, type FeedLimits, type ValueWeights } from '@offer/rank';
import { aed, tintFor } from '../lib/format';
import { forYouCandidates, type ForYouEntry } from '../lib/personal';
import { loadDirectory } from '../lib/plan';
import { activitySettings, loadProfile, markShown, personalOn, recordActivity, rememberSettings } from '../lib/profile';
import { toast } from '../lib/quick-add';

/**
 * What this shopper is most likely to want, with a plain reason on each card. Drawn only in the browser:
 * their activity lives here, not on the server. Shows nothing until there's something to go on, so
 * first-time shoppers see no gap.
 */
export default function ForYouRail({ radiusFilter }: { radiusFilter?: string }) {
  const [entries, setEntries] = useState<ForYouEntry[]>([]);

  useEffect(() => {
    if (!personalOn()) return;
    (async () => {
      const [dir, candidates] = await Promise.all([loadDirectory(), forYouCandidates(radiusFilter)]);
      const settings = dir.settings as unknown as { value_weights: ValueWeights; feed: { slots: number; max_per_group: number; max_per_chain: number; discovery_slots: number } };
      const limits: FeedLimits = { slots: settings.feed.slots, maxPerGroup: settings.feed.max_per_group, maxPerChain: settings.feed.max_per_chain, discoverySlots: settings.feed.discovery_slots };
      rememberSettings(dir.settings as never);
      const profile = loadProfile();
      const now = Date.now();
      const picked = rerank(candidates.map((item) => scoreOffer(item, profile, settings.value_weights, now, activitySettings())), limits);
      setEntries(picked);
      markShown(picked.map((entry) => entry.item.productId));
    })().catch(() => {});
  }, []);

  function notInterested(entry: ForYouEntry) {
    const { hit } = entry.item;
    recordActivity('not_interested', [`product:${hit.product_id}`, `group:${hit.product_group_id}`]);
    setEntries(entries.filter((other) => other !== entry));
    toast(`We'll show less of ${hit.product_name}`);
  }

  if (!entries.length) return null;

  return (
    <section className="mt-5" aria-labelledby="for-you-title">
      <h2 id="for-you-title" className="mb-2 text-xl font-bold">For you</h2>
      <ul className="rail">
        {entries.map((entry) => {
          const { hit } = entry.item;
          return (
            <li key={hit.id} className="card relative w-40 overflow-hidden">
              <div className={`grid h-24 place-items-center ${hit.image ? 'bg-white' : tintFor(String(hit.product_group_id))}`}>
                {hit.image
                  ? <img src={`${hit.image}-320.webp`} alt="" loading="lazy" className="size-full object-contain p-1" />
                  : <span aria-hidden="true" className="text-[2.5rem] leading-none">{hit.icon ?? hit.product_name.charAt(0)}</span>}
              </div>
              <button type="button" aria-label={`Not interested in ${hit.product_name}`} onClick={() => notInterested(entry)} className="absolute end-1.5 top-1.5 z-10 grid size-8 place-items-center rounded-full bg-white/90 text-muted shadow-sm">
                <X className="size-4" aria-hidden="true" />
              </button>
              <div className="p-2.5">
                <p className="text-xs font-semibold text-link">{entry.reason}</p>
                <a href={`/products/${hit.product_slug}?variant=${encodeURIComponent(hit.variant_slug)}`} data-click={`product:${hit.product_id} chain:${hit.chain_id}`} className="mt-0.5 line-clamp-2 text-sm/snug font-semibold after:absolute after:inset-0">{hit.product_name}</a>
                <p className="mt-1 font-bold tabular-nums">{aed(hit.price)}</p>
                <p className="truncate text-xs text-muted">{hit.chain_name}</p>
              </div>
            </li>
          );
        })}
      </ul>
    </section>
  );
}
