import { defineMiddleware } from 'astro:middleware';
import { PASS_TO_ORIGIN } from 'astro:env/server';
import { env } from 'cloudflare:workers';

/**
 * In production the Worker serves the whole of offer.emirait.com. Laravel's paths go straight to the
 * origin server: a Worker's fetch to its own zone skips the Worker, so this doesn't loop.
 * Locally (PASS_TO_ORIGIN false) the admin and API are on localhost:8000 instead.
 */
const ORIGIN_PATHS = ['/admin', '/api', '/sanctum', '/filament', '/storage', '/typesense', '/up', '/css/filament', '/js/filament', '/fonts/filament'];
// Livewire 4 (Filament's admin) uses an app-specific prefix such as /livewire-f83ae909/update.
const ORIGIN_PATTERN = /^\/livewire(-[0-9a-f]+)?(\/|$)/;

// Cloudflare doesn't cache a Worker's own responses, so public pages are kept in the Cache API here.
// Pages opt in with "Cache-Control: public, s-maxage=N" (lib/format.ts cacheFor); personal state
// (likes, plan, sign-in) lives in the browser, so the same HTML is right for everyone.
const edgeCache = () => (globalThis as unknown as { caches: { default: Cache } }).caches.default;

// Product photos live in the R2 bucket (binding MEDIA, production only) and are served here at
// /media/products/…, so the bucket stays private and needs no domain of its own. Only image files
// are served, with the type taken from the extension, never from the stored object.
const MEDIA_TYPES: Record<string, string> = { avif: 'image/avif', webp: 'image/webp', jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png' };

async function media(url: URL): Promise<Response> {
  const key = url.pathname.slice('/media/'.length);
  const type = MEDIA_TYPES[key.split('.').pop()?.toLowerCase() ?? ''];
  if (!type || !env.MEDIA) return new Response('Not found', { status: 404 });

  const cacheKey = new Request(`${url.origin}/media/${key}`, { method: 'GET' });
  const hit = await edgeCache().match(cacheKey);
  if (hit) return hit;

  const object = await env.MEDIA.get(key);
  if (!object) return new Response('Not found', { status: 404, headers: { 'Cache-Control': 'public, max-age=60' } });
  // Sizes are written once per upload (a new upload gets a new file name), so they never change.
  const response = new Response(object.body, {
    headers: {
      'Content-Type': type,
      'Cache-Control': 'public, max-age=31536000, immutable',
      ETag: object.httpEtag,
      'X-Content-Type-Options': 'nosniff',
    },
  });
  await edgeCache().put(cacheKey, response.clone());
  return response;
}

export const onRequest = defineMiddleware(async (context, next) => {
  // Plain-http visitors go to https. 308 for other methods so a form POST isn't turned into a GET.
  // Static files (/_astro/…) are served before the Worker runs, but https pages only link them over https.
  if (PASS_TO_ORIGIN && context.url.protocol === 'http:') {
    const target = new URL(context.url);
    target.protocol = 'https:';
    const method = context.request.method;
    return Response.redirect(target.toString(), method === 'GET' || method === 'HEAD' ? 301 : 308);
  }

  const path = context.url.pathname;
  if (path.startsWith('/media/products/') && context.request.method === 'GET' && env.MEDIA) return media(context.url);
  if (!PASS_TO_ORIGIN) return next();

  // Search-as-you-type: identical queries from many shoppers (the key is the same public scoped key),
  // so answer them from the edge for a minute instead of going to the origin on every keystroke.
  if (path.startsWith('/typesense/') && context.request.method === 'GET') {
    const key = new Request(context.url.toString(), { method: 'GET' });
    const hit = await edgeCache().match(key);
    if (hit) return hit;
    const origin = await fetch(context.request);
    if (origin.status === 200) {
      const response = new Response(origin.body, origin);
      response.headers.set('Cache-Control', 'public, max-age=30, s-maxage=60');
      await edgeCache().put(key, response.clone());
      return response;
    }
    return origin;
  }
  if (ORIGIN_PATTERN.test(path) || ORIGIN_PATHS.some((prefix) => path === prefix || path.startsWith(`${prefix}/`))) {
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
