# Issuer provisioning contract, version 1

This is Namingo's own neutral subscription management API. The production issuer provides a separate RFC 8555 ACME directory that a customer client uses directly. No paid supplier adapter or production ACME server is included.

## Request

The billing module makes an authenticated HTTPS request:

```http
PUT /v1/subscriptions/{subscription_key}
Authorization: Bearer <operator-management-token>
Idempotency-Key: <operation_id>
Content-Type: application/json
Accept: application/json
```

```json
{
  "schema_version": 1,
  "subscription_key": "912ae2d51c08c5c1dcd0ecfc8c6f6d2a",
  "operation_id": "72c7db5c0e55444c88940891e5d75310",
  "revision": 1,
  "order_id": 123,
  "state": "active",
  "expires_at": "2027-10-09T12:00:00Z",
  "domains": ["example.com", "*.example.com"],
  "environment": "production",
  "plan": "DV-WILDCARD"
}
```

The random 32-character subscription key is stable across the life of one service. It is not a client credential. `operation_id` is a random 32-character operation key; `revision` increases for each new absolute desired state. A retry uses the same exact payload, operation and revision. The local billing order ID is metadata, not a global primary key.

Allowed states are `active`, `suspended`, `canceled`, `expired`, `deleted`. Managed active subscriptions require a finite UTC expiry. Direct Let's Encrypt handoff is a local adapter and may be perpetual. The exact domains originate in administrator product settings plus the customer's normalized domain. The issuer must authorize the plan, environment, identifiers and operation before accepting it.

## Confirmed response

Return `200` or `201`, JSON, after the requested state/coverage has actually been applied:

```json
{
  "schema_version": 1,
  "subscription_id": "issuer-subscription-456",
  "state": "active",
  "expires_at": "2027-10-09T12:00:00Z",
  "domains": ["example.com", "*.example.com"],
  "directory_url": "https://acme.example.net/directory",
  "eab": {
    "kid": "per-service-external-account-id",
    "hmac_key": "RANDOM_BASE64URL_ENCODED_HMAC_KEY_WITHOUT_PADDING"
  },
  "client_ready": true
}
```

The returned domains, expiry and state must equal the desired entitlement. Domain order is immaterial; additional names are rejected. `directory_url` must be HTTPS without query, fragment or embedded username/password. The EAB HMAC key is unpadded base64url (32–512 characters); `kid` is a nonempty identifier up to 512 characters. Use a cryptographically random key of at least 256 bits. The billing module encrypts the returned credentials and only releases them through the separately authorized setup API. Responses must stay below 64 KiB. Unknown response fields are discarded.

The directory and EAB belong to this service's account. They must not be a shared reseller account with unrestricted access to other customers' names. EAB credentials bind the customer-generated account key using RFC 8555 external account binding. Keep the association, account ID and existing credentials stable across renew/suspend/restore. `client_ready=false` means provisioning is confirmed but the client cannot yet use that account; a real profile's setup is withheld until ready. An emulator always returns false and is identified as a simulation profile.

## Issuer obligations

- Make the request idempotent even after a timeout or lost response. Repeating an operation must return the same account and credentials and must not create a new charge.
- Reject stale revisions (`409`) so a late active request cannot override a more recent suspension/cancellation. Reject reuse of the same revision for different content. Scope keys to the authenticated operator.
- Apply absolute expiry and exact identifier coverage. Subscription renewal extends coverage, not certificate validity. Future fixed-term supplier adapters must translate or reject unsupported billing periods rather than invent coverage.
- Enforce subscription state, expiry and allowed identifiers on ACME newOrder for already bound accounts. Disabling EAB registration alone does not stop a registered account from issuing.
- Enforce expiry at the issuer itself, independently of billing cron. A wildcard permits only the explicitly subscribed wildcard/apex identifiers as defined by ACME wildcard semantics.
- Keep previously issued certificates intact on suspend/cancel/delete. Explicit revocation belongs to the ACME client unless a future paid adapter adds an explicit operation.
- Disable automatic upstream renewal unless that renewal is authorized by billing. Do not blindly buy another year for every synchronization PUT.
- Never return an operator token, shared reseller credentials or an ACME/certificate private key.
- Confirm `deleted` as an idempotent closure/tombstone so retries can recover. The connector expects the same response shape, including account metadata/EAB, for disabled/deleted states; credentials must no longer authorize issuance. The billing module removes its local record only after confirmation.

Any transport failure, non-200/201 response, invalid JSON, schema mismatch or mismatched entitlement leaves a durable pending request and blocks client setup. Error bodies are not logged or shown. Lifecycle failures (including suspend/cancel/delete) are retried through FOSSBilling order actions; synchronization failures can be retried from the service page or cron. A later lifecycle cancel/suspend/delete can supersede failed active provisioning with a higher revision.

This release does not poll `202` operations or implement supplier-specific grace periods, prorated multi-domain additions, refund rules, account key rotation or commercial price checks. Those belong in future adapters and must be represented explicitly before selling such behavior.

## Built-in and remote simulators

The built-in `emulator` profile runs the same subscription lifecycle entirely in the module. Its EAB is deterministic simulation data, not a security credential. It always uses `https://acme.emulator.invalid/directory` and `client_ready=false`.

The standalone Python 3 simulator in `examples/provisioning-emulator/server.py` exercises the real HTTPS connector with persisted subscriptions, random fake EAB keys, revision conflict rejection and an optional lost-confirmation fault. **It does not implement ACME, domain validation, certificates, installation or a CA.**

Generate a local test TLS certificate with a localhost SAN:

```bash
openssl req -x509 -newkey rsa:2048 -nodes -days 7 \
  -subj '/CN=localhost' -addext 'subjectAltName=DNS:localhost' \
  -keyout simulator.key -out simulator.pem
chmod 600 simulator.key
export NAMINGO_SIMULATOR_TOKEN='<a-random-token-of-at-least-24-characters>'
python3 examples/provisioning-emulator/server.py \
  --cert simulator.pem --key simulator.key --database simulator.sqlite --port 9443
```

Enable the example `remote_simulator` issuer profile with `emulated=true`, the HTTPS URL, matching token and `ca_file` pointing to the simulator certificate. PHP uses normal TLS verification against that certificate. `--fail-once` commits the first operation and responds 503, allowing you to verify that billing retries preserve the same account. Never use the simulator as a production issuer.
