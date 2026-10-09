# Namingo SSL subscriptions for FOSSBilling 0.8.8

Version 1.0.0 · Apache-2.0 · PHP 8.3+

A complete billing-side `ssl` product module using the same third-party product integration as Namingo DNS. The billing platform sells **domain coverage**, provisions an issuer account and supplies an **ACME directory URL and, for managed issuers, per-service EAB credentials**. A customer-side ACME tool will register that account, validate domains, issue, install and renew certificates. Certificate and ACME account private keys stay on the customer server.

This replaces the first version's panel-driven CSR/DNS issuance workflow. No customer tool or paid CA adapter is included in this release. CoyoteCert belongs in that future customer tool; the billing module has no Composer dependency on it.

## Included

- Free or paid SSL products with ordinary FOSSBilling pricing and invoices.
- Required domain, IDN normalization, single-name, name + www, or apex + wildcard coverage.
- Direct Let's Encrypt staging and production endpoint handoff.
- An offline subscription simulator with fake EAB credentials, clearly marked as unable to issue certificates.
- A real HTTPS connector for the documented generic Namingo issuer provisioning contract.
- Activation, paid coverage renewal, suspension, restoration, cancellation, expiry synchronization and deletion.
- Persistent idempotent provisioning requests, monotonically increasing revisions, per-service locks and recovery after a lost confirmation.
- Encrypted EAB credentials, ownership checks, staff permissions, service-scoped pairing tokens and safe audit history.
- Customer/admin subscription panels and ACME setup JSON downloads.
- Versioned contracts for the future customer tool and future commercial reseller adapters.
- A standalone HTTPS management API simulator for integration testing.
- Preservation and downloads of historical certificates from version 1.

## Install

Requirements: **FOSSBilling 0.8.8**, PHP **8.3+**, cURL/OpenSSL and the usual FOSSBilling extensions, MySQL/MariaDB with InnoDB. The module uses FOSSBilling's existing autoloader and crypto service. Keep your normal FOSSBilling cron enabled.

1. Back up the FOSSBilling database and configuration.
2. Copy `Servicessl/` into the FOSSBilling `modules/` directory. The resulting path must be `modules/Servicessl/Service.php`; in a source checkout it is `src/modules/Servicessl/Service.php`.
3. In **Extensions → Overview**, install and activate **Namingo SSL**. It creates the module tables in `schema.sql`. Clear FOSSBilling's cache if necessary. On an upgrade apply `Servicessl/schema.sql` once with your normal database administration tool before accepting orders; the statements are idempotent. FOSSBilling’s extension update route does not perform local module upgrades in this version.
4. Create a product of type **SSL**. Set the price in the normal pricing tab: zero/free for a free service, a chosen price for managed service, and a recurring period for finite coverage. Managed issuer products require a recurring period; direct Let's Encrypt can also be free or one-time/perpetual.
5. In its SSL configuration choose an issuer profile, covered names, environment and optional issuer plan/SKU. Start with **Simulation only** for billing lifecycle tests, or **Let's Encrypt / staging** for an actual ACME endpoint.
6. Activate an order. Open its service page and download **ACME setup**. The setup contains the directory, covered names, expiry and EAB information where applicable. Protect it with file permissions `0600` on the customer server.
7. Grant **Manage SSL subscriptions** to staff who need provisioning or client setup access. This permission permits access to EAB secrets.

Creating a production product changes new services only. Existing orders retain their issuer/coverage/environment snapshot. Each order is one account/coverage subscription; adding names later and multi-order account aggregation are deliberately outside this contract.

## Configure an actual issuer provisioning API

A managed endpoint needs an issuer service that implements [ISSUER_API.md](ISSUER_API.md). It must provide an actual ACME server and enforce active subscription, expiry and allowed identifiers at that server, including on already-bound accounts. A management API alone does not issue certificates. This package includes the billing connector and test simulators, not that production issuer server.

Copy `examples/issuers.php` **outside the web root**, for example `/etc/namingo/ssl-issuers.php`. Configure the API URL, an operator-only bearer token and supported coverage. Expose `NAMINGO_SSL_ISSUERS_FILE=/etc/namingo/ssl-issuers.php` and any referenced token variables to the PHP-FPM worker environment. PHP-FPM may require explicit `env[...]` entries because `clear_env` defaults to enabled. Give the PHP worker read access while keeping other users out. Restart the worker after configuration changes.

Each configured profile appears in the product issuer selector. Management credentials are never stored in product/cart/order configuration or returned to customers. Do not put real tokens in this module's web-accessible files. The connector requires verified HTTPS, does not follow redirects, limits response size and accepts only synchronous confirmed success. `202 Accepted` is not a provisioned account.

## Billing and certificate renewal

The standard product price buys your SSL service/coverage. A billing renewal extends the domain subscription's absolute expiry. It never requests a certificate and never changes a deployed certificate's expiry. The future customer tool uses the supplied ACME endpoint for issuance and renewal while issuer coverage is active. Wildcards require a supported DNS validation method in that tool.

For direct Let's Encrypt the module supplies the public endpoint without EAB. Let's Encrypt is publicly accessible: cancellation blocks your service setup/pairing, but cannot prevent a customer from using Let's Encrypt independently or stop an already-configured ACME client. Charge for your service, automation or support, not exclusive access to the public CA.

For managed profiles, the upstream issuer must enforce expiry and suspension on ACME orders. Hiding EAB credentials in FOSSBilling is insufficient once an account is bound. Cancellation/suspension stops future issuance through that managed subscription; it does not revoke already-issued certificates. Explicit revocation is the customer's ACME client's responsibility.

Normal cron reconciles effective order state/expiry with the issuer, in bounded batches. A properly implemented managed issuer also enforces the stored expiry itself; it does not depend on billing cron to notice expiry. Cron does not retry failed lifecycle actions: use the normal FOSSBilling order action so both order status and billing expiry are updated correctly. It retries failed synchronization requests. Issuer errors remain visible on the service page.

## Customer tool handoff

[CLIENT_CONTRACT.md](CLIENT_CONTRACT.md) documents the JSON setup and service-scoped pairing API. The module is ready to supply that data to a future CoyoteCert-based tool or another compatible ACME client. No DNS credentials, private keys, software installation or unattended renewals are performed by this module.

The setup download is separate from normal order API responses, which never include EAB secrets. Optional pairing tokens let a tool fetch only one subscription's current setup without a global FOSSBilling client API key. Tokens can be rotated/revoked from the service page. Rotation revokes access to setup retrieval; it does **not** change a CA account key or deactivate an already registered ACME account. Use managed subscription suspension/cancellation for that enforcement.