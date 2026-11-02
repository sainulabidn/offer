import { useEffect, useState } from 'react';
import { isOpen, type OpeningHours } from '@offer/rank';
import { marketNow } from '../lib/format';

/** "Open now" is worked out in the browser: the page around it is cached, so a server's answer would go stale. */
export default function OpenNow({ hours, closedToday }: { hours: OpeningHours[]; closedToday: boolean }) {
  const [open, setOpen] = useState<boolean | null>(null);

  useEffect(() => setOpen(closedToday ? false : isOpen(hours, marketNow())), []);

  if (open === null) return null;
  return <p className={`text-sm font-semibold ${open ? 'text-link' : 'text-save'}`}>{open ? 'Open now' : 'Closed now'}</p>;
}
