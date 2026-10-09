# Customer ACME tool contract, version 1

The module provisions subscriptions and supplies this configuration. The customer tool will be developed separately. CoyoteCert can be used on that server to communicate directly with an RFC 8555 issuer. This module does not run CoyoteCert or operate an ACME server.

## Setup download

An authenticated owner (or staff with `servicessl.manage`) POSTs `order_id` to `/api/client/servicessl/handoff` (or the admin equivalent). The standard FOSSBilling response wrapper is:

```json
{
  "result": {
    "filename": "namingo-ssl-123.json",
    "config": {
      "schema_version": 1,
      "order_id": 123,
      "issuer": "namingo_issuer",
      "directory_url": "https://acme.example.net/directory",
      "domains": ["example.com", "*.example.com"],
      "environment": "production",
      "coverage_expires_at": "2027-10-09T12:00:00Z",
      "managed": true,
      "emulated": false,
      "client_ready": true,
      "eab": {
        "kid": "per-service-external-account-id",
        "hmac_key": "UNPADDED_BASE64URL_HMAC_KEY"
      },
      "private_key_location": "customer_server",
      "terms_acceptance_required": true
    }
  },
  "error": null
}
```

The downloaded file contains `config`, not the surrounding API wrapper. Direct Let's Encrypt uses `eab=null` and `managed=false`. Its endpoint is selected by the product environment. Free/perpetual direct services may have `coverage_expires_at=null`. Simulation profiles use `emulated=true`, `client_ready=false`, an `.invalid` endpoint and fake credentials; a real customer tool must refuse to issue from them.

The account is not registered by FOSSBilling. The tool generates and persists its own ACME account key, agrees to CA terms after customer acceptance, registers it using the supplied EAB when applicable, and persists the returned ACME account URL. It generates certificate private keys/CSRs locally, performs validation, downloads certificates, installs/reloads services and schedules certificate renewal. It must preserve the account key between runs; re-fetching the EAB does not replace a lost bound account key.

Configuration must be stored with `0600` permissions and secrets must stay out of process command lines and logs. DNS API credentials and installer choices are configured locally by the future tool. The configured domains are exact coverage; billing does not accept an arbitrary customer SAN list.

## Service-scoped pairing

Authenticated owner POST `/api/client/servicessl/pairing` with `order_id` returns:

```json
{
  "order_id": 123,
  "token": "A_43_CHARACTER_RANDOM_BASE64URL_PAIRING_TOKEN",
  "api_path": "/api/guest/servicessl/bootstrap"
}
```

This result is inside the normal FOSSBilling `result` wrapper. Supply the billing installation's trusted HTTPS base URL separately. For installations in a subdirectory, retain that base path and append `/api/guest/servicessl/bootstrap` rather than discarding the subdirectory. Do not accept a different host/base URL from an unauthenticated discovery response.

The tool POSTs JSON `{ "order_id": 123, "token": "..." }` to that guest endpoint. The `result` is the current **config object** above. No FOSSBilling-wide API key or authenticated browser session is necessary. Do not send cookies or a browser Referer from the server tool. FOSSBilling's configured API role/IP access rules still apply. Honor the normal `{result,error}` response: an HTTP 200 with `error` is a failure, not a configuration.

The module stores only a SHA-256 token hash. The 256-bit random token grants setup retrieval for exactly one order. The tool must not put the token in a URL/query string. Invalid tokens, inactive/expired orders, incomplete provisioning or an unready real issuer cannot retrieve setup. API routes enforce POST and `Cache-Control: no-store`.

Creating another pairing token invalidates the old one. POST `/api/client/servicessl/revoke_pairing` with `order_id` revokes it; revocation is allowed even while suspended/canceled. Cancellation clears the pairing hash. Suspension denies retrieval while preserving the hash for restoration. Restoring a canceled service requires creating a new token.

Pairing rotation/revocation affects billing setup access, not an already-bound CA account. A managed issuer must separately enforce account state/coverage. Direct public Let's Encrypt cannot be gated by a billing subscription.

## Other billing APIs

| Role | Method | Effect |
|---|---|---|
| Guest | `servicessl/options` | Public configuration of an active SSL product (`product_id`) |
| Client/admin | `servicessl/refresh` POST | Reconcile existing billing state/expiry; cannot buy/extend coverage |
| Admin | `servicessl/retry` POST | Retry pending synchronization; all lifecycle retries use normal order actions |
| Client/admin | `servicessl/history` | Last 30 safe provisioning/setup events (`order_id`) |
| Client/admin | `servicessl/download` | Version-1 certificate history only (`order_id`, `certificate_id`, optional `format`) |
| Admin | `servicessl/providers` | Issuer selector labels |
| Admin | `servicessl/issuer_info` | Public issuer capabilities |

Normal `order/service` data includes status, public directory and coverage but no EAB, pairing token or issuer-management credentials. There are no version-2 billing API methods for submitting CSRs, challenges, certificates, private keys or arbitrary issuer URLs. Revoke certificates through the ACME client.
