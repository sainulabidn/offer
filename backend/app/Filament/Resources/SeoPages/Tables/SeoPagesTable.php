<?php

namespace App\Filament\Resources\SeoPages\Tables;

use App\Domain\Seo\SeoPage;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SeoPagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('path')
            ->columns([
                TextColumn::make('path')
                    ->label('Address')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono'),
                TextColumn::make('kind')
                    ->label('Type')
                    ->state(fn (SeoPage $page) => $page->isLanding() ? 'Landing page' : 'Texts for a page')
                    ->badge()
                    ->color(fn (SeoPage $page) => $page->isLanding() ? 'success' : 'gray'),
                TextColumn::make('title')
                    ->state(fn (SeoPage $page) => $page->getTranslation('title', 'en', false))
                    ->placeholder('The page\'s own')
                    ->limit(60)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereRaw("title->>'en' ilike ?", ['%'.$search.'%'])),
                IconColumn::make('noindex')->label('Hidden from search')->boolean()->toggleable(),
                ToggleColumn::make('is_active')->label('Active'),
                TextColumn::make('updated_at')->since()->sortable()->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
                TernaryFilter::make('landing')
                    ->label('Type')
                    ->trueLabel('Landing pages')
                    ->falseLabel('Texts for a page')
                    ->queries(
                        true: fn (Builder $query) => $query->where('path', 'like', SeoPage::LANDING_PREFIX.'%'),
                        false: fn (Builder $query) => $query->where('path', 'not like', SeoPage::LANDING_PREFIX.'%'),
                    ),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (SeoPage $page) => rtrim((string) config('app.frontend_url'), '/').$page->path, shouldOpenInNewTab: true),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
