import { useEffect, useRef, useState } from 'react';
import { Check } from 'lucide-react';
import { isSignedIn } from '../lib/account';
import { loadDirectory, searchConfig } from '../lib/plan';
import { readNotice, clearNotice, keepActivityOnAccount, syncPins, type Notice } from '../lib/places';
import { readPrefs } from '../lib/prefs';
import { accountKeepsActivity, consent, loadProfile, pinnedStores, recall, keep, recordActivity, rememberSettings, setConsent, setPinnedStores } from '../lib/profile';
import { rankStores, distanceKm } from '@offer/rank';

const ACCOUNT_ASKED = 'offer:account-asked';

interface Staple {
  group: number;
  name: string;
  icon?: string;
  category?: number;
}

/**
 * The quiet questions, one at a time and never blocking the page: may we use your activity, may we keep
 * it on your account, and short notes such as an automatic default place. Saying yes offers a three-tap
 * start: usual stores, then staples, so the first page is useful without weeks of clicks.
 */
export default function WelcomeBar() {
  const sheet = useRef<HTMLDialogElement>(null);
  const [question, setQuestion] = useState<'consent' | 'account' | null>(null);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [step, setStep] = useState<1 | 2>(1);
  const [stores, setStores] = useState<{ id: number; chainId: number; chain: string }[]>([]);
  const [staples, setStaples] = useState<Staple[]>([]);
  const [pickedStores, setPickedStores] = useState<number[]>([]);
  const [pickedStaples, setPickedStaples] = useState<number[]>([]);

  useEffect(() => {
    if (consent() === null) setQuestion('consent');
    else if (consent() && isSignedIn() && !accountKeepsActivity() && !recall(ACCOUNT_ASKED, false) && Object.keys(loadProfile()).length) setQuestion('account');
    else {
      setNotice(readNotice());
      clearNotice();
    }
  }, []);

  async function agree() {
    setConsent(true);
    setQuestion(null);
    if (Object.keys(loadProfile()).length) return;

    const dir = await loadDirectory();
    rememberSettings(dir.settings as never);
    const chains = new Map(dir.chains.map((chain) => [chain.id, chain]));
    const { at } = readPrefs();
    const nearby = dir.branches
      .map((branch) => ({ ...branch, chainId: branch.chain_id, grade: chains.get(branch.chain_id)?.grade ?? 3, offers: 1 }))
      .filter((branch) => !at || distanceKm(at, branch) < 30);
    setStores(rankStores(nearby, { at, radiusKm: 30, weights: { activity: 0, near: 0.6, grade: 0.4, offers: 0 } }).slice(0, 8).map(({ store }) => store));

    const { url, collection, key } = await searchConfig();
    const params = new URLSearchParams({ q: '*', query_by: 'product_name', filter_by: 'item_grade:=1', group_by: 'product_group_id', group_limit: '1', per_page: '16', sort_by: 'product_rank:desc', include_fields: 'product_group,product_group_id,icon,category_ids' });
    const body = (await (await fetch(`${url}/collections/${collection}/documents/search?${params}`, { headers: { 'X-TYPESENSE-API-KEY': key } })).json()) as { grouped_hits?: { hits: { document: { product_group: string; product_group_id: number; icon?: string; category_ids: number[] } }[] }[] };
    setStaples((body.grouped_hits ?? []).map((group) => {
      const hit = group.hits[0].document;
      return { group: hit.product_group_id, name: hit.product_group, icon: hit.icon, category: hit.category_ids.at(-1) };
    }));
    setStep(1);
    sheet.current?.showModal();
  }

  function finish() {
    pickedStores.forEach((id) => {
      const store = stores.find((candidate) => candidate.id === id);
      if (store) recordActivity('directions', [`branch:${id}`, `chain:${store.chainId}`]);
    });
    if (pickedStores.length) {
      setPinnedStores([...new Set([...pinnedStores(), ...pickedStores])]);
      syncPins();
    }
    pickedStaples.forEach((group) => {
      const staple = staples.find((candidate) => candidate.group === group);
      recordActivity('plan', [`group:${group}`, ...(staple?.category ? [`category:${staple.category}`] : [])]);
    });
    sheet.current?.close();
    location.reload();
  }

  const toggle = (list: number[], id: number) => (list.includes(id) ? list.filter((other) => other !== id) : [...list, id]);

  return (
    <>
      {(question || notice) && (
        <div role="region" aria-label="A quick question" className="fixed inset-x-3 bottom-[5.25rem] z-30 mx-auto max-w-md rounded-2xl border border-line bg-surface p-4 shadow-float md:bottom-4">
          {question === 'consent' && (
            <>
              <p className="text-sm font-semibold">Show deals that fit you?</p>
              <p className="mt-1 text-sm text-muted">We'd use your activity to show the stores and items you need. It stays on this device, and you can turn it off or clear it any time in Account.</p>
              <div className="mt-3 flex gap-2">
                <button type="button" onClick={agree} className="btn btn-primary flex-1">Yes, please</button>
                <button type="button" onClick={() => { setConsent(false); setQuestion(null); }} className="btn btn-quiet flex-1">No thanks</button>
              </div>
            </>
          )}
          {question === 'account' && (
            <>
              <p className="text-sm font-semibold">Keep your activity on your account?</p>
              <p className="mt-1 text-sm text-muted">Then "For you" follows you to your other devices. You can delete it any time in Account.</p>
              <div className="mt-3 flex gap-2">
                <button type="button" onClick={() => { keep(ACCOUNT_ASKED, true); setQuestion(null); keepActivityOnAccount(true); }} className="btn btn-primary flex-1">Yes, keep it</button>
                <button type="button" onClick={() => { keep(ACCOUNT_ASKED, true); setQuestion(null); }} className="btn btn-quiet flex-1">This device only</button>
              </div>
            </>
          )}
          {!question && notice && (
            <div className="flex items-start gap-3">
              <p className="flex-1 text-sm">{notice.text}</p>
              {notice.action && <button type="button" data-open-location onClick={() => setNotice(null)} className="text-sm font-semibold text-link">{notice.action}</button>}
              <button type="button" onClick={() => setNotice(null)} className="text-sm text-muted">Dismiss</button>
            </div>
          )}
        </div>
      )}

      <dialog ref={sheet} className="sheet" aria-labelledby="start-title">
        <div className="space-y-4 p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))]">
          <div>
            <p className="text-sm text-muted">Step {step} of 2</p>
            <h2 id="start-title" className="text-xl font-bold">{step === 1 ? 'Where do you usually shop?' : 'What do you buy most?'}</h2>
          </div>
          <div className="flex flex-wrap gap-2">
            {step === 1
              ? stores.map((store) => {
                  const on = pickedStores.includes(store.id);
                  return <button key={store.id} type="button" aria-pressed={on} onClick={() => setPickedStores(toggle(pickedStores, store.id))} className={`chip ${on ? 'chip-on' : ''}`}>{on && <Check className="size-4" aria-hidden="true" />}{store.chain}</button>;
                })
              : staples.map((staple) => {
                  const on = pickedStaples.includes(staple.group);
                  return <button key={staple.group} type="button" aria-pressed={on} onClick={() => setPickedStaples(toggle(pickedStaples, staple.group))} className={`chip ${on ? 'chip-on' : ''}`}><span aria-hidden="true">{staple.icon}</span> {staple.name}</button>;
                })}
          </div>
          <div className="flex gap-2">
            <button type="button" onClick={() => (step === 1 ? setStep(2) : finish())} className="btn btn-primary flex-1">{step === 1 ? 'Next' : 'Done'}</button>
            <button type="button" onClick={() => sheet.current?.close()} className="btn btn-quiet">Skip</button>
          </div>
        </div>
      </dialog>
    </>
  );
}
