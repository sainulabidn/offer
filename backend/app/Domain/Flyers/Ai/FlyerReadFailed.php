<?php

namespace App\Domain\Flyers\Ai;

use RuntimeException;

/**
 * The AI couldn't give a usable answer for a page. Tokens may still have been billed, so the usage
 * travels with the failure.
 */
final class FlyerReadFailed extends RuntimeException
{
    /** The model declined the request. */
    public const string REFUSAL = 'refusal';

    /** The answer was cut off at the output limit: the page needs reading in halves. */
    public const string TRUNCATED = 'truncated';

    /** The answer wasn't the JSON asked for. */
    public const string INVALID = 'invalid';

    /** The API failed: network, rate limit, overload, bad key… */
    public const string API = 'error';

    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly ?TokenUsage $usage = null,
        public readonly ?string $model = null,
        public readonly ?string $requestId = null,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}
