# Changelog

All notable changes to `atannex/bridge` are listed here. The project follows
[Semantic Versioning](https://semver.org).

## 1.0.0 — 2026-10-31

First release.

- Protocol version 1 (`PROTOCOL.md`): `POST {prefix}/call/{operation}`,
  `GET {prefix}/health`, signed downloads, optional mobile token endpoints.
- Explicit registration (`Bridge::action()`, or `#[Operation]` in namespaces
  listed under `bridge.discovery`), duplicate detection, compiled cache
  (`bridge:cache`, hooked into `php artisan optimize`).
- Fixed execution pipeline: principal → tenant → body limits → rate limit →
  function-level authorization (deny by default) → idempotency → validation →
  record-level authorization → action → output allowlist.
- Schema DSL that drives validation rules, typed input, output allowlisting
  and TypeScript: string, integer, number, decimal, boolean, date, date-time,
  enum, list, object, record, file, paginated, download, mixed.
- Standard error envelope for every failure on Bridge routes, including
  errors raised by middleware (CSRF, maintenance, signatures).
- Laravel guards for authentication; Sanctum SPA sessions; Sanctum mobile
  tokens with Fortify two-factor challenges, recovery codes, rate limiting,
  revocation and refresh (`POST {prefix}/auth/refresh`); token responses
  report `expires_at`.
- Tenant context through a `TenantResolver`; client selection is verified.
- Idempotency-Key storage and replay for idempotent operations.
- Signed, expiring, user-bound download links.
- Typed broadcast events (`#[Event]`, `BroadcastsThroughBridge`).
- Commands: `bridge:install`, `bridge:generate [--check]`, `bridge:list`,
  `bridge:cache`, `bridge:clear`, `bridge:doctor`.
- `OperationStarting` / `OperationHandled` events and an optional log channel.
