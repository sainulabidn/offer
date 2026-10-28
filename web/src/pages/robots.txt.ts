import type { APIRoute } from 'astro';

export const GET: APIRoute = ({ site, url }) => {
  const origin = (site ?? new URL(url.origin)).origin;
  // Search result permutations and account pages add nothing for search engines.
  const body = ['User-agent: *', 'Disallow: /search', 'Disallow: /account/', `Sitemap: ${origin}/sitemap.xml`, ''].join('\n');

  return new Response(body, { headers: { 'Content-Type': 'text/plain', 'Cache-Control': 'public, s-maxage=86400' } });
};
