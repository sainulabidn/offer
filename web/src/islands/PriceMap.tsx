import { Suspense, lazy, useState } from 'react';
import { Map as MapIcon } from 'lucide-react';
import { loadDirectory } from '../lib/plan';
import type { MapPin } from './StoresMap';

// The map library is large, so it loads only when someone asks for the map.
const StoresMap = lazy(() => import('./StoresMap'));

/** Where this pack is on offer: a price on each store, the cheapest highlighted. */
export default function PriceMap({ offers }: { offers: { brochure_id: number; chain: string; price: number }[] }) {
  const [pins, setPins] = useState<MapPin[] | null>(null);
  const [error, setError] = useState('');

  async function open() {
    try {
      const dir = await loadDirectory();
      const lowest = Math.min(...offers.map((offer) => offer.price));
      const branches = new Map(dir.branches.map((branch) => [branch.id, branch]));
      const seen = new Set<number>();
      setPins(
        offers.flatMap((offer) =>
          (dir.brochures.find((brochure) => brochure.id === offer.brochure_id)?.branch_ids ?? []).flatMap((id) => {
            const branch = branches.get(id);
            if (!branch || seen.has(id)) return [];
            seen.add(id);
            return [{ id, lat: branch.lat, lng: branch.lng, text: offer.price.toFixed(2), label: `${offer.chain}, ${branch.name}: AED ${offer.price.toFixed(2)}`, highlight: offer.price === lowest }];
          }),
        ),
      );
    } catch {
      setError('Could not load the map. Check your connection.');
    }
  }

  if (pins?.length) {
    return (
      <Suspense fallback={<div className="skeleton h-[60dvh]" />}>
        <StoresMap pins={pins} />
      </Suspense>
    );
  }

  return (
    <div>
      <button type="button" onClick={open} className="btn btn-quiet"><MapIcon className="size-5" aria-hidden="true" /> See prices on a map</button>
      {error && <p role="status" className="mt-2 text-sm text-save">{error}</p>}
    </div>
  );
}
