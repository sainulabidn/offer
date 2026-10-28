import type { APIRoute } from 'astro';
import { api } from '../lib/api';

/** One sitemap for now (fine up to 50,000 URLs); split into an index when the catalog grows past that. */
export const GET: APIRoute = async ({ site, url }) => {
  const origin = (site ?? new URL(url.origin)).origin;
  const data = await api.sitemap();
  const entry = (path: string, lastmod?: string) =>
    `<url><loc>${origin}${path}</loc>${lastmod ? `<lastmod>${lastmod}</lastmod>` : ''}</url>`;

  const urls = [
    entry('/'),
    ...(data?.categories ?? []).map((slug) => entry(`/c/${slug}`)),
    ...(data?.chains ?? []).map((slug) => entry(`/stores/${slug}`)),
    ...(data?.products ?? []).map((p) => entry(`/products/${p.slug}`, p.updated)),
  ];

  return new Response(`<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">${urls.join('')}</urlset>`, {
    headers: { 'Content-Type': 'application/xml; charset=utf-8', 'Cache-Control': 'public, s-maxage=3600' },
  });
};
