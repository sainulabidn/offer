import { defineMiddleware } from 'astro:middleware';
import { PASS_TO_ORIGIN } from 'astro:env/server';

/**
 * In production the Worker serves the whole of offer.emirait.com. Laravel's paths go straight to the
 * origin server: a Worker's fetch to its own zone skips the Worker, so this doesn't loop.
 * Locally (PASS_TO_ORIGIN false) the admin and API are on localhost:8000 instead.
 */
const ORIGIN_PATHS = ['/admin', '/api', '/sanctum', '/livewire', '/filament', '/storage', '/typesense', '/up', '/css/filament', '/js/filament', '/fonts/filament'];

// Cloudflare doesn't cache a Worker's own responses, so public pages are kept in the Cache API here.
// Pages opt in with "Cache-Control: public, s-maxage=N" (lib/format.ts cacheFor); personal state
// (likes, plan, sign-in) lives in the browser, so the same HTML is right for everyone.
const edgeCache = () => (globalThis as unknown as { caches: { default: Cache } }).caches.default;

export const onRequest = defineMiddleware(async (context, next) => {
  if (!PASS_TO_ORIGIN) return next();

  const path = context.url.pathname;
  if (ORIGIN_PATHS.some((prefix) => path === prefix || path.startsWith(`${prefix}/`))) {
    return fetch(context.request);
  }
  if (context.request.method !== 'GET') return next();

  const key = new Request(context.url.toString(), { method: 'GET' });
  const hit = await edgeCache().match(key);
  if (hit) {
    const cached = new Response(hit.body, hit);
    cached.headers.set('X-Edge-Cache', 'HIT');
    return cached;
  }

  const rendered = await next();
  const response = new Response(rendered.body, rendered); // mutable headers
  const cacheControl = response.headers.get('Cache-Control') ?? '';
  if (response.status === 200 && /\bpublic\b/.test(cacheControl) && /s-maxage=\d+/.test(cacheControl) && !response.headers.has('Set-Cookie')) {
    try {
      await edgeCache().put(key, response.clone());
      response.headers.set('X-Edge-Cache', 'MISS');
    } catch (error) {
      response.headers.set('X-Edge-Cache', `ERROR ${String(error).slice(0, 120)}`);
    }
  } else {
    response.headers.set('X-Edge-Cache', 'BYPASS');
  }
  return response;
});
