import { defineMiddleware } from 'astro:middleware';
import { API_URL, PASS_TO_ORIGIN } from 'astro:env/server';
import { env } from 'cloudflare:workers';
import { NO_PREFS, PREFS_COOKIE, prefsKey, resolvePrefs, type Prefs } from '@offer/rank';

/**
 * In production the Worker serves the whole of lazyoffer.com. Laravel's paths go straight to the
 * origin server: a Worker's fetch to its own zone skips the Worker, so this doesn't loop.
 * Locally (PASS_TO_ORIGIN false) the admin and API are on localhost:8000 instead.
 */
const ORIGIN_PATHS = ['/admin', '/api', '/sanctum', '/filament', '/storage', '/typesense', '/up', '/css/filament', '/js/filament', '/fonts/filament'];
// Livewire 4 (Filament's admin) uses an app-specific prefix such as /livewire-f83ae909/update.
const ORIGIN_PATTERN = /^\/livewire(-[0-9a-f]+)?(\/|$)/;
// cPanel AutoSSL proves each hostname with a file in the docroot; on every hostname, so certificates renew.
const CERT_CHECK = /^\/\.well-known\/(acme-challenge|pki-validation)\//;
// The site's one hostname (lazyoffer.com); www and the old offer.emirait.com redirect to it.
const SITE_HOST = new URL(API_URL).host;

// Cloudflare doesn't cache a Worker's own responses, so public pages are kept in the Cache API here.
// Pages opt in with "Cache-Control: public, s-maxage=N" (lib/format.ts cacheFor); personal state
// (likes, plan, sign-in) lives in the browser, so the same HTML is right for everyone.
const edgeCache = () => (globalThis as unknown as { caches: { default: Cache } }).caches.default;

// Pages drawn for the shopper's area. Everything else gets NO_PREFS, so it can't vary by shopper and
// keeps a single cached copy; a path missing here shows the plain page, never someone else's.
// Location pages (/dubai/…) and landing pages (/deals/…) name their place in the URL, so they are the same for everyone.
const USES_PREFS = /^\/(search|stores|c\/[^/]+|brands\/[^/]+)?\/?$/;

// Each deploy gets its own page cache: HTML cached before a deploy links /_astro files the deploy removed,
// which shows the page without styles. CF_VERSION_METADATA changes with every deploy.
// The shopper's settings are in the key, so people in different areas never share a cached page.
const pageCacheKey = (url: URL, prefs: Prefs) => {
  const key = new URL(url);
  key.searchParams.set('__build', env.CF_VERSION_METADATA?.id ?? 'dev');
  key.searchParams.set('__prefs', prefsKey(prefs));
  return new Request(key.toString(), { method: 'GET' });
};
// Browsers re-check every page (answered from the edge cache in milliseconds). Without this, the cached
// copy comes back with the zone's Browser Cache TTL (4 h) and a browser keeps showing pre-deploy HTML.
const BROWSER_CACHE = 'public, max-age=0, must-revalidate';

// Product photos, store and brand logos, and the share pictures set in the admin (SEO) live in the R2
// bucket (binding MEDIA, production only) and are served here at /media/…, so the bucket stays private
// and needs no domain of its own. Only image files are served, with the type taken from the extension,
// never from the stored object.
const MEDIA_FOLDERS = ['/media/products/', '/media/logos/', '/media/share/'];
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

/**
 * Social sharing pictures (/og/product/….jpg, /og/list.jpg), drawn by Laravel and kept at the edge. On the
 * site's own domain, as WhatsApp and Facebook prefer; the address carries a version, so a cached copy
 * is never out of date for long.
 */
async function shareImage(request: Request, url: URL): Promise<Response> {
  const cacheKey = new Request(url.toString(), { method: 'GET' });
  const hit = await edgeCache().match(cacheKey).catch(() => undefined);
  if (hit) return hit;

  // Laravel limits drawing per visitor; without this every request would come from the Worker's address.
  const visitor = request.headers.get('CF-Connecting-IP');
  const origin = await fetch(`${API_URL}/api/v1${url.pathname}${url.search}`, {
    headers: { Accept: 'image/jpeg', ...(visitor && { 'X-Forwarded-For': visitor }) },
  });
  if (origin.status !== 200 || origin.headers.get('Content-Type') !== 'image/jpeg') {
    return new Response('Not found', { status: origin.status === 429 ? 429 : 404, headers: { 'Cache-Control': 'public, max-age=60' } });
  }
  const response = new Response(origin.body, origin);
  await edgeCache().put(cacheKey, response.clone()).catch(() => undefined);
  return response;
}

export const onRequest = defineMiddleware(async (context, next) => {
  if (PASS_TO_ORIGIN && CERT_CHECK.test(context.url.pathname)) return fetch(context.request);

  // Plain-http visitors and other hostnames go to https://lazyoffer.com, same path. 308 for other methods
  // so a form POST isn't turned into a GET.
  // Static files (/_astro/…) are served before the Worker runs, but https pages only link them over https.
  if (PASS_TO_ORIGIN && (context.url.protocol === 'http:' || context.url.host !== SITE_HOST)) {
    const target = new URL(context.url);
    target.protocol = 'https:';
    target.host = SITE_HOST;
    const method = context.request.method;
    return Response.redirect(target.toString(), method === 'GET' || method === 'HEAD' ? 301 : 308);
  }

  const path = context.url.pathname;
  context.locals.prefs = USES_PREFS.test(path)
    ? resolvePrefs({
        url: context.url,
        cookie: context.cookies.get(PREFS_COOKIE)?.value,
        regionCode: (context.request as Request & { cf?: { regionCode?: string } }).cf?.regionCode,
      })
    : NO_PREFS;

  if (MEDIA_FOLDERS.some((folder) => path.startsWith(folder)) && context.request.method === 'GET' && env.MEDIA) return media(context.url);
  if (path.startsWith('/og/') && context.request.method === 'GET') return shareImage(context.request, context.url);
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

  const key = pageCacheKey(context.url, context.locals.prefs);
  const hit = await edgeCache().match(key);
  if (hit) {
    const cached = new Response(hit.body, hit);
    cached.headers.set('Cache-Control', BROWSER_CACHE);
    cached.headers.set('X-Edge-Cache', 'HIT');
    return cached;
  }

  const rendered = await next();
  const response = new Response(rendered.body, rendered); // mutable headers
  const cacheControl = response.headers.get('Cache-Control') ?? '';
  if (response.status === 200 && /\bpublic\b/.test(cacheControl) && /s-maxage=\d+/.test(cacheControl) && !response.headers.has('Set-Cookie')) {
    try {
      await edgeCache().put(key, response.clone());
      response.headers.set('Cache-Control', BROWSER_CACHE);
      response.headers.set('X-Edge-Cache', 'MISS');
    } catch (error) {
      response.headers.set('X-Edge-Cache', `ERROR ${String(error).slice(0, 120)}`);
    }
  } else {
    response.headers.set('X-Edge-Cache', 'BYPASS');
  }
  return response;
});
