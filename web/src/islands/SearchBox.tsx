import { useEffect, useId, useRef, useState } from 'react';
import { clearRecentSearches, recentSearches, rememberSearch, suggest, type Suggestions } from '../lib/suggest';

const aed = (n: number) => `AED ${n.toFixed(2)}`;

interface Option {
  id: string;
  href: string;
  label: string;
  render: () => React.ReactNode;
}

/**
 * Header search with suggestions as you type (typo-tolerant), keyboard navigation and recent
 * searches. Renders as a normal GET form, so it also works before or without JavaScript.
 */
export default function SearchBox({ initial = '' }: { initial?: string }) {
  const [query, setQuery] = useState(initial);
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(-1);
  const [result, setResult] = useState<Suggestions | null>(null);
  const [recent, setRecent] = useState<string[]>([]);
  const listId = useId();
  const box = useRef<HTMLFormElement>(null);

  useEffect(() => {
    const q = query.trim();
    if (q.length < 2) {
      setResult(null);
      return;
    }
    const controller = new AbortController();
    const timer = setTimeout(() => {
      suggest(q, controller.signal).then(setResult).catch(() => {});
    }, 120);
    return () => {
      clearTimeout(timer);
      controller.abort();
    };
  }, [query]);

  // Close when clicking elsewhere.
  useEffect(() => {
    const close = (e: MouseEvent) => !box.current?.contains(e.target as Node) && setOpen(false);
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, []);

  const q = query.trim();
  const searchUrl = (params: Record<string, string>) => `/search?${new URLSearchParams(params)}`;
  const options: Option[] = [];

  if (q.length >= 2) {
    options.push({ id: 'all', href: searchUrl({ q }), label: q, render: () => <>Search for <strong>“{q}”</strong>{result ? <span className="text-zinc-500"> · {result.found} offers</span> : null}</> });
    for (const p of result?.products ?? []) {
      options.push({
        id: `p:${p.href}`,
        href: p.href,
        label: p.name,
        render: () => (
          <span className="flex items-center gap-3">
            <span className="grid size-10 shrink-0 place-items-center overflow-hidden rounded bg-zinc-100 text-zinc-400">
              {p.image ? <img src={`${p.image}-160.webp`} alt="" width={40} height={40} loading="lazy" className="size-full object-contain" /> : p.name.charAt(0)}
            </span>
            <span className="min-w-0 flex-1">
              <span className="block truncate" dangerouslySetInnerHTML={{ __html: p.highlighted }} />
              <span className="block truncate text-xs text-zinc-500">
                {p.variant} · {p.stores > 1 ? `${p.stores} offers, from ${p.chain}` : p.chain}
              </span>
            </span>
            <span className="shrink-0 text-end">
              <span className="block text-sm font-semibold">{aed(p.price)}</span>
              {p.unitPrice !== undefined && <span className="block text-xs text-zinc-500">{aed(p.unitPrice)} {p.unitLabel}</span>}
            </span>
          </span>
        ),
      });
    }
    const chips = (kind: string, values: string[], href: (v: string) => string) =>
      values.forEach((v) => options.push({ id: `${kind}:${v}`, href: href(v), label: v, render: () => <><span className="text-zinc-500">{kind}:</span> {v}</> }));
    chips('Brand', result?.brands ?? [], (v) => searchUrl({ brand: v }));
    chips('Store', result?.stores ?? [], (v) => searchUrl({ store: v }));
    chips('Type', (result?.groups ?? []).filter((g) => g.toLowerCase() !== q.toLowerCase()), (v) => searchUrl({ q: v }));
  } else {
    recent.forEach((r) => options.push({ id: `r:${r}`, href: searchUrl({ q: r }), label: r, render: () => <><span aria-hidden="true" className="me-2 text-zinc-400">↺</span>{r}</> }));
  }

  function go(option: Option) {
    rememberSearch(option.label);
    location.href = option.href;
  }

  function onKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
    if (!open && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) setOpen(true);
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setActive((i) => (i + 1) % Math.max(1, options.length));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setActive((i) => (i <= 0 ? options.length - 1 : i - 1));
    } else if (e.key === 'Enter' && open && active >= 0 && options[active]) {
      e.preventDefault();
      go(options[active]);
    } else if (e.key === 'Escape') {
      setOpen(false);
      setActive(-1);
    }
  }

  const showList = open && options.length > 0;

  return (
    <form ref={box} action="/search" method="get" role="search" className="relative flex flex-1" onSubmit={() => rememberSearch(query)}>
      <label htmlFor="site-search" className="sr-only">Search offers</label>
      <input
        id="site-search"
        name="q"
        type="search"
        value={query}
        autoComplete="off"
        placeholder="Search rice, milk, Almarai…"
        role="combobox"
        aria-expanded={showList}
        aria-controls={listId}
        aria-autocomplete="list"
        aria-activedescendant={showList && active >= 0 ? `${listId}-${active}` : undefined}
        onChange={(e) => {
          setQuery(e.target.value);
          setOpen(true);
          setActive(-1);
        }}
        onFocus={() => {
          setRecent(recentSearches());
          setOpen(true);
        }}
        onKeyDown={onKeyDown}
        className="w-full rounded-s-lg border border-zinc-300 px-3 py-2 text-sm focus:border-brand-600 focus:outline-none"
      />
      <button className="rounded-e-lg bg-brand-600 px-4 text-sm font-medium text-white">Search</button>

      {showList && (
        <div className="absolute inset-x-0 top-full z-40 mt-1 overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xl">
          <ul id={listId} role="listbox" aria-label="Suggestions" className="max-h-[70vh] overflow-auto py-1 text-sm">
            {options.map((option, i) => (
              <li
                key={option.id}
                id={`${listId}-${i}`}
                role="option"
                aria-selected={i === active}
                onMouseEnter={() => setActive(i)}
                onMouseDown={(e) => {
                  e.preventDefault();
                  go(option);
                }}
                className={`cursor-pointer px-3 py-2 ${i === active ? 'bg-brand-50' : ''}`}
              >
                {option.render()}
              </li>
            ))}
          </ul>
          {q.length < 2 && recent.length > 0 && (
            <button type="button" onMouseDown={(e) => { e.preventDefault(); clearRecentSearches(); setRecent([]); }} className="w-full border-t border-zinc-100 px-3 py-2 text-start text-xs text-zinc-500 hover:bg-zinc-50">
              Clear recent searches
            </button>
          )}
        </div>
      )}
    </form>
  );
}
