<?php
// Compact item lines -> manifest pages JSON. Line: page|name|brand|size|price|was|box(l,t,r,b)|category|group[|note][|promo]
// Usage: php items.php in.txt manifest.json  (merges pages into the manifest; keeps header fields)
[$in, $out] = [$argv[1], $argv[2]];
$m = json_decode(file_get_contents($out), true);
$pages = [];
foreach ($m['pages'] ?? [] as $p) { $pages[$p['page']] = $p; }
$meta = [];
foreach (file($in, FILE_IGNORE_NEW_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    if (str_starts_with($line, '@')) { // @page|key=value;key=value
        [$pg, $kv] = explode('|', substr($line, 1), 2);
        foreach (explode(';', $kv) as $pair) { [$k, $v] = explode('=', $pair, 2); $meta[(int)$pg][$k] = $v; }
        continue;
    }
    $f = array_map('trim', explode('|', $line));
    [$pg, $name, $brand, $size, $price, $was, $box, $cat, $group] = array_pad($f, 9, '');
    $note = $f[9] ?? ''; $promo = $f[10] ?? '';
    $item = ['name' => $name, 'brand' => $brand ?: null, 'size' => $size ?: null, 'price' => (float) $price,
        'was' => $was !== '' ? (float) $was : null, 'promo' => $promo ?: ($was !== '' ? 'was_now' : 'price'),
        'note' => $note ?: null, 'box' => array_map('intval', explode(',', $box)), 'sure' => 0.9,
        'category' => $cat, 'group' => $group];
    if (preg_match('/^card_price/', $item['promo'])) { $item['card'] = true; }
    if (preg_match('/^multibuy:(\d+)$/', $item['promo'], $mm)) { $item['promo'] = 'multibuy'; $item['qty'] = (int) $mm[1]; }
    $pages[(int) $pg]['page'] = (int) $pg;
    $pages[(int) $pg]['items'][] = $item;
}
foreach ($meta as $pg => $kv) { foreach ($kv as $k => $v) { $pages[$pg][$k] = $k === 'where' ? explode(',', $v) : $v; } $pages[$pg]['page'] = $pg; }
ksort($pages);
$m['pages'] = array_values($pages);
file_put_contents($out, json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo count($m['pages']), " pages, ", array_sum(array_map(fn ($p) => count($p['items'] ?? []), $m['pages'])), " items\n";
