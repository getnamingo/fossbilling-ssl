<?php
/** Copyright 2026 Namingo. SPDX-License-Identifier: Apache-2.0 */
declare(strict_types=1);

namespace Box\Mod\Servicessl;

final class Domain
{
    public static function normalize(mixed $value, bool $wildcard = false): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('A valid domain name is required.');
        }
        $name = strtolower(rtrim(trim($value), '.'));
        $prefix = $wildcard && str_starts_with($name, '*.') ? '*.' : '';
        if ($prefix !== '') {
            $name = substr($name, 2);
        }
        if (function_exists('idn_to_ascii')) {
            $name = idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '';
        }
        if (strlen($prefix . $name) > 253 || !str_contains($name, '.') || filter_var($name, FILTER_VALIDATE_IP)
            || !filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || !preg_match('/\.[a-z][a-z0-9-]*$/D', $name)) {
            throw new \InvalidArgumentException('Enter a domain such as example.com, without a URL, port or path.');
        }
        return $prefix . $name;
    }

    public static function identifiers(string $domain, string $mode): array
    {
        $domain = self::normalize($domain);
        return match ($mode) {
            'single' => [$domain],
            'www' => [$domain, self::normalize('www.' . $domain)],
            'wildcard' => [$domain, self::normalize('*.' . $domain, true)],
            default => throw new \InvalidArgumentException('Unknown certificate domain mode.'),
        };
    }
}
