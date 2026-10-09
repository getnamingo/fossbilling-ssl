<?php
/** Copyright 2026 Namingo. SPDX-License-Identifier: Apache-2.0 */
declare(strict_types=1);
namespace Box\Mod\Servicessl\Api;
class Guest extends \FOSSBilling\Api\AbstractApi
{
    public function options($data): array
    {
        $id = filter_var($data['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) throw new \FOSSBilling\InformationException('SSL product ID is required.');
        return $this->getService()->productOptions($id);
    }
    /** The random token is restricted to ONE service; it is not a FOSSBilling client API key. */
    public function bootstrap($data): array
    {
        if (PHP_SAPI !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new \FOSSBilling\InformationException('Use POST for SSL bootstrap.');
        header('Cache-Control: no-store');
        return $this->getService()->bootstrap($data);
    }
}
