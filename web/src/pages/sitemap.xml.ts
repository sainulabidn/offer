import type { APIRoute } from 'astro';
import { SITEMAPS } from '../lib/sitemap';

/** The sitemap index: one file per kind of page (lib/sitemap.ts), each well under the 50,000-URL limit for now. */
export const GET: APIRoute = ({ site, url }) => {
  const origin = (site ?? new URL(url.origin)).origin;
  const today = new Date().toISOString().slice(0, 10);
  const files = SITEMAPS.map((name) => `<sitemap><loc>${origin}/sitemaps/${name}.xml</loc><lastmod>${today}</lastmod></sitemap>`);

  return new Response(`<?xml version="1.0" encoding="UTF-8"?>\n<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">${files.join('')}</sitemapindex>`, {
    headers: { 'Content-Type': 'application/xml; charset=utf-8', 'Cache-Control': 'public, s-maxage=3600' },
  });
};
