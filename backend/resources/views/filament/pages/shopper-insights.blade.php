<x-filament-panels::page>
    <div style="display: flex; flex-wrap: wrap; gap: 0.75rem">
        <x-filament::input.wrapper>
            <x-filament::input.select wire:model.live="days" aria-label="Period">
                <option value="7">Last 7 days</option>
                <option value="30">Last 30 days</option>
                <option value="90">Last 90 days</option>
            </x-filament::input.select>
        </x-filament::input.wrapper>
        <x-filament::input.wrapper>
            <x-filament::input.select wire:model.live="region" aria-label="Area">
                <option value="">All areas</option>
                @foreach ($regions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </div>

    <x-filament::section heading="Does personal ranking help?" description="Clicks per home visit. 10% of shoppers who agreed to personal deals stay on the shared order.">
        @if ($cohorts['enough'])
            <p>
                Personal order: <strong>{{ number_format($cohorts['personal']['rate'], 3) }}</strong> clicks per visit
                ({{ number_format($cohorts['personal']['visits']) }} visits).
                Shared order: <strong>{{ number_format($cohorts['shared']['rate'], 3) }}</strong>
                ({{ number_format($cohorts['shared']['visits']) }} visits).
            </p>
        @else
            <p>
                Not enough data yet. Each group needs {{ number_format($minVisits) }} visits; so far personal has
                {{ number_format($cohorts['personal']['visits']) }} and shared has {{ number_format($cohorts['shared']['visits']) }}.
            </p>
        @endif
    </x-filament::section>

    @foreach (['Most viewed products' => $products, 'Most viewed stores' => $stores] as $heading => $rows)
        <x-filament::section :heading="$heading">
            @if ($rows === [])
                <p>Nothing counted for this period and area yet.</p>
            @else
                <table style="width: 100%; font-size: 0.875rem">
                    <thead>
                        <tr style="text-align: start">
                            <th style="text-align: start; padding-block: 0.25rem">Name</th>
                            <th style="text-align: end">Views</th>
                            <th style="text-align: end">Clicks</th>
                            <th style="text-align: end">Plans</th>
                            <th style="text-align: end">Directions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td style="padding-block: 0.25rem">{{ $row['name'] }}</td>
                                <td style="text-align: end">{{ number_format($row['views']) }}</td>
                                <td style="text-align: end">{{ number_format($row['clicks']) }}</td>
                                <td style="text-align: end">{{ number_format($row['plans']) }}</td>
                                <td style="text-align: end">{{ number_format($row['directions']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
