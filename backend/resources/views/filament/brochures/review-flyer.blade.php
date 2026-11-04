{{--
    The flyer review screen (ReviewFlyer): page thumbnails, the current page with a box on every offer the AI
    found, and the items beside it. Hovering an item or clicking a box highlights both (Alpine, no round trip).
    Inline styles: the panel's prebuilt CSS has no arbitrary Tailwind classes. Polls while pages are read.
--}}
@php
    use App\Domain\Brochures\ItemStatus;
    use App\Domain\Brochures\PageStatus;
    $muted = 'opacity: 0.7; font-size: 0.8125rem';
    $card = 'border: 1px solid rgba(128, 128, 128, 0.3); border-radius: 0.6rem; padding: 0.65rem 0.8rem; margin-bottom: 0.6rem';
@endphp

<div @if ($processing) wire:poll.5s @endif x-data="{ selected: null }" style="display: flex; flex-direction: column; gap: 1rem">
    {{-- Pages --}}
    <div style="display: flex; gap: 0.6rem; overflow-x: auto; padding-bottom: 0.4rem">
        @foreach ($pages as $p)
            <button type="button" wire:click="$set('pageNo', {{ $p->page_no }})" wire:key="page-{{ $p->id }}"
                style="flex: 0 0 auto; width: 96px; text-align: center; border-radius: 0.5rem; padding: 0.3rem; border: 2px solid {{ $page?->id === $p->id ? '#d97706' : 'rgba(128, 128, 128, 0.25)' }}; background: transparent; cursor: pointer">
                @if ($thumbs[$p->page_no] ?? null)
                    <img src="{{ $thumbs[$p->page_no] }}" alt="Page {{ $p->page_no }}" style="width: 100%; height: 110px; object-fit: contain; display: block">
                @else
                    <div style="height: 110px; display: flex; align-items: center; justify-content: center; {{ $muted }}">…</div>
                @endif
                <div style="font-size: 0.75rem; margin-top: 0.2rem">Page {{ $p->page_no }}</div>
                <x-filament::badge size="sm" :color="$p->ai_status->getColor()">{{ $p->ai_status->getLabel() }}</x-filament::badge>
                @if ($p->open_count)
                    <div style="font-size: 0.7rem; margin-top: 0.15rem; color: #b45309">{{ $p->open_count }} to review</div>
                @endif
            </button>
        @endforeach
    </div>

    <div style="display: flex; gap: 1.25rem; flex-wrap: wrap; align-items: flex-start">
        {{-- The page, with a box on each offer --}}
        <div style="flex: 1 1 380px; max-width: 620px; min-width: 0">
            @if ($image)
                <div style="position: relative">
                    <img src="{{ $image }}" alt="Page {{ $page->page_no }}" style="width: 100%; display: block; border-radius: 0.5rem">
                    @foreach ($items as $item)
                        @if ($item->bbox && $item->status !== ItemStatus::Ignored)
                            <div x-on:click="selected = {{ $item->id }}; document.getElementById('item-{{ $item->id }}')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' })"
                                title="{{ $item->name }} · AED {{ $item->price }}"
                                style="position: absolute; cursor: pointer; border-radius: 4px;
                                    left: {{ $item->bbox['x'] * 100 }}%; top: {{ $item->bbox['y'] * 100 }}%;
                                    width: {{ $item->bbox['w'] * 100 }}%; height: {{ $item->bbox['h'] * 100 }}%;
                                    border: 2px solid {{ $item->status === ItemStatus::Accepted ? '#16a34a' : ($item->status === ItemStatus::New ? '#d97706' : '#2563eb') }}"
                                x-bind:style="{ background: selected === {{ $item->id }} ? 'rgba(250, 204, 21, 0.35)' : 'transparent' }"></div>
                        @endif
                    @endforeach
                </div>
                @if ($page->text())
                    <div style="{{ $muted }}; margin-top: 0.4rem">This page has selectable text; prices were checked against it.</div>
                @endif
            @elseif ($page)
                <div style="{{ $card }}; {{ $muted }}">The page is still being prepared…</div>
            @endif
            <div style="{{ $muted }}; margin-top: 0.6rem">AI cost for this flyer so far: ${{ number_format($cost, 4) }}</div>
        </div>

        {{-- Items on this page --}}
        <div style="flex: 1 1 440px; min-width: 0">
            @if ($pastDate)
                <div style="{{ $card }}; color: #b45309; font-size: 0.875rem">
                    ⚠ The AI read "valid until {{ \Illuminate\Support\Carbon::parse($pastDate)->format('j M Y') }}", which has passed, so the items keep the brochure's dates. Check them under Brochure details.
                </div>
            @endif
            @forelse ($items as $item)
                <div id="item-{{ $item->id }}" wire:key="item-{{ $item->id }}"
                    x-on:mouseenter="selected = {{ $item->id }}"
                    x-bind:style="{ outline: selected === {{ $item->id }} ? '2px solid #d97706' : 'none' }"
                    style="{{ $card }}; {{ $item->status === ItemStatus::Ignored ? 'opacity: 0.55' : '' }}">
                    <div style="display: flex; justify-content: space-between; gap: 0.6rem; align-items: flex-start">
                        @if ($canCreate && ! $item->variant && in_array($item->status, [ItemStatus::New, ItemStatus::Matched], true))
                            <input type="checkbox" wire:model.live="selected" value="{{ $item->id }}" title="Select to create this product"
                                style="margin-top: 0.3rem; width: 1rem; height: 1rem; flex: 0 0 auto; cursor: pointer">
                        @endif
                        <div style="min-width: 0; flex: 1 1 auto">
                            <div style="font-weight: 600">{{ $item->name }}@if ($item->size_text) <span style="font-weight: 400">· {{ $item->size_text }}</span>@endif</div>
                            @if ($item->name_ar)
                                <div dir="rtl" style="{{ $muted }}">{{ $item->name_ar }}</div>
                            @endif
                            <div style="margin-top: 0.15rem">
                                AED {{ number_format((float) $item->price, 2) }}
                                @if ($item->regular_price)
                                    <s style="opacity: 0.6">{{ number_format((float) $item->regular_price, 2) }}</s>
                                @endif
                                @if ($item->promo_type && $item->promo_type->value !== 'price')
                                    · {{ $item->promo_type->getLabel() }}
                                    @if (! empty($item->promo_params['qty'])) ({{ $item->promo_params['qty'] }} for {{ $item->promo_params['total'] ?? '' }}{{ isset($item->promo_params['get']) ? ', get '.$item->promo_params['get'].' free' : '' }})@endif
                                @endif
                                @if ($item->promo_label)<span style="{{ $muted }}"> · “{{ $item->promo_label }}”</span>@endif
                                @if ($item->condition_label)<span style="{{ $muted }}"> · {{ $item->condition_label }}</span>@endif
                            </div>
                        </div>
                        <div style="text-align: end; flex: 0 0 auto">
                            <x-filament::badge :color="$item->status->getColor()">{{ $item->status->getLabel() }}</x-filament::badge>
                            @if ($item->confidence !== null)
                                <div style="{{ $muted }}">AI {{ round($item->confidence * 100) }}%</div>
                            @endif
                        </div>
                    </div>

                    <div style="margin-top: 0.35rem; font-size: 0.875rem">
                        Product:
                        @if ($item->variant)
                            <strong>{{ $item->variant->label() }}</strong>
                            <span style="{{ $muted }}">({{ match ($item->match_method) { 'alias' => 'learned', 'barcode' => 'barcode', 'manual' => 'picked', 'created' => 'new product', default => 'by name' } }})</span>
                        @else
                            <em style="color: #b45309">not matched yet</em>
                        @endif
                    </div>

                    @if ($canEdit && $item->status !== ItemStatus::Accepted && count($item->candidates ?? []) > 0 && ! $item->variant)
                        <div style="display: flex; flex-wrap: wrap; gap: 0.35rem; margin-top: 0.35rem; align-items: center">
                            <span style="{{ $muted }}">Did you mean</span>
                            @foreach ($item->candidates as $candidate)
                                @if ($labels[$candidate['id']] ?? null)
                                    <x-filament::button size="xs" color="gray" wire:click="useCandidate({{ $item->id }}, {{ $candidate['id'] }})">
                                        {{ $labels[$candidate['id']] }}
                                    </x-filament::button>
                                @endif
                            @endforeach
                        </div>
                    @endif

                    @foreach ($item->issues ?? [] as $issue)
                        <div style="color: #b45309; font-size: 0.8125rem; margin-top: 0.25rem">⚠ {{ $issue['message'] }}</div>
                    @endforeach

                    @if ($canEdit)
                        <div style="display: flex; gap: 0.4rem; margin-top: 0.5rem; flex-wrap: wrap">
                            @if (in_array($item->status, [ItemStatus::New, ItemStatus::Matched], true) && $item->variant)
                                <x-filament::button size="xs" icon="heroicon-m-check" wire:click="acceptItem({{ $item->id }})">Accept</x-filament::button>
                            @endif
                            @if ($item->status !== ItemStatus::Ignored)
                                <x-filament::button size="xs" color="gray" icon="heroicon-m-pencil-square" wire:click="mountAction('editItem', @js(['item' => $item->id]))">
                                    {{ $item->status === ItemStatus::Accepted ? 'Change' : 'Edit and accept' }}
                                </x-filament::button>
                                <x-filament::button size="xs" color="gray" icon="heroicon-m-x-mark" wire:click="ignoreItem({{ $item->id }})">Ignore</x-filament::button>
                            @else
                                <x-filament::button size="xs" color="gray" icon="heroicon-m-arrow-uturn-left" wire:click="restoreItem({{ $item->id }})">Restore</x-filament::button>
                            @endif
                        </div>
                    @endif
                </div>
            @empty
                <div style="{{ $card }}; {{ $muted }}">
                    @switch($page?->ai_status)
                        @case(PageStatus::Queued)
                        @case(PageStatus::Extracting)
                        @case(PageStatus::Pending)
                            The AI is reading this page…
                            @break
                        @case(PageStatus::Batched)
                            Waiting in a half-price batch; the AI answers within a few hours.
                            @break
                        @case(PageStatus::Held)
                            Waiting: this month's AI budget is spent. An admin can raise it under Flyer AI settings.
                            @break
                        @case(PageStatus::Skipped)
                            No prices on this page, so the AI didn't read it.
                            @break
                        @case(PageStatus::Failed)
                            This page could not be read: {{ $page->ai_error }}
                            @break
                        @default
                            No offers found on this page.
                    @endswitch
                </div>
            @endforelse
        </div>
    </div>
</div>
