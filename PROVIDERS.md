# Future commercial issuer adapters

`Servicessl/Issuer/IssuerInterface.php` is the billing provider boundary. Register trusted implementations in `Servicessl/providers.php`, which returns a callable:

```php
<?php
return static function (
    \Box\Mod\Servicessl\Issuer\Registry $registry,
    \Pimple\Container $di
): void {
    $registry->register('supplier_profile', new YourInstalledIssuerAdapter(/* private settings */));
};
```

An adapter implements `describe()` with public `label`, `managed`, `emulated`, `wildcard` capabilities and `reconcile(array $request, array $previous): array`. The request/response fields and issuer enforcement requirements are in [ISSUER_API.md](ISSUER_API.md). The previous response is private and already decrypted; never log it. Registration runs trusted local PHP code only; a product/customer cannot select an arbitrary PHP class or remote URL.

The supplied `JsonApi` is a working generic REST connector with operator-side profiles configured outside the web root. It is not a commercial CA adapter. No CentralNic, Sectigo, DigiCert, SSL.com reseller or other paid supplier implementation is included.