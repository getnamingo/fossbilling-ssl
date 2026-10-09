<?php
/** Copyright 2026 Namingo. SPDX-License-Identifier: Apache-2.0 */
declare(strict_types=1);
namespace Box\Mod\Servicessl\Api;
class Admin extends \FOSSBilling\Api\AbstractApi
{
    public function providers($data = []): array { $this->checkPermissions('servicessl', 'manage'); return $this->getService()->providers()->labels(); }
    public function issuer_info($data = []): array { $this->checkPermissions('servicessl', 'manage'); return $this->getService()->providers()->descriptions(); }
    public function handoff($data): array { $this->authorize(); return $this->getService()->handoff($data, $this->getIdentity()); }
    public function pairing($data): array { $this->authorize(); return $this->getService()->pairing($data, $this->getIdentity()); }
    public function revoke_pairing($data): bool { $this->authorize(); return $this->getService()->revokePairing($data, $this->getIdentity()); }
    public function refresh($data): array { $this->authorize(); return $this->getService()->sync($data, $this->getIdentity()); }
    public function retry($data): array { $this->authorize(); return $this->getService()->retry($data, $this->getIdentity()); }
    public function history($data): array { $this->checkPermissions('servicessl', 'manage'); return $this->getService()->history($data, $this->getIdentity()); }
    public function download($data): array { $this->checkPermissions('servicessl', 'manage'); return $this->getService()->download($data, $this->getIdentity()); }
    private function authorize(): void
    {
        $this->checkPermissions('servicessl', 'manage');
        if (PHP_SAPI !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new \FOSSBilling\InformationException('Use POST for SSL setup operations.');
        header('Cache-Control: no-store');
    }
}
