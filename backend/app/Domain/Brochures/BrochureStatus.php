<?php

namespace App\Domain\Brochures;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BrochureStatus: string implements HasColor, HasLabel
{
    case Uploaded = 'uploaded';
    case Processing = 'processing';
    case InReview = 'in_review';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Expired = 'expired';
    case Rejected = 'rejected';
    case Archived = 'archived';

    public function getLabel(): string
    {
        return str($this->value)->replace('_', ' ')->ucfirst()->toString();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Published => 'success',
            self::InReview, self::Scheduled => 'warning',
            self::Rejected => 'danger',
            default => 'gray',
        };
    }
}
