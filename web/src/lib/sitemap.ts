import { api, brands, type Category } from './api';
import { productPath } from './format';
import { directory } from './near';
import { searchOffers } from './search';
import { MIN_INDEXABLE_OFFERS, absoluteUrl, regions, scopePath, seoData } from './seo';

/** The sitemap files under /sitemaps/, listed by the index at /sitemap.xml. */
export const SITEMAPS = ['pages', 'products', 'locations', 'product-locations', 'brands', 'stores'] as const;
export type SitemapName = (typeof SITEMAPS)[number];

interface Entry {
  path: string;
  lastmod?: string | null;
  image?: string | null;
}

const escapeXml = (value: string) => value.replace(/[<>&'"]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', "'": '&apos;', '"': '&quot;' })[c]!);

export function urlset(entries: Entry[], origin: string): string {
  const urls = entries.map(({ path, lastmod, image }) =>
    [
      '<url>',
      `<loc>${escapeXml(absoluteUrl(path, origin))}</loc>`,
      lastmod ? `<lastmod>${lastmod}</lastmod>` : '',
      image ? `<image:image><image:loc>${escapeXml(absoluteUrl(image, origin))}</image:loc></image:image>` : '',
      '</url>',
    ].join(''),
  );
  return `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">${urls.join('')}</urlset>`;
}

const flatten = (tree: Category[]): Category[] => tree.flatMap((category) => [category, ...flatten(category.children)]);

/** Live offers per category and per brand, in one region (or all of the UAE). */
async function countsIn(regionId: number | null) {
  const result = await searchOffers({
    filters: regionId ? [`region_ids:=${regionId}`] : [],
    perPage: 0,
    facets: ['category_ids', 'brand_id'],
    maxFacetValues: 500,
  });
  const facet = (field: string) => new Map((result.facets.find((f) => f.field === field)?.values ?? []).map((v) => [Number(v.value), v.count]));
  return { total: result.found, categories: facet('category_ids'), brands: facet('brand_id') };
}

export async function sitemapEntries(name: SitemapName): Promise<Entry[]> {
  const today = new Date().toISOString().slice(0, 10);

  switch (name) {
    case 'pages': {
      const { pages } = await seoData();
      const categories = flatten(await api.categories());
      return [
        { path: '/', lastmod: today },
        { path: '/stores' },
        { path: '/brands' },
        ...categories.map((category) => ({ path: `/c/${category.slug}`, lastmod: today })),
        // Staff landing pages; overrides of other pages are listed with those pages.
        ...[...pages.values()].filter((page) => page.filters && !page.noindex).map((page) => ({ path: page.path, lastmod: page.updated })),
      ];
    }
    case 'products': {
      const data = await api.sitemap();
      return (data?.products ?? []).flatMap((product) => [
        { path: `/products/${product.slug}`, lastmod: product.updated, image: product.image },
        ...product.variants.map((variant) => ({ path: productPath(product.slug, variant.slug), lastmod: product.updated, image: product.image })),
      ]);
    }
    case 'product-locations': {
      // Each product, and each of its packs, in every emirate where a store has it on offer today.
      // Area pages exist too, but repeat their emirate's too closely to list.
      const [data, dir] = await Promise.all([api.sitemap(), directory()]);
      const emirates = regions(dir).filter((region) => region.type === 'emirate');
      const counted = await Promise.all(
        emirates.map(async (emirate) => {
          const result = await searchOffers({ filters: [`region_ids:=${emirate.id}`, 'staff_only:false'], perPage: 0, facets: ['product_id', 'variant_id'], maxFacetValues: 10_000 });
          const ids = (field: string) => new Set((result.facets.find((f) => f.field === field)?.values ?? []).map((v) => Number(v.value)));
          return { emirate, products: ids('product_id'), variants: ids('variant_id') };
        }),
      );
      return counted.flatMap(({ emirate, products, variants }) =>
        (data?.products ?? [])
          .filter((product) => products.has(product.id))
          .flatMap((product) => [
            { path: productPath(product.slug, null, `/${emirate.slug}`), lastmod: today, image: product.image },
            ...product.variants.filter((variant) => variants.has(variant.id)).map((variant) => ({ path: productPath(product.slug, variant.slug, `/${emirate.slug}`), lastmod: today })),
          ]),
      );
    }
    case 'locations': {
      // Only places, and place × category or brand, with enough live offers; the pages themselves say noindex below that.
      const [dir, tree, brandList] = await Promise.all([directory(), api.categories(), brands()]);
      const categories = flatten(tree);
      const places = regions(dir);
      const allCounts = await Promise.all(places.map((place) => countsIn(place.id)));
      const entries: Entry[] = [];
      for (const [index, place] of places.entries()) {
        const counts = allCounts[index];
        const scope = place.type === 'area' ? { emirate: place.emirate, area: place } : { emirate: place };
        if (counts.total >= (place.type === 'area' ? MIN_INDEXABLE_OFFERS : 1)) entries.push({ path: scopePath(scope), lastmod: today });
        for (const category of categories) {
          if ((counts.categories.get(category.id) ?? 0) >= MIN_INDEXABLE_OFFERS) entries.push({ path: scopePath({ ...scope, category }), lastmod: today });
        }
        for (const brand of brandList) {
          if ((counts.brands.get(brand.id) ?? 0) >= MIN_INDEXABLE_OFFERS) entries.push({ path: scopePath({ ...scope, brand }), lastmod: today });
        }
      }
      return entries;
    }
    case 'brands': {
      const [brandList, counts] = await Promise.all([brands(), countsIn(null)]);
      return brandList.filter((brand) => counts.brands.has(brand.id)).map((brand) => ({ path: `/brands/${brand.slug}`, lastmod: today }));
    }
    case 'stores': {
      const data = await api.sitemap();
      return [...(data?.chains ?? []).map((slug) => ({ path: `/stores/${slug}`, lastmod: today })), ...(data?.branches ?? []).map((path) => ({ path: `/stores/${path}` }))];
    }
  }
}
