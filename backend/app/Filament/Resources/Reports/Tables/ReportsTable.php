<?php

namespace App\Filament\Resources\Reports\Tables;

use App\Domain\Access\Access;
use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductVariant;
use App\Domain\Engagement\Report;
use App\Domain\Engagement\ReportReason;
use App\Domain\Engagement\Reports;
use App\Domain\Engagement\ReportStatus;
use App\Domain\Offers\Offer;
use App\Domain\Retail\Branch;
use App\Filament\Resources\Branches\BranchResource;
use App\Filament\Resources\Brochures\BrochureResource;
use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'reportable' => fn (MorphTo $morph) => $morph->morphWith([
                Offer::class => ['chain', 'variant.product', 'variant.optionValues'],
                ProductVariant::class => ['product', 'optionValues'],
            ])]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->since()->label('When')->sortable(),
                TextColumn::make('reason')->badge(),
                TextColumn::make('item')->label('Item')->state(fn (Report $r) => self::describe($r))->wrap()
                    ->url(fn (Report $r) => self::editUrl($r), shouldOpenInNewTab: true),
                TextColumn::make('comment')->wrap()->limit(120)->placeholder('—'),
                TextColumn::make('reporter')->state(fn (Report $r) => $r->user?->email ?? 'Guest'),
                TextColumn::make('status')->badge(),
                TextColumn::make('resolution')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(ReportStatus::class)->default(ReportStatus::Open->value),
                SelectFilter::make('reason')->options(ReportReason::class),
            ])
            ->recordActions([
                self::resolveAction('accept', 'Fixed', ReportStatus::Accepted, 'success'),
                self::resolveAction('reject', 'Not a problem', ReportStatus::Rejected, 'gray'),
            ]);
    }

    private static function resolveAction(string $name, string $label, ReportStatus $status, string $color): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->visible(fn (Report $r) => $r->status === ReportStatus::Open && Access::allows(Access::user(), 'reports', 'resolve', $r))
            ->schema([Textarea::make('resolution')->label('Note')->rows(2)])
            ->action(fn (Report $r, array $data, Reports $reports) => $reports->resolve($r, $status, $data['resolution'] ?? null, Access::user()?->id));
    }

    private static function describe(Report $report): string
    {
        $item = $report->reportable;

        return match (true) {
            $item instanceof Offer => "{$item->variant->label()} at {$item->chain->name} · AED {$item->price}",
            $item instanceof ProductVariant => $item->label(),
            $item instanceof Product, $item instanceof Branch => $item->name,
            default => 'Deleted item',
        };
    }

    private static function editUrl(Report $report): ?string
    {
        $item = $report->reportable;

        return match (true) {
            $item instanceof Offer => BrochureResource::getUrl('edit', ['record' => $item->brochure_id]),
            $item instanceof ProductVariant => ProductResource::getUrl('edit', ['record' => $item->product_id]),
            $item instanceof Product => ProductResource::getUrl('edit', ['record' => $item]),
            $item instanceof Branch => BranchResource::getUrl('edit', ['record' => $item]),
            default => null,
        };
    }
}
