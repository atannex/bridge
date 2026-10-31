# Atannex Bridge

Lets JavaScript and TypeScript apps (web, React Native, Expo) call Laravel actions you register explicitly, over one small versioned HTTP endpoint. Every call is validated and authorized on the server, deny by default, and returns only the fields you declare. A generated TypeScript contract gives the client full types through the `@atannex/bridge` SDK. Works with Sanctum cookie sessions or mobile bearer tokens, Fortify two-factor, tenants, file uploads and signed downloads. The wire format is described in [PROTOCOL.md](PROTOCOL.md).

## Install

```bash
composer require atannex/bridge
php artisan bridge:install
```
