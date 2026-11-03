<?php

namespace App\Filament\Resources\Offers;

use App\Domain\Access\Access;
use App\Domain\Offers\Offer;
use App\Filament\Resources\Offers\Pages\CreateOffer;
use App\Filament\Resources\Offers\Pages\EditOffer;
use App\Filament\Resources\Offers\Pages\ListOffers;
use App\Filament\Resources\Offers\Schemas\OfferForm;
use App\Filament\Resources\Offers\Tables\OffersTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Every offer, from brochures or added directly. New offers here (one by one, or by CSV) go live at once
 * (DirectOffers); brochure offers are changed on their brochure. Permissions are the "Brochures and offers" ones.
 */
class OfferResource extends Resource
{
    protected static ?string $model = Offer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Offers';

    protected static ?int $navigationSort = 2;

    /** Offers in the brochures (and direct-offer containers) the person may see. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('brochure', fn (Builder $query) => Access::scope($query, Access::user(), 'brochures'));
    }

    public static function canViewAny(): bool
    {
        return Access::allows(Access::user(), 'brochures', 'view');
    }

    public static function canCreate(): bool
    {
        return Access::allows(Access::user(), 'brochures', 'create');
    }

    /** Direct offers only; a brochure offer is changed on its brochure. "Own" means offers the person added. */
    public static function canEdit(Model $record): bool
    {
        $user = Access::user();

        return $record instanceof Offer && $record->isDirect()
            && (Access::allows($user, 'brochures', 'edit', $record->brochure)
                || (Access::level($user, 'brochures', 'edit') !== null && $record->created_by === $user?->id));
    }

    public static function canDelete(Model $record): bool
    {
        return false; // withdraw instead, so the price history stays
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return OfferForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OffersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOffers::route('/'),
            'create' => CreateOffer::route('/create'),
            'edit' => EditOffer::route('/{record}/edit'),
        ];
    }
}
