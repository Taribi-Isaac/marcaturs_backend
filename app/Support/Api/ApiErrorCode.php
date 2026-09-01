<?php

namespace App\Support\Api;

/**
 * Transport-level API error codes. Business-domain codes belong in later tasks.
 */
final class ApiErrorCode
{
    public const VALIDATION_ERROR = 'validation_error';

    public const UNAUTHENTICATED = 'unauthenticated';

    public const FORBIDDEN = 'forbidden';

    public const NOT_FOUND = 'not_found';

    public const CONFLICT = 'conflict';

    public const BUSINESS_VALIDATION = 'business_validation';

    public const RATE_LIMITED = 'rate_limited';

    public const SERVER_ERROR = 'server_error';

    public const SERVICE_UNAVAILABLE = 'service_unavailable';
}
