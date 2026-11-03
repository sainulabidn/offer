import { timeLeft } from './format';

/**
 * Ticks every [data-ends-at] (a unix timestamp) once a second: timed offers count down to the end of
 * today's window. Worked out in the browser, so a cached page still shows the right time.
 */
export function startCountdowns() {
  const tick = () => {
    const now = Date.now();
    document.querySelectorAll<HTMLElement>('[data-ends-at]').forEach((el) => {
      const text = timeLeft(Number(el.dataset.endsAt), now);
      if (el.textContent !== text) el.textContent = text;
      // The page may be a few minutes old: say plainly that this price is over.
      if (text === 'ended') el.closest('[data-timed]')?.classList.add('opacity-60');
    });
  };
  if (!document.querySelector('[data-ends-at]')) return;
  tick();
  setInterval(tick, 1000);
}
