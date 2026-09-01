# MarcatursHub API foundation

URI versioning (Technical Architecture Document §48):

```
/api/v1/...
```

The only business-agnostic endpoint in MH-BE-001 is health.

## Health

`GET /api/v1/health`

Unauthenticated. Excluded from API rate limiting and from HTTP maintenance blocking so load balancers can probe it.

Operational database:

- `200` with `data.status` = `ok` or `degraded`
- `503` with `data.status` = `unavailable` when the database cannot be reached

Example (operational):

```json
{
  "success": true,
  "data": {
    "status": "ok",
    "service": "marcaturshub-api",
    "api_version": "v1",
    "timestamp": "2026-09-01T17:00:00+00:00",
    "checks": {
      "application": "ok",
      "database": "ok",
      "cache": "skipped"
    }
  }
}
```

`checks.cache` is `ok`, `unavailable`, or `skipped` (skipped when Redis is not the configured cache/queue backend, including the test suite).

The payload must not include secrets, credentials, `APP_KEY`, database passwords, or dump environment configuration.

## Success envelope

```json
{
  "success": true,
  "data": {},
  "meta": {
    "pagination": {
      "current_page": 1,
      "per_page": 15,
      "total": 0,
      "last_page": 1,
      "from": null,
      "to": null
    }
  }
}
```

`meta` is omitted when unused. Pagination helpers live in `App\Support\Api\ApiResponse`.

## Error envelope

TAD §61 status categories:

| HTTP | Code | Meaning |
| --- | --- | --- |
| 400 | `validation_error` | Request/input validation |
| 401 | `unauthenticated` | Authentication required |
| 403 | `forbidden` | Authorization failure |
| 404 | `not_found` | Missing resource |
| 409 | `conflict` | State conflict |
| 422 | `business_validation` | Business-rule validation |
| 429 | `rate_limited` | Rate limit exceeded |
| 500 | `server_error` | Unexpected failure |
| 503 | `service_unavailable` | Dependency/process unavailable |

```json
{
  "success": false,
  "error": {
    "code": "validation_error",
    "message": "The given data was invalid.",
    "details": {
      "email": ["The email field is required."]
    }
  }
}
```

Input validation uses HTTP 400, not Laravel's default 422. HTTP 422 is reserved for business-rule failures.

## Rate limiting

Named limiters (configurable via environment variables):

- `api`
- `login`
- `registration`
- `password-reset`
- `uploads`

Apply the named limiter when the corresponding route is implemented.

## Authentication

Sanctum is installed and the `User` model uses `HasApiTokens`. CSRF cookie route: `GET /sanctum/csrf-cookie`.

Session authentication for the first-party web client is the TAD default. JWT is not used. Login, logout, and registration endpoints are not part of this foundation task.
