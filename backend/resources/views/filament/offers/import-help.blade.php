{{-- "How to import offers": steps, rules, every column, and the usual reasons a row fails. Downloads are the modal's footer buttons. --}}
<div style="display: grid; gap: 1.25rem; font-size: 0.875rem; line-height: 1.5">
    <x-filament::section heading="Steps">
        <ol style="list-style: decimal; padding-inline-start: 1.25rem; display: grid; gap: 0.35rem">
            <li><strong>Easiest: the Offer builder</strong> (Offers → Offer builder). Choose your stores and branches, the dates (and times), shared texts and which products, then type or generate the prices in the table and save, with no CSV at all. It can also download the table as a CSV to import later; rows without a price are skipped.</li>
            <li>Or <strong>download the sample</strong> (button below). It is made from your own store, branches and real packs, so you can edit it and import it straight back.</li>
            <li>Keep the first row (the column names). Add <strong>one row per offer</strong>. Only <strong>store</strong>, <strong>price</strong>, <strong>until</strong> and the pack (barcode, or product and pack) are required.</li>
            <li>In Excel: <em>File → Save as → CSV UTF-8 (comma delimited)</em>. Plain "CSV" can break Arabic text.</li>
            <li>Click <strong>Import offers (CSV)</strong>, choose the file, check that each column is matched, then <strong>Import</strong>.</li>
            <li>A notification tells you when it is done. If some rows failed, download them from the notification: the last column says why. Fix them and import just those rows.</li>
        </ol>
    </x-filament::section>

    <x-filament::section heading="Good to know">
        <ul style="list-style: disc; padding-inline-start: 1.25rem; display: grid; gap: 0.35rem">
            <li>Imported offers <strong>go live straight away</strong>, each as its row is read.</li>
            @if ($hours > 0)
                <li>A pack's price at a store can be set <strong>once every {{ $hours }} hours</strong> by store users, whether from a brochure, the Offers page or a CSV. Rows that come too soon fail and say when to try again. Our team can change it any time.</li>
            @endif
            <li>A new price for a pack <strong>replaces</strong> your earlier added offer for that pack at the same branches.</li>
            <li>Codes: use <strong>Your stores and branches</strong> for store and branch codes, and <strong>Pack list</strong> for barcodes and product and pack codes. A pack that isn't in the list must be added under <em>Catalog → Products</em> first.</li>
            <li>An offer at certain times only (e.g. 8:00 AM – 11:00 AM): fill <code>from_time</code> and <code>until_time</code>. It shows only inside that window on each of its days, with a countdown, and is hidden the rest of the time. Leave both empty for all day.</li>
            <li>Dates are <code>YYYY-MM-DD</code>, e.g. {{ now(config('app.market_timezone'))->toDateString() }}. Numbers use a dot: <code>45.95</code>, no "AED".</li>
        </ul>
    </x-filament::section>

    <x-filament::section heading="Columns" collapsible>
        <table style="width: 100%; border-collapse: collapse">
            <thead>
                <tr>
                    <th style="text-align: start; padding: 0.35rem 0.5rem 0.35rem 0; white-space: nowrap">Column</th>
                    <th style="text-align: start; padding: 0.35rem 0">What to put in it</th>
                    <th style="text-align: start; padding: 0.35rem 0 0.35rem 0.5rem">Sample</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($columns as $column => $help)
                    <tr style="border-top: 1px solid rgb(127 127 127 / 0.2); vertical-align: top">
                        <td style="padding: 0.35rem 0.5rem 0.35rem 0; white-space: nowrap"><code>{{ $column }}</code></td>
                        <td style="padding: 0.35rem 0">{{ $help }}</td>
                        <td style="padding: 0.35rem 0 0.35rem 0.5rem; word-break: break-all"><code>{{ collect($sample)->pluck($column)->filter()->first() ?? '' }}</code></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="When a row fails" collapsible collapsed>
        <ul style="list-style: disc; padding-inline-start: 1.25rem; display: grid; gap: 0.35rem">
            <li><strong>Unknown store / Unknown branches</strong>: the code is misspelt, or the branch is closed. Copy it from <em>Your stores and branches</em>.</li>
            <li><strong>Pack not found</strong>: the barcode or the product and pack codes don't match a pack. Copy them from <em>Pack list</em>.</li>
            <li><strong>You can't add offers for…</strong>: that store or branch isn't one of yours.</li>
            <li><strong>Name your branches</strong>: you work at some branches only, so fill the <code>branches</code> column.</li>
            <li><strong>…was set less than {{ $hours }} hours ago</strong>: wait until the time shown, or ask our team.</li>
            <li><strong>The until field must be a date after or equal to today</strong>: the offer has already ended, or the date isn't <code>YYYY-MM-DD</code>.</li>
        </ul>
    </x-filament::section>
</div>
