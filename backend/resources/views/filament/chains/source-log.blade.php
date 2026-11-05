{{-- The last checks of a flyer source (BrochureSourcesRelationManager → Log). --}}
<div style="font-size: 0.875rem">
    @forelse ($fetches as $fetch)
        <div style="padding: 0.4rem 0; border-bottom: 1px solid rgba(128, 128, 128, 0.2)">
            <strong>{{ $fetch->created_at->setTimezone(config('app.market_timezone'))->format('j M H:i') }}</strong>
            · {{ match ($fetch->outcome) { 'new' => 'New flyer', 'unchanged' => 'No change', 'duplicate' => 'Already have it', 'gone' => 'Link removed by the store, skipped', 'no_links' => 'No flyer links', 'blocked' => 'Not permitted', default => 'Failed' } }}
            @if ($fetch->http_status) · HTTP {{ $fetch->http_status }} @endif
            @if ($fetch->bytes) · {{ number_format($fetch->bytes / 1048576, 1) }} MB @endif
            @if ($fetch->error)
                <div style="color: #b45309">{{ $fetch->error }}</div>
            @endif
            <div style="opacity: 0.6; word-break: break-all">{{ $fetch->url }}</div>
        </div>
    @empty
        <div style="opacity: 0.7">Not checked yet.</div>
    @endforelse
</div>
