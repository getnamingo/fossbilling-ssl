<?php
/** Copyright 2026 Namingo. SPDX-License-Identifier: Apache-2.0 */
declare(strict_types=1);
namespace Box\Mod\Servicessl\Issuer;

final class Registry
{
    private array $issuers = [];
    public function register(string $id, IssuerInterface $issuer): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,40}$/D', $id) || isset($this->issuers[$id])) {
            throw new \InvalidArgumentException('Invalid or duplicate SSL issuer profile.');
        }
        $this->issuers[$id] = $issuer;
    }
    public function get(string $id): IssuerInterface
    {
        return $this->issuers[$id] ?? throw new \RuntimeException('SSL issuer profile is not configured.');
    }
    public function descriptions(): array
    {
        return array_map(static fn (IssuerInterface $issuer) => $issuer->describe(), $this->issuers);
    }
    public function labels(): array
    {
        return array_map(static fn (array $info) => $info['label'], $this->descriptions());
    }
}
