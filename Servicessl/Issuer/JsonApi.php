<?php
/** Copyright 2026 Namingo. SPDX-License-Identifier: Apache-2.0 */
declare(strict_types=1);
namespace Box\Mod\Servicessl\Issuer;

/** Generic Namingo provisioning contract. Not a CentralNic API adapter. */
final class JsonApi implements IssuerInterface
{
    public function __construct(private array $settings)
    {
        self::httpsUrl($settings['base_url'] ?? '');
        if (!is_string($settings['token'] ?? null) || strlen($settings['token']) < 24
            || preg_match('/[\r\n]/', $settings['token'])) {
            throw new \InvalidArgumentException('Issuer API token must contain at least 24 characters without line breaks.');
        }
    }
    public static function httpsUrl(mixed $url): string
    {
        if (!is_string($url) || strlen($url) > 2048 || preg_match('/[\s\x00-\x1f]/', $url)) {
            throw new \InvalidArgumentException('Issuer URL must be a valid HTTPS URL.');
        }
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['query'])
            || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Issuer URL must use HTTPS without user information, query or fragment.');
        }
        return $url;
    }
    public function describe(): array
    {
        return ['label' => (string) ($this->settings['label'] ?? 'Managed ACME issuer'),
            'managed' => true, 'emulated' => (bool) ($this->settings['emulated'] ?? false), 'wildcard' => (bool) ($this->settings['wildcard'] ?? true)];
    }
    public function reconcile(array $request, array $previous): array
    {
        $url = rtrim($this->settings['base_url'], '/') . '/v1/subscriptions/' . rawurlencode($request['subscription_key']);
        $curl = curl_init($url);
        if ($curl === false) throw new \RuntimeException('Issuer transport unavailable.');
        $body = ''; $tooLarge = false;
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => json_encode($request, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->settings['token'], 'Content-Type: application/json',
                'Accept: application/json', 'Idempotency-Key: ' . $request['operation_id']],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > 65536) { $tooLarge = true; return 0; }
                $body .= $chunk; return strlen($chunk);
            }]);
        if (isset($this->settings['ca_file'])) curl_setopt($curl, CURLOPT_CAINFO, $this->settings['ca_file']);
        $success = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        // Never return response bodies or transport error text: they can contain secrets.
        if ($success === false || $tooLarge || !in_array($status, [200, 201], true)) {
            throw new \RuntimeException('Issuer provisioning could not be confirmed.');
        }
        try { $result = json_decode($body, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new \RuntimeException('Issuer response is invalid.'); }
        if (!is_array($result) || ($result['schema_version'] ?? null) !== 1) {
            throw new \RuntimeException('Issuer response schema is invalid.');
        }
        return $result;
    }
}
