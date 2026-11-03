import type { APIRoute } from 'astro';
import { SITEMAPS, sitemapEntries, urlset, type SitemapName } from '../../lib/sitemap';

export const GET: APIRoute = async ({ params, site, url }) => {
  const name = params.name as SitemapName;
  if (!SITEMAPS.includes(name)) return new Response('Not found', { status: 404 });

  const origin = (site ?? new URL(url.origin)).origin;
  return new Response(urlset(await sitemapEntries(name), origin), {
    headers: { 'Content-Type': 'application/xml; charset=utf-8', 'Cache-Control': 'public, s-maxage=3600' },
  });
};
