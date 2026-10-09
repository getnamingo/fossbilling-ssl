<?php
/** Copyright 2026 Namingo. SPDX-License-Identifier: Apache-2.0 */
declare(strict_types=1);
namespace Box\Mod\Servicessl\Issuer;

/** Offline subscription simulator. This is deliberately NOT an ACME issuer. */
final class Emulator implements IssuerInterface
{
    public function describe(): array
    {
        return ['label' => 'Simulation only — no certificates', 'managed' => true,
            'emulated' => true, 'wildcard' => true];
    }
    public function reconcile(array $request, array $previous): array
    {
        $key = $request['subscription_key'];
        return ['subscription_id' => 'sim-' . $key, 'state' => $request['state'],
            'domains' => $request['domains'], 'expires_at' => $request['expires_at'],
            'directory_url' => 'https://acme.emulator.invalid/directory',
            'eab' => ['kid' => 'sim-' . $key, 'hmac_key' => rtrim(strtr(base64_encode(hash('sha256', 'SIMULATION-ONLY:' . $key, true)), '+/', '-_'), '=')],
            'client_ready' => false];
    }
}
