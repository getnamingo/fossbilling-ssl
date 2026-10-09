<?php
/** Copyright 2026 Namingo. SPDX-License-Identifier: Apache-2.0 */
declare(strict_types=1);
namespace Box\Mod\Servicessl\Issuer;

final class LetsEncrypt implements IssuerInterface
{
    public function describe(): array
    {
        return ['label' => "Let's Encrypt (direct ACME)", 'managed' => false,
            'emulated' => false, 'wildcard' => true];
    }
    public function reconcile(array $request, array $previous): array
    {
        return ['subscription_id' => $request['subscription_key'], 'state' => $request['state'],
            'domains' => $request['domains'], 'expires_at' => $request['expires_at'],
            'directory_url' => $request['environment'] === 'production'
                ? 'https://acme-v02.api.letsencrypt.org/directory'
                : 'https://acme-staging-v02.api.letsencrypt.org/directory',
            'eab' => null, 'client_ready' => $request['state'] === 'active'];
    }
}
