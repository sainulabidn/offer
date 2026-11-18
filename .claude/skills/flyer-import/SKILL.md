---
name: flyer-import
description: Import every store's current flyers into the LIVE site without the flyer AI — download them from the Flyer Sources (admin/brochure-sources) or the web, read each page here (Claude Code), write a manifest per flyer, and run flyers:import-reviewed on the server, which matches or creates products (with a photo cropped from the flyer), categories, groups and brands, then publishes the offers. Use when asked to "add all offers", "import the flyers", "update offers for all branches".
---

# Flyer import by Claude Code (no flyer AI)

Live = SSH host `offeremirait` (lazyoffer.com), app in `~/offer/backend`, PHP is
`/opt/cpanel/ea-php84/root/usr/bin/php` (plain `php` is 8.2 and fails). Never call the server's AI pipeline
(no API key there, and the user wants Claude Code to do the reading). Data goes into the live DB only — not local.

Code: `backend/app/Domain/Flyers/ReviewedFlyerWorkspace.php` (export/download/render) and
`ImportReviewedFlyer.php` (the import). Read the class docblocks for the manifest format.

## 1. Export sources from live, download, render (local Docker)

```bash
D=2026-11-17   # today
ssh offeremirait "cd ~/offer/backend && /opt/cpanel/ea-php84/root/usr/bin/php artisan flyers:workspace-export" > backend/storage/app/flyer-work/$D/export.json
docker compose run --rm app php artisan flyers:workspace-download storage/app/flyer-work/$D/export.json storage/app/flyer-work/$D
```

Each flyer gets a folder `<source-id>-<hash>/` with the file(s), `flyer.json`, `manifest.json` (skeleton),
`page-N.jpg`, `grid-N.jpg` and halves `grid-N-top/bottom.jpg` (or `-left/right` for wide pages). Flyers already on
the live site are skipped (same address or same file hash). Use `--source=ID` to retry one source.

Sources that fail (JS-only pages, robots, dead links): search the web for the store's current flyer (store site,
WowDeals, D4D Online, etc.), download the PDF or page images into a new folder with a hand-written `flyer.json`
(same keys as the generated ones: chain slug, source_id or null, branch_ids, title, valid_from, valid_to,
source_url, files), then `docker compose run --rm app php artisan flyers:workspace-render storage/app/flyer-work/$D/<folder>`.
Several sources can list the same chain-wide flyer (Nesto stores, Lulu regions): import it once, with branch_ids [] or the union.

## 2. Read the pages and fill manifest.json

Open `grid-N-top.jpg` / `grid-N-bottom.jpg` (sharper) with Read; the pink labels are thousandths of the full page,
which is exactly the `box` unit (`[left, top, right, bottom]`, 0–1000 of the whole page, even on a half).
Per page: `{"page": N, "type": "offers"|"cover"|"info"|"mixed", "from": null, "until": null, "where": [], "items": [...]}`.
Pages without offers can be left out. Per item (FlyerSchema keys + hints):

```json
{"name": "Almarai Full Fat Fresh Milk", "brand": "Almarai", "size": "2 L", "price": 10.5, "was": 12.95,
 "promo": "was_now", "qty": null, "free": null, "pct": null, "limit": null, "card": false, "note": null,
 "box": [120, 340, 330, 520], "sure": 0.9,
 "category": "Dairy & Eggs", "group": "Fresh Milk", "product": "Almarai Full Fat Fresh Milk"}
```

- `size` must parse: "500 g", "1.5 L", "6 x 200 ml", "per kg", "30 pcs", "1 pc" (units: g kg ml l pc dozen m sheet wash…).
- `promo`: price, was_now, multibuy (price = total, qty = n), bogo (qty, free), percent_off (pct), card_price, bundle, gift, coupon.
- `box` should hug the product picture only (not the price tag): it is cropped as the product photo when the
  product has none, and highlighted on the review screen. Reading boxes straight off the raw page works too:
  the Read tool shows the picture's pixel size, so box = pixel / width (or height) × 1000.
- Boxes are thousandths of the page, never pixels: on a 1200x1500 page a box at pixels 60–520 × 540–910 is
  [50, 360, 433, 607]. Check the first box of every flyer against its picture.
- Faster than JSON by hand: one line per item in `items.txt` and `items.php` (in this folder) builds the pages:
  `page|name|brand|size|price|was|l,t,r,b|category|group|note|promo` (promo empty = was_now if a was price
  is given, else price; `card_price` sets card). `@7|from=2026-11-18;until=2026-11-18` gives page 7 its own dates.
  Run: `MSYS_NO_PATHCONV=1 docker run --rm -v "d:/offer:/repo" -w /repo offer-app:dev php .claude/skills/flyer-import/items.php <dir>/items.txt <dir>/manifest.json`
- Imports take ~5 s per new product (photo); run `queue.sh <date>` once in the background and append folder
  names to `<date>/queue.txt` as manifests are ready (one import at a time on the server).
- The same catalogue for two regions (Lulu Abu Dhabi vs Dubai) usually shares layout but not prices: read each
  page, copy the manifest and change only the prices (`sed`), and compare page files with `md5sum` first.
- `category`: an existing name (Fresh Food > Fruits & Vegetables, Meat & Poultry, Fish & Seafood, Dairy & Eggs,
  Bakery; Pantry > Rice & Grains, Oil & Ghee, Flour & Baking, Pulses & Lentils, Spices, Tea & Coffee, Canned Food,
  Breakfast & Cereals, Snacks & Sweets, Pasta & Noodles, Dates & Nuts, Sauces & Condiments, Sugar & Salt; Frozen Food >
  Frozen Meat & Poultry, Frozen Vegetables & Fries, Ice Cream; Beverages > Water, Soft Drinks, Juices; Household >
  Laundry, Cleaning, Paper & Tissues, Dishwashing; Personal Care > Hair Care, Bath & Body, Oral Care; Baby > Diapers,
  Baby Milk & Food) or a new path like "Electronics > Mobile Phones" / "Home & Kitchen > Cookware" (created).
  Check live for categories added since: `Category::pluck('name')`.
- `group`: product group (e.g. "Basmati Rice", "Smartphones"); created when new. A group compares one unit
  dimension: don't mix "per kg" and "pcs" items in one group name.
- `product`: clean catalog name with brand, no size; defaults to `name`. Known products are matched by the
  existing matcher (name similarity + exact size); `variant_id` forces a pack.
- `from`/`until` on a page only when printed; brochure dates come from the manifest (`valid_from`/`valid_to`).

Validate before uploading: `php -r` in Docker, or `jq . manifest.json`.

## 3. Import on live

```bash
F=storage/app/flyer-work/$D
ssh offeremirait "mkdir -p ~/flyer-imports/$D"
scp -r backend/$F/<folder> offeremirait:flyer-imports/$D/     # files + manifest.json (pictures not needed)
ssh offeremirait "cd ~/offer/backend && /opt/cpanel/ea-php84/root/usr/bin/php artisan flyers:import-reviewed ~/flyer-imports/$D/*/manifest.json"
```

It prints per flyer: items, accepted, new products, photos, published, and problems to fix (fix the manifest
and re-run is not possible for the same file — it is skipped as already imported; fix items in admin instead, or
archive the brochure and import again). `--draft` leaves offers unpublished for a look in admin first.

## Notes

- Live cron runs `schedule:run` only on the 1st and 15th, so queued jobs (image sizes, alerts) barely run:
  the import does rendering and photo sizes itself.
- Old offers end by date on their own; `offers:expire` tidies statuses.
