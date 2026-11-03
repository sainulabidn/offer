{{--
    The Offer builder's rows, one page at a time. Inputs are plain HTML with Filament's classes (fast for many
    rows) bound with wire:model, which sends edits with the next action. Promotion details show with Alpine,
    without a round trip. Store, branches and pack come from $targets, which the browser can't change.
--}}
@php
    $from = ($page - 1) * $perPage;
    $cell = 'padding: 0.35rem 0.4rem; vertical-align: top';
    $input = fn (string $width) => "width: {$width}";
@endphp

<div style="overflow-x: auto; font-size: 0.8125rem">
    <table style="width: 100%; border-collapse: collapse; min-width: 1500px">
        <thead>
            <tr style="text-align: start; white-space: nowrap">
                <th style="{{ $cell }}"><span class="sr-only">Selected</span></th>
                <th style="{{ $cell }}; text-align: start">Product · store</th>
                <th style="{{ $cell }}; text-align: end">Usual</th>
                <th style="{{ $cell }}; text-align: start">Was</th>
                <th style="{{ $cell }}; text-align: start">Price</th>
                <th style="{{ $cell }}; text-align: end">Off</th>
                <th style="{{ $cell }}; text-align: start">Promotion</th>
                <th style="{{ $cell }}; text-align: start">Promotion text</th>
                <th style="{{ $cell }}; text-align: start">Limit text</th>
                <th style="{{ $cell }}; text-align: start">Max</th>
                <th style="{{ $cell }}; text-align: start">Employees</th>
                <th style="{{ $cell }}; text-align: start">Card</th>
                <th style="{{ $cell }}; text-align: start">From</th>
                <th style="{{ $cell }}; text-align: start">Until</th>
                <th style="{{ $cell }}; text-align: start">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach (array_slice($targets, $from, $perPage, true) as $i => $target)
                @php
                    $row = $rows[$i];
                    $result = $results[$i] ?? null;
                    $base = (float) ($row['was_price'] ?: $target['usual_price']);
                    $off = is_numeric($row['price']) && $base > 0 && $row['price'] < $base ? round((1 - $row['price'] / $base) * 100) : null;
                @endphp
                <tr wire:key="offer-row-{{ $i }}" x-data="{ type: @js($row['promotion']) }"
                    style="border-top: 1px solid rgb(127 127 127 / 0.2); {{ ($result['ok'] ?? false) ? 'opacity: 0.6' : '' }}">
                    <td style="{{ $cell }}; padding-top: 0.6rem">
                        <input type="checkbox" class="fi-checkbox-input" wire:model="rows.{{ $i }}.selected" aria-label="Include {{ $target['name'] }}">
                    </td>
                    <td style="{{ $cell }}; min-width: 15rem">
                        <div style="font-weight: 600">{{ $target['name'] }}</div>
                        <div style="opacity: 0.7">{{ $target['store_name'] }} · {{ $target['branch_label'] }}</div>
                    </td>
                    <td style="{{ $cell }}; text-align: end; padding-top: 0.6rem; white-space: nowrap">{{ $target['usual_price'] !== '' ? number_format((float) $target['usual_price'], 2) : '—' }}</td>
                    <td style="{{ $cell }}">
                        <div class="fi-input-wrp"><input type="number" step="0.01" min="0" class="fi-input" style="{{ $input('5.5rem') }}" wire:model="rows.{{ $i }}.was_price" aria-label="Was price"></div>
                    </td>
                    <td style="{{ $cell }}">
                        <div class="fi-input-wrp"><input type="number" step="0.01" min="0" class="fi-input" style="{{ $input('5.5rem') }}; font-weight: 600" wire:model="rows.{{ $i }}.price" aria-label="Offer price" placeholder="—"></div>
                    </td>
                    <td style="{{ $cell }}; text-align: end; padding-top: 0.6rem">{{ $off !== null ? "-{$off}%" : '' }}</td>
                    <td style="{{ $cell }}; min-width: 11rem">
                        <div class="fi-input-wrp">
                            <select class="fi-select-input" wire:model="rows.{{ $i }}.promotion" x-on:change="type = $event.target.value" aria-label="Promotion">
                                @foreach ($promotions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div x-show="type === 'multibuy'" x-cloak style="display: flex; gap: 0.25rem; margin-top: 0.25rem; align-items: center">
                            <div class="fi-input-wrp"><input type="number" min="2" class="fi-input" style="{{ $input('3.5rem') }}" wire:model="rows.{{ $i }}.multibuy_qty" placeholder="2" aria-label="How many"></div>
                            for
                            <div class="fi-input-wrp"><input type="number" step="0.01" class="fi-input" style="{{ $input('4.5rem') }}" wire:model="rows.{{ $i }}.multibuy_total" placeholder="18.00" aria-label="Total"></div>
                        </div>
                        <div x-show="type === 'bogo'" x-cloak style="display: flex; gap: 0.25rem; margin-top: 0.25rem; align-items: center">
                            buy <div class="fi-input-wrp"><input type="number" min="1" class="fi-input" style="{{ $input('3.5rem') }}" wire:model="rows.{{ $i }}.buy" aria-label="Buy"></div>
                            get <div class="fi-input-wrp"><input type="number" min="1" class="fi-input" style="{{ $input('3.5rem') }}" wire:model="rows.{{ $i }}.get_free" aria-label="Get free"></div>
                        </div>
                    </td>
                    <td style="{{ $cell }}">
                        <div class="fi-input-wrp"><input type="text" class="fi-input" style="{{ $input('9rem') }}" wire:model="rows.{{ $i }}.promotion_text" aria-label="Promotion text"></div>
                    </td>
                    <td style="{{ $cell }}">
                        <div class="fi-input-wrp"><input type="text" class="fi-input" style="{{ $input('10rem') }}" wire:model="rows.{{ $i }}.limit_text" aria-label="Limit text"></div>
                        <div class="fi-input-wrp" style="margin-top: 0.25rem"><input type="text" dir="rtl" class="fi-input" style="{{ $input('10rem') }}" wire:model="rows.{{ $i }}.limit_text_ar" aria-label="Limit text in Arabic" placeholder="عربي"></div>
                    </td>
                    <td style="{{ $cell }}">
                        <div class="fi-input-wrp"><input type="number" min="1" class="fi-input" style="{{ $input('3.5rem') }}" wire:model="rows.{{ $i }}.max_per_customer" aria-label="Max per customer"></div>
                    </td>
                    <td style="{{ $cell }}; padding-top: 0.6rem; text-align: center">
                        <input type="checkbox" class="fi-checkbox-input" wire:model="rows.{{ $i }}.employees_only" aria-label="Store employees only">
                    </td>
                    <td style="{{ $cell }}; padding-top: 0.6rem; text-align: center">
                        <input type="checkbox" class="fi-checkbox-input" wire:model="rows.{{ $i }}.loyalty_card" aria-label="Loyalty card price">
                    </td>
                    <td style="{{ $cell }}">
                        <div class="fi-input-wrp"><input type="date" class="fi-input" style="{{ $input('8.5rem') }}" wire:model="rows.{{ $i }}.from" aria-label="From"></div>
                    </td>
                    <td style="{{ $cell }}">
                        <div class="fi-input-wrp"><input type="date" class="fi-input" style="{{ $input('8.5rem') }}" wire:model="rows.{{ $i }}.until" aria-label="Until"></div>
                        @if ($target['from_time'])
                            <div style="opacity: 0.7; margin-top: 0.25rem; white-space: nowrap">Daily {{ $target['from_time'] }}–{{ $target['until_time'] }}</div>
                        @endif
                    </td>
                    <td style="{{ $cell }}; padding-top: 0.6rem; min-width: 10rem">
                        @if ($result)
                            <span style="color: {{ $result['ok'] ? 'rgb(22 163 74)' : 'rgb(220 38 38)' }}; font-weight: 600">{{ $result['ok'] ? '✓ ' : '' }}{{ $result['message'] }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

@if ($pages > 1)
    <nav aria-label="Rows" style="display: flex; gap: 0.75rem; align-items: center; justify-content: center; margin-top: 0.75rem; font-size: 0.875rem">
        <button type="button" class="fi-link" wire:click="goToPage({{ $page - 1 }})" @disabled($page === 1)>‹ Previous</button>
        <span>Page {{ $page }} of {{ $pages }} · rows {{ $from + 1 }}–{{ min($from + $perPage, count($targets)) }} of {{ count($targets) }}</span>
        <button type="button" class="fi-link" wire:click="goToPage({{ $page + 1 }})" @disabled($page === $pages)>Next ›</button>
    </nav>
    <p style="text-align: center; font-size: 0.75rem; opacity: 0.7; margin-top: 0.25rem">Edits on other pages are kept. "Select all", "Generate prices" and "Save" work on every page.</p>
@endif
