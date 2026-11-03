import type { APIRoute } from 'astro';

// Filters, sort orders and store selections make endless copies of a list; each list's clean URL is the one to index.
const FILTER_PARAMS = ['stores', 'items', 'store', 'brand', 'sort', 'min_discount', 'card', 'staff', 'group', 'near', 'r', 'place'];

export const GET: APIRoute = ({ site, url }) => {
  const origin = (site ?? new URL(url.origin)).origin;
  const body = [
    'User-agent: *',
    // Search result permutations, account pages and shared plans add nothing for search engines.
    'Disallow: /search',
    'Disallow: /account/',
    'Disallow: /plan',
    ...FILTER_PARAMS.flatMap((param) => [`Disallow: /*?${param}=`, `Disallow: /*&${param}=`]),
    'Allow: /media/',
    '',
    `Sitemap: ${origin}/sitemap.xml`,
    '',
  ].join('\n');

  return new Response(body, { headers: { 'Content-Type': 'text/plain', 'Cache-Control': 'public, s-maxage=86400' } });
};
