# Atannex Bridge wire protocol, version 1

This document is the contract between `atannex/bridge` (Laravel) and
`@atannex/bridge` (TypeScript). Both packages are tested against it. A change
that breaks a conforming client or server needs a new protocol version.

## Endpoints

All paths sit under a configurable prefix, `bridge/v1` by default.

| Method | Path                     | Purpose                                         |
|--------|--------------------------|-------------------------------------------------|
| POST   | `/call/{operation}`      | Run one registered operation                    |
| GET    | `/health`                | Liveness. Body is `{"data":{"status":"ok"},"meta":{...}}` and nothing else |
| GET    | `/files/{token}`         | Download a file through a signed, expiring link |
| POST   | `/auth/token`            | Mobile login (optional, off by default)         |
| POST   | `/auth/two-factor`       | Finish a mobile login that needs a second factor|
| POST   | `/auth/refresh`          | Swap a live bearer token for a new one          |
| POST   | `/auth/logout`           | Revoke the bearer token that made the request   |
| GET    | `/auth/user`             | The authenticated user (allowlisted fields only)|

There is no public endpoint that lists operations. Contracts come from
`php artisan bridge:generate`.

`{operation}` matches `^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$` and is at most
120 characters. Anything else, and any name that is not registered, gets 404
`NOT_FOUND`. An operation name is never echoed back unless it is registered.

## Request

### Headers

| Header              | Direction | Meaning |
|---------------------|-----------|---------|
| `X-Bridge-Version`  | request   | Protocol version. Optional; missing means `1`. Unsupported value → 400 `UNSUPPORTED_VERSION`. |
| `X-Request-Id`      | both      | Optional on requests. Kept only if it matches `^[A-Za-z0-9._:-]{8,128}$`, otherwise the server generates one. Always on responses. |
| `X-Correlation-Id`  | both      | Same pattern. Defaults to the request id. Echoed on responses. |
| `Idempotency-Key`   | request   | Same pattern, 8–128 chars. See Idempotency. |
| `X-Bridge-Tenant`   | request   | A tenant *selection request*. The server verifies membership; the value is never trusted. |
| `X-Bridge-Client`   | request   | Free text `name/version`, at most 64 chars, for logs only. |
| `Accept-Language`   | request   | Locale hint. The server picks only from its configured locales. |
| `Authorization`     | request   | `Bearer <token>` for token authentication. |
| `X-XSRF-TOKEN`      | request   | CSRF token for cookie sessions (Laravel/Sanctum convention). |

### JSON body

```json
{ "input": { "page": 1 } }
```

`Content-Type: application/json`. `input` must be a JSON object, or absent
(treated as `{}`). A body that is not an object, is not valid JSON, nests deeper
than 64 levels, or has any top-level key other than `input` gets 400
`MALFORMED_REQUEST`.

### Multipart body (file uploads)

`Content-Type: multipart/form-data` with:

* a field `input` holding the JSON object as a string, and
* one part per file, named `files[<path>]`, where `<path>` is the dot path of the
  input key the file belongs to, for example `files[avatar]` or
  `files[attachments.0]`.

The server places each file at its path inside `input` before validation. A file
whose path the operation's input schema does not declare as a file is rejected
by validation like any other unknown value would be (it is dropped, then
required-checks apply).

### Size limits

Requests whose `Content-Length` (or actual body) exceeds the configured limit get
413 `PAYLOAD_TOO_LARGE`. JSON and multipart have separate limits.

## Responses

Every response from these endpoints, success or failure, is JSON (except the file
download) with `Content-Type: application/json` and a `meta` object.

### Success — 200

```json
{
  "data": { "id": 7, "name": "Toghu" },
  "meta": {
    "request_id": "01JB...",
    "version": "1",
    "operation": "products.show"
  }
}
```

`data` is whatever the operation's output schema says, and only that: keys not
in the schema are never sent. An operation without an output schema returns
`"data": null`.

`meta` may also carry:

* `"deprecated": { "message": "..." }` — the operation is deprecated. The
  response also has a `Deprecation: true` header.
* `"idempotent_replay": true` — this response was stored and replayed.

### Failure

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The supplied data is invalid.",
    "details": { "quantity": ["The quantity field must be at least 1."] }
  },
  "meta": { "request_id": "01JB...", "version": "1" }
}
```

`details` is present only where listed below. Messages are safe for end users;
they never carry stack traces, SQL, file paths or exception class names.

| HTTP | `code`                  | When | `details` |
|------|-------------------------|------|-----------|
| 400  | `MALFORMED_REQUEST`     | Body not parseable / wrong shape | — |
| 400  | `UNSUPPORTED_VERSION`   | Unknown `X-Bridge-Version` | `{"supported":["1"]}` |
| 400  | `TENANT_REQUIRED`       | Operation needs a tenant and none was resolved | — |
| 401  | `UNAUTHENTICATED`       | Operation needs a user and there is none | — |
| 403  | `FORBIDDEN`             | Authorization denied, or an invalid/expired download link | — |
| 403  | `HTTPS_REQUIRED`        | The server requires HTTPS and the call was plain HTTP | — |
| 403  | `TENANT_FORBIDDEN`      | Requested tenant is not one the user belongs to | — |
| 404  | `NOT_FOUND`             | Unknown operation, or a record the action reports as missing | — |
| 409  | `CONFLICT`              | The action reported a conflict | optional, action-defined |
| 409  | `IDEMPOTENCY_KEY_REUSED`| Same key, different input | — |
| 409  | `IDEMPOTENCY_IN_PROGRESS` | Same key still running | — |
| 413  | `PAYLOAD_TOO_LARGE`     | Body over the limit | `{"limit_bytes":n}` |
| 415  | `UNSUPPORTED_MEDIA_TYPE`| Not JSON or multipart | — |
| 419  | `CSRF_MISMATCH`         | Session request with a bad/missing CSRF token | — |
| 422  | `VALIDATION_FAILED`     | Input failed the schema | `{ "<dot.path>": ["message", ...] }` |
| 422  | `TWO_FACTOR_FAILED`     | Wrong or expired second-factor code | — |
| 429  | `RATE_LIMITED`          | Too many requests | `{"retry_after": seconds}`; also `Retry-After` header |
| 500  | `INTERNAL_ERROR`        | Anything unexpected, including an output that broke its schema | — |
| 503  | `SERVICE_UNAVAILABLE`   | Maintenance mode or a dependency is down | `Retry-After` header when known |

Clients must treat an unknown `code` by its HTTP status, so new codes can be
added within protocol version 1.

## Value conventions

* **Dates and times**: ISO 8601 strings with an explicit offset, in UTC by
  default: `2026-10-30T14:05:00+00:00`. Date-only values: `2026-10-30`.
* **Integers**: JSON numbers. **Decimals** (money, quantities that must not
  lose precision): strings, `"12.50"`.
* **Enums**: the backing value of a PHP backed enum.
* **Null**: a nullable key is always present, with `null` when empty. Optional
  output keys may be absent.
* **Pagination**: a paginated output is an object

  ```json
  {
    "items": [ ... ],
    "pagination": {
      "type": "length_aware",
      "per_page": 15, "current_page": 1, "has_more": true,
      "total": 42, "last_page": 3
    }
  }
  ```

  `type` is `length_aware` (adds `total`, `last_page`), `simple`, or `cursor`
  (replaces `current_page` with `next_cursor` and `prev_cursor`, both
  strings or null).
* **Download links**: `{ "url": "...", "name": "invoice.pdf", "expires_at": "<ISO 8601>" }`.
  The URL is signed and expires; it may be bound to the user who asked for it.

## Idempotency

Each operation is one of:

* `safe` — reads only. Clients may retry it freely.
* `idempotent` — writes, but the server honours `Idempotency-Key`. The first
  response (2xx or 4xx other than 409/429) is stored for the configured TTL,
  scoped to operation + user + key, and replayed for the same key and the same
  input. A different input with the same key gets 409
  `IDEMPOTENCY_KEY_REUSED`. Clients may retry only when they sent a key.
  For guests the scope is the session, or the client IP when there is no
  session, so guests never share stored responses.
* `unsafe` — the default. Clients never retry automatically.

The level of each operation is part of the generated contract.

## Mobile token authentication

Off unless `bridge.auth.tokens.enabled` is true and `laravel/sanctum` is
installed.

`POST /auth/token` with `{"email","password","device_name"}`:

* 200 `{"data":{"status":"authenticated","token":"<plain>","token_type":"Bearer","expires_at":"<ISO 8601>|null","user":{...}}}`
  (`expires_at` is when the token stops working, null for never)
* 200 `{"data":{"status":"two_factor_required","challenge":"<opaque>","expires_at":"..."}}`
  when the user has confirmed two-factor authentication. No token is issued.
* 422 `VALIDATION_FAILED` with `details.email` for wrong credentials (one
  generic message; it does not say whether the account exists).
* 429 `RATE_LIMITED`.

`POST /auth/two-factor` with `{"challenge", "code"}` or
`{"challenge", "recovery_code"}` → the `authenticated` shape above, or 422
`TWO_FACTOR_FAILED`. A challenge is single-use, expires, and is burned after
too many wrong codes. Wrong codes also count per user across challenges;
over that limit both endpoints answer 429 `RATE_LIMITED` for that user until
it decays, even with the right password or code.

Token login applies the app's own login rules: a Bridge
`authenticateTokensUsing()` callback, else Fortify's `authenticateUsing()`
callback when set.

`POST /auth/refresh`, sent with a live bearer token and no body, revokes
that token and returns the `authenticated` shape above with a new token of
the same name and abilities and a fresh expiry. There is no separate refresh
token: an expired or revoked token gets 401 and the user signs in again. Of
two refreshes racing with one token, only one succeeds; the other gets 401.
A cookie session cannot be refreshed here (401).

`POST /auth/logout` revokes the token that sent it and returns `{"data":null}`.

`GET /auth/user` returns the user's allowlisted fields, or 401.

## Realtime

Bridge does not run a socket server. Events go out through Laravel
broadcasting; registered events have a stable name (`broadcastAs`) and a
payload schema, and the generated contract types them. Private and presence
channels are authorized by Laravel's `/broadcasting/auth` as usual.
