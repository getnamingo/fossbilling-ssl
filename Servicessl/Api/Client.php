<?php
/** Copyright 2026 Namingo. SPDX-License-Identifier: Apache-2.0 */
declare(strict_types=1);
namespace Box\Mod\Servicessl\Api;
class Client extends \FOSSBilling\Api\AbstractApi
{
    public function handoff($data): array { $this->postOnly(); return $this->getService()->handoff($data, $this->getIdentity()); }
    public function pairing($data): array { $this->postOnly(); return $this->getService()->pairing($data, $this->getIdentity()); }
    public function revoke_pairing($data): bool { $this->postOnly(); return $this->getService()->revokePairing($data, $this->getIdentity()); }
    public function refresh($data): array { $this->postOnly(); return $this->getService()->sync($data, $this->getIdentity()); }
    public function history($data): array { return $this->getService()->history($data, $this->getIdentity()); }
    public function download($data): array { return $this->getService()->download($data, $this->getIdentity()); }
    private function postOnly(): void
    {
        if (PHP_SAPI !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new \FOSSBilling\InformationException('Use POST for SSL setup operations.');
        header('Cache-Control: no-store');
    }
}
