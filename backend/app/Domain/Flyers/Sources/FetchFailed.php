<?php

namespace App\Domain\Flyers\Sources;

use RuntimeException;

/** A source couldn't be checked: unreachable, refused, blocked, or not a flyer. */
final class FetchFailed extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
