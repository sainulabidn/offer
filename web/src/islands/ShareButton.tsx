import { useState } from 'react';

/** Native share sheet on phones; WhatsApp, Facebook, X, Telegram and copy link everywhere else. */
export default function ShareButton({ title, path }: { title: string; path: string }) {
  const [open, setOpen] = useState(false);
  const [copied, setCopied] = useState(false);

  const url = () => {
    const u = new URL(path, location.origin);
    u.searchParams.set('utm_source', 'share');
    return u.toString();
  };

  async function share() {
    if (navigator.share) {
      try {
        await navigator.share({ title, url: url() });
        return;
      } catch {
        // Cancelled or unsupported: fall back to the menu.
      }
    }
    setOpen(!open);
  }

  async function copy() {
    await navigator.clipboard.writeText(url());
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  }

  const links = () => {
    const u = encodeURIComponent(url());
    const t = encodeURIComponent(title);
    return [
      ['WhatsApp', `https://wa.me/?text=${t}%20${u}`],
      ['Facebook', `https://www.facebook.com/sharer/sharer.php?u=${u}`],
      ['X', `https://x.com/intent/post?text=${t}&url=${u}`],
      ['Telegram', `https://t.me/share/url?url=${u}&text=${t}`],
    ];
  };

  return (
    <div className="relative inline-block">
      <button type="button" onClick={share} className="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm">
        Share
      </button>
      {open && (
        <div className="absolute start-0 top-full z-30 mt-1 w-44 rounded-lg border border-zinc-200 bg-white p-1 text-sm shadow-lg">
          {links().map(([name, href]) => (
            <a key={name} href={href} target="_blank" rel="noopener" className="block rounded px-3 py-1.5 hover:bg-zinc-100">
              {name}
            </a>
          ))}
          <button type="button" onClick={copy} className="block w-full rounded px-3 py-1.5 text-start hover:bg-zinc-100">
            {copied ? 'Link copied' : 'Copy link'}
          </button>
        </div>
      )}
    </div>
  );
}
