import { defineMiddleware } from 'astro:middleware';
import { PASS_TO_ORIGIN } from 'astro:env/server';

/**
 * In production the Worker serves the whole of offer.emirait.com. Laravel's paths go straight to the
 * origin server: a Worker's fetch to its own zone skips the Worker, so this doesn't loop.
 * Locally (PASS_TO_ORIGIN false) the admin and API are on localhost:8000 instead.
 */
const ORIGIN_PATHS = ['/admin', '/api', '/sanctum', '/livewire', '/filament', '/storage', '/typesense', '/up', '/css/filament', '/js/filament', '/fonts/filament'];

export const onRequest = defineMiddleware((context, next) => {
  if (PASS_TO_ORIGIN) {
    const path = context.url.pathname;
    if (ORIGIN_PATHS.some((prefix) => path === prefix || path.startsWith(`${prefix}/`))) {
      return fetch(context.request);
    }
  }
  return next();
});
