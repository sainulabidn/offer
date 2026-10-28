import { useEffect, useState } from 'react';
import { call, isSignedIn, myLikes, signInUrl } from '../lib/account';

/** Heart on product and offer cards. Guests are sent to sign in; counts update instantly for the shopper. */
export default function LikeButton({ target }: { target: `product:${number}` | `offer:${number}` | string }) {
  const [type, rawId] = target.split(':') as ['product' | 'offer', string];
  const id = Number(rawId);
  const [liked, setLiked] = useState(false);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let alive = true;
    myLikes().then((likes) => alive && setLiked(likes[type].has(id)));
    return () => {
      alive = false;
    };
  }, [type, id]);

  async function toggle() {
    if (!isSignedIn()) {
      location.href = signInUrl();
      return;
    }
    setBusy(true);
    setLiked(!liked); // optimistic
    try {
      const result = await call<{ liked: boolean }>('POST', 'likes', { type, id });
      setLiked(result.liked);
    } catch {
      setLiked(liked);
    } finally {
      setBusy(false);
    }
  }

  return (
    <button
      type="button"
      onClick={toggle}
      disabled={busy}
      aria-pressed={liked}
      aria-label={liked ? 'Unlike' : 'Like'}
      className="relative z-10 grid size-8 place-items-center rounded-full bg-white/90 shadow-sm"
    >
      <svg viewBox="0 0 24 24" className={`size-5 ${liked ? 'fill-deal stroke-deal' : 'fill-none stroke-zinc-500'}`} strokeWidth="2" aria-hidden="true">
        <path d="M12 21s-7.5-4.6-9.5-9.2C1.2 8.6 3.4 5 7 5c2 0 3.4 1.1 5 3 1.6-1.9 3-3 5-3 3.6 0 5.8 3.6 4.5 6.8C19.5 16.4 12 21 12 21z" />
      </svg>
    </button>
  );
}
