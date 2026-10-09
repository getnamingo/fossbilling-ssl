<?php
/** Copyright 2026 Namingo. SPDX-License-Identifier: Apache-2.0 */
declare(strict_types=1);
namespace Box\Mod\Servicessl;

use Box\Mod\Servicessl\Issuer\{Registry, IssuerInterface, LetsEncrypt, Emulator, JsonApi};
use RedBeanPHP\OODBBean;

/** Billing entitlement and ACME account provisioning. Certificate keys remain on the customer server. */
class Service implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;
    private ?Registry $registry = null;
    public function setDi(\Pimple\Container $di): void { $this->di = $di; }
    public function getDi(): ?\Pimple\Container { return $this->di; }
    public function getModulePermissions(): array
    {
        return ['manage' => ['type' => 'bool', 'display_name' => __trans('Manage SSL subscriptions'),
            'description' => __trans('Manage issuer provisioning and access client ACME setup credentials.')]];
    }
    public function providers(): Registry
    {
        if ($this->registry === null) {
            $registry = new Registry();
            $registry->register('letsencrypt', new LetsEncrypt());
            $registry->register('emulator', new Emulator());
            $file = getenv('NAMINGO_SSL_ISSUERS_FILE');
            if ($file) {
                if (!str_starts_with($file, '/') || !is_readable($file)) {
                    throw new \RuntimeException('Issuer configuration must be a readable absolute path.');
                }
                $profiles = require $file;
                if (!is_array($profiles)) throw new \RuntimeException('Invalid issuer profiles.');
                foreach ($profiles as $id => $settings) $registry->register((string) $id, new JsonApi($settings));
            }
            // Optional future packages register trusted PHP adapters, never user-submitted class names.
            $file = __DIR__ . '/providers.php';
            if (is_file($file)) {
                $register = require $file;
                if (!is_callable($register)) throw new \RuntimeException('Invalid SSL adapter registration.');
                $register($registry, $this->di);
            }
            $this->registry = $registry;
        }
        return $this->registry;
    }
    protected function issuer(string $id): IssuerInterface { return $this->providers()->get($id); }
    public function clientSettableConfigKeys(): array { return ['domain_name', 'period', 'quantity']; }
    private function validatedConfig(array $config): array
    {
        $config['issuer'] = $config['issuer'] ?? $config['provider'] ?? 'letsencrypt';
        $config += ['domain_mode' => 'single', 'environment' => 'staging', 'issuer_plan' => ''];
        if (!is_string($config['issuer']) || !in_array($config['domain_mode'], ['single', 'www', 'wildcard'], true)
            || !in_array($config['environment'], ['production', 'staging'], true)
            || !is_string($config['issuer_plan']) || strlen($config['issuer_plan']) > 100) {
            throw new \FOSSBilling\InformationException('Invalid SSL product settings.');
        }
        try { $info = $this->issuer($config['issuer'])->describe(); }
        catch (\RuntimeException $e) { throw new \FOSSBilling\InformationException('Select a configured SSL issuer profile.'); }
        if ($config['domain_mode'] === 'wildcard' && empty($info['wildcard'])) {
            throw new \FOSSBilling\InformationException('This issuer profile does not support wildcard coverage.');
        }
        return array_intersect_key($config, array_flip(['issuer', 'domain_mode', 'environment', 'issuer_plan']));
    }
    private function publicConfig(array $config): array
    {
        $info = $this->issuer($config['issuer'])->describe();
        return array_intersect_key($config, array_flip(['issuer', 'domain_mode', 'environment']))
            + ['emulated' => (bool) $info['emulated'], 'managed' => (bool) $info['managed']];
    }
    public function productOptions(int $id): array
    {
        $product = $this->di['mod_service']('product')->findOneActiveById($id);
        if (!$product || $product->getType() !== 'ssl') throw new \FOSSBilling\InformationException('SSL product not found.');
        return $this->publicConfig($this->validatedConfig($this->decode($product->getConfig())));
    }
    public function getCartProductTitle($product, array $data): string
    {
        return (method_exists($product, 'getTitle') ? $product->getTitle() : $product->title) . ' — ' . ($data['domain_name'] ?? '');
    }
    public function validateOrderData(array $data, $product = null): void
    {
        $this->domain($data['domain_name'] ?? '');
        if (isset($data['quantity']) && filter_var($data['quantity'], FILTER_VALIDATE_INT) !== 1) {
            throw new \FOSSBilling\InformationException('Order one SSL subscription per domain.');
        }
    }
    public function attachOrderConfig($product, array $data): array
    {
        $this->validateOrderData($data);
        $config = $this->validatedConfig($this->decode(method_exists($product, 'getConfig') ? $product->getConfig() : $product->config));
        $domain = $this->domain($data['domain_name'] ?? '');
        Domain::identifiers($domain, $config['domain_mode']);
        $public = $this->publicConfig($config) + ['domain_name' => $domain, 'quantity' => 1];
        if (isset($data['period'])) $public['period'] = $data['period'];
        return $public;
    }
    public function create(\Model_ClientOrder|OODBBean $order): OODBBean
    {
        $data = $this->decode($order->config);
        $this->validateOrderData($data);
        $product = $this->di['mod_service']('product')->findProductById((int) $order->product_id);
        $config = $this->validatedConfig($this->decode($product->getConfig()));
        $model = $this->di['db']->dispense('service_ssl');
        $model->client_id = $order->client_id; $model->domain_name = $this->domain($data['domain_name']);
        $model->config = $this->encrypt($config); $model->status = 'pending_setup';
        $model->provider_state = '{}'; $model->challenges = '[]'; $model->retry_at = 0;
        $model->created_at = $model->updated_at = gmdate('Y-m-d H:i:s');
        $this->di['db']->store($model);
        $this->subscription($model);
        $public = $this->publicConfig($config) + ['domain_name' => $model->domain_name, 'quantity' => 1];
        if (isset($data['period'])) $public['period'] = $data['period'];
        $order->config = json_encode($public, JSON_THROW_ON_ERROR); $this->di['db']->store($order);
        return $model;
    }
    public function activate(\Model_ClientOrder|OODBBean $order, OODBBean $model): bool
    { return $this->lifecycle($order, $model, 'activate', 'active', $this->nextExpiry($order, false)); }
    public function renew(\Model_ClientOrder|OODBBean $order, OODBBean $model): bool
    { return $this->lifecycle($order, $model, 'renew', 'active', $this->nextExpiry($order, true)); }
    public function suspend(\Model_ClientOrder|OODBBean $order, OODBBean $model): bool
    { return $this->lifecycle($order, $model, 'suspend', 'suspended', $this->orderExpiry($order)); }
    public function unsuspend(\Model_ClientOrder|OODBBean $order, OODBBean $model): bool
    { return $this->lifecycle($order, $model, 'unsuspend', 'active', $this->orderExpiry($order)); }
    public function cancel(\Model_ClientOrder|OODBBean $order, OODBBean $model): bool
    { return $this->lifecycle($order, $model, 'cancel', 'canceled', $this->orderExpiry($order)); }
    public function uncancel(\Model_ClientOrder|OODBBean $order, OODBBean $model): bool
    { return $this->lifecycle($order, $model, 'uncancel', 'active', $this->nextExpiry($order, false, true)); }

    private function lifecycle($order, OODBBean $model, string $action, string $state, ?string $expires): bool
    {
        return $this->locked($model, function (OODBBean $current) use ($order, $action, $state, $expires): bool {
            $row = $this->subscription($current); $anchor = $this->anchor($order);
            if ($row['pending_request']) {
                if ($row['pending_action'] === $action && $row['last_anchor'] === $anchor) {
                    $request = $this->decrypt($row['pending_request']);
                    $this->send($current, $row, $request, $action, $anchor);
                    if (in_array($state, ['canceled', 'deleted'], true)) $this->setFields($current->id, ['token_hash' => null]);
                    return true;
                }
                // A later suspend/cancel must supersede a failed activation/renewal.
                if (!in_array($action, ['suspend', 'cancel', 'delete'], true)) {
                    throw new \FOSSBilling\InformationException('An issuer operation needs recovery before this action.');
                }
            } elseif ($row['last_action'] === $action && $row['last_anchor'] === $anchor) {
                return true; // Retry after remote success but before FOSSBilling updated the order.
            }
            $request = $this->request($current, $row, $state, $expires, (int) $order->id);
            $this->send($current, $row, $request, $action, $anchor);
            if (in_array($state, ['canceled', 'deleted'], true)) $this->setFields($current->id, ['token_hash' => null]);
            return true;
        });
    }
    private function nextExpiry($order, bool $renew, bool $fromNow = false): ?string
    {
        if (!$order->period) return $this->orderExpiry($order);
        $base = $fromNow || !$order->expires_at ? time() : strtotime((string) $order->expires_at);
        if ($renew) {
            $logic = $this->di['mod_config']('order')['order_renewal_logic'] ?? '';
            if ($logic === 'from_today') $base = time();
            elseif ($logic === 'from_greater') $base = max(time(), $base);
        }
        if ($base === false) throw new \FOSSBilling\InformationException('Invalid billing expiry date.');
        return gmdate('Y-m-d\TH:i:s\Z', $this->di['period']($order->period)->getExpirationTime($base));
    }
    private function orderExpiry($order): ?string
    {
        if (!$order->expires_at) return null;
        $time = strtotime((string) $order->expires_at);
        if ($time === false) throw new \FOSSBilling\InformationException('Invalid billing expiry date.');
        return gmdate('Y-m-d\TH:i:s\Z', $time);
    }
    private function anchor($order): string { return (string) ($order->expires_at ?? '<none>') . '|' . (string) ($order->period ?? ''); }
    private function request(OODBBean $model, array $row, string $state, ?string $expires, int $orderId): array
    {
        $config = $this->config($model); $info = $this->issuer($config['issuer'])->describe();
        if ($info['managed'] && $state === 'active' && $expires === null) {
            throw new \FOSSBilling\InformationException('Managed ACME subscriptions require a recurring product with a finite expiry.');
        }
        return ['schema_version' => 1, 'subscription_key' => $row['subscription_key'],
            'operation_id' => bin2hex(random_bytes(16)), 'revision' => (int) $row['revision'] + 1,
            'order_id' => $orderId, 'state' => $state, 'expires_at' => $expires,
            'domains' => Domain::identifiers($model->domain_name, $config['domain_mode']),
            'environment' => $config['environment'], 'plan' => $config['issuer_plan']];
    }
    private function send(OODBBean $model, array $row, array $request, string $action, string $anchor): void
    {
        $this->setFields($model->id, ['pending_request' => $this->encrypt($request), 'pending_action' => $action,
            'last_anchor' => $anchor, 'desired_state' => $request['state'], 'desired_expires_at' => $request['expires_at'],
            'revision' => $request['revision'], 'next_sync_at' => time() + 60]);
        // Durable intent is written before contacting the issuer. A retry reuses this exact request.
        $pdo = $this->di['pdo']; $ownsTransaction = false;
        try {
            $config = $this->config($model); $issuer = $this->issuer($config['issuer']);
            $previous = $row['remote_payload'] ? $this->decrypt($row['remote_payload']) : [];
            $result = $issuer->reconcile($request, $previous);
            $result = $this->validateResult($result, $request, $issuer->describe());
            $ownsTransaction = !$pdo->inTransaction();
            if ($ownsTransaction) $pdo->beginTransaction();
            $this->setFields($model->id, ['remote_payload' => $this->encrypt($result), 'remote_state' => $result['state'],
                'pending_request' => null, 'pending_action' => null, 'last_action' => $action, 'last_anchor' => $anchor,
                'last_error' => null, 'next_sync_at' => time() + 300]);
            $model->status = $result['state']; $model->last_error = null; $this->touch($model);
            $this->audit($model->id, $action, 'confirmed', $request['operation_id']);
            if ($ownsTransaction) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            $error = 'Issuer provisioning could not be confirmed. Retry provisioning or contact support.';
            $this->setFields($model->id, ['last_error' => $error, 'next_sync_at' => time() + 60]);
            $model->status = 'provisioning_error'; $model->last_error = $error; $this->touch($model);
            $this->audit($model->id, $action, 'failed', $request['operation_id']);
            throw new \FOSSBilling\InformationException($error);
        }
    }
    private function validateResult(array $result, array $request, array $info): array
    {
        if (!is_string($result['subscription_id'] ?? null) || $result['subscription_id'] === '' || strlen($result['subscription_id']) > 255
            || ($result['state'] ?? null) !== $request['state'] || ($result['expires_at'] ?? null) !== $request['expires_at']
            || !is_array($result['domains'] ?? null) || !is_bool($result['client_ready'] ?? null)) {
            throw new \RuntimeException('Issuer confirmation does not match requested entitlement.');
        }
        $domains = $result['domains']; $expected = $request['domains']; sort($domains); sort($expected);
        if ($domains !== $expected) throw new \RuntimeException('Issuer returned different domain coverage.');
        JsonApi::httpsUrl($result['directory_url'] ?? null);
        $eab = $result['eab'] ?? null;
        if ($info['managed'] && (!is_array($eab) || !is_string($eab['kid'] ?? null) || $eab['kid'] === ''
            || strlen($eab['kid']) > 512 || preg_match('/[\x00-\x1f\x7f]/', $eab['kid']) || !is_string($eab['hmac_key'] ?? null)
            || !preg_match('/^[A-Za-z0-9_-]{32,512}$/D', $eab['hmac_key']))) {
            throw new \RuntimeException('Issuer EAB credentials are missing or invalid.');
        }
        if ($info['emulated'] && $result['client_ready']) throw new \RuntimeException('Simulation cannot be client ready.');
        $result['eab'] = $info['managed'] ? array_intersect_key($eab, array_flip(['kid', 'hmac_key'])) : null;
        // Whitelist persisted fields to avoid accidental leakage of management credentials.
        return array_intersect_key($result, array_flip(['subscription_id', 'state', 'expires_at', 'domains', 'directory_url', 'eab', 'client_ready']));
    }
    private function subscription(OODBBean $model): array
    {
        $row = $this->row($model->id);
        if (!$row) {
            $this->di['pdo']->prepare('INSERT IGNORE INTO ssl_subscription (service_id, subscription_key, desired_state, revision, next_sync_at, updated_at) VALUES (?, ?, ?, 0, 0, UTC_TIMESTAMP())')
                ->execute([$model->id, bin2hex(random_bytes(16)), 'pending_setup']);
            $row = $this->row($model->id);
        }
        return $row;
    }
    private function row(int|string $serviceId): array|false
    {
        $stmt = $this->di['pdo']->prepare('SELECT * FROM ssl_subscription WHERE service_id = ?'); $stmt->execute([$serviceId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
    private function setFields(int|string $serviceId, array $fields): void
    {
        $allowed = ['desired_state','desired_expires_at','remote_state','remote_payload','pending_request','pending_action',
            'last_action','last_anchor','revision','next_sync_at','last_error','token_hash'];
        if (array_diff(array_keys($fields), $allowed)) throw new \LogicException('Invalid subscription field.');
        $sql = implode(', ', array_map(static fn ($key) => $key . ' = ?', array_keys($fields)));
        $this->di['pdo']->prepare('UPDATE ssl_subscription SET ' . $sql . ', updated_at = UTC_TIMESTAMP() WHERE service_id = ?')
            ->execute([...array_values($fields), $serviceId]);
    }
    private function effectiveState($order): string
    {
        if ($order->status === 'active') {
            $expires = $this->orderExpiry($order);
            return $expires && strtotime($expires) <= time() ? 'expired' : 'active';
        }
        return match ($order->status) { 'suspended' => 'suspended', 'canceled' => 'canceled', default => 'pending_setup' };
    }
    public function sync(array $data, $identity): array
    {
        [$order, $model] = $this->managed($data, $identity);
        $this->syncOrder($order, $model);
        return $this->toApiArray($this->di['db']->load('service_ssl', $model->id));
    }
    private function syncOrder($order, OODBBean $model): void
    {
        $this->locked($model, function (OODBBean $current) use ($order): void {
            $order = $this->di['db']->getExistingModelById('ClientOrder', (int) $order->id);
            $row = $this->subscription($current); $state = $this->effectiveState($order);
            $expires = $this->orderExpiry($order);
            if ($row['pending_request']) {
                $pending = $this->decrypt($row['pending_request']);
                if ($row['pending_action'] === 'sync') {
                    $request = $pending['state'] === $state && $pending['expires_at'] === $expires
                        ? $pending : $this->request($current, $row, $state, $expires, (int) $order->id);
                    $this->send($current, $row, $request, 'sync', $this->anchor($order)); return;
                }
                if (in_array($state, ['suspended','canceled','expired'], true) && $pending['state'] === 'active') {
                    $request = $this->request($current, $row, $state, $expires, (int) $order->id);
                    $this->send($current, $row, $request, 'sync', $this->anchor($order)); return;
                }
                // Lifecycle operations must be retried through FOSSBilling so billing expiry advances exactly once.
                throw new \FOSSBilling\InformationException('Retry the failed billing action in the administrator order page.');
            }
            if (in_array($order->status, ['pending_setup','failed_setup','failed_renew'], true) || $state === 'pending_setup') return;
            // Lifecycle hooks run before the core persists its new order status.
            // Do not restore an account in that small window after suspension/cancellation.
            if ($state === 'active' && in_array($row['last_action'], ['suspend','cancel','delete'], true)
                && $row['last_anchor'] === $this->anchor($order)) return;
            if ($row['desired_state'] === $state && $row['desired_expires_at'] === $expires && $row['remote_payload']) {
                $remote = $this->decrypt($row['remote_payload']);
                $info = $this->issuer($this->config($current)['issuer'])->describe();
                if ($state !== 'active' || $remote['client_ready'] || $info['emulated']) {
                    $this->setFields($current->id, ['next_sync_at' => time() + 300]); return;
                }
            }
            $request = $this->request($current, $row, $state, $expires, (int) $order->id);
            $this->send($current, $row, $request, 'sync', $this->anchor($order));
            if ($state === 'canceled') $this->setFields($current->id, ['token_hash' => null]);
        });
    }
    public function retry(array $data, $identity): array
    {
        [$order, $model] = $this->managed($data, $identity);
        $row = $this->subscription($model);
        if ($row['pending_request'] && $row['pending_action'] !== 'sync') {
            throw new \FOSSBilling\InformationException('Retry the failed lifecycle action through the normal FOSSBilling order actions.');
        }
        $this->syncOrder($order, $model);
        return $this->toApiArray($this->di['db']->load('service_ssl', $model->id));
    }
    public function handoff(array $data, $identity): array
    {
        [$order, $model] = $this->managed($data, $identity, true);
        return $this->locked($model, function (OODBBean $current) use ($order): array {
            // Recheck the persisted order under the same lock used by cancellation.
            $fresh = $this->di['db']->getExistingModelById('ClientOrder', (int) $order->id);
            return $this->manifest($fresh, $current);
        });
    }
    private function manifest($order, OODBBean $model): array
    {
        if ($this->effectiveState($order) !== 'active') throw new \FOSSBilling\InformationException('SSL subscription is not active or has expired.');
        $row = $this->subscription($model); $config = $this->config($model); $info = $this->issuer($config['issuer'])->describe();
        if ($row['pending_request'] || !$row['remote_payload'] || $row['remote_state'] !== 'active') {
            throw new \FOSSBilling\InformationException('ACME setup is unavailable until issuer provisioning succeeds.');
        }
        $remote = $this->decrypt($row['remote_payload']);
        if ($remote['expires_at'] && strtotime($remote['expires_at']) <= time()) throw new \FOSSBilling\InformationException('Issuer coverage has expired.');
        if (!$info['emulated'] && !$remote['client_ready']) throw new \FOSSBilling\InformationException('The issuer has not made this ACME account ready for client registration.');
        $result = ['schema_version' => 1, 'order_id' => (int) $order->id, 'issuer' => $config['issuer'],
            'directory_url' => $remote['directory_url'], 'domains' => $remote['domains'], 'environment' => $config['environment'],
            'coverage_expires_at' => $remote['expires_at'], 'managed' => $info['managed'], 'emulated' => $info['emulated'],
            'client_ready' => $remote['client_ready'], 'eab' => $remote['eab'],
            'private_key_location' => 'customer_server', 'terms_acceptance_required' => true];
        $this->audit($model->id, 'handoff', 'released', null);
        return ['filename' => 'namingo-ssl-' . $order->id . '.json', 'config' => $result];
    }
    public function pairing(array $data, $identity): array
    {
        [$order, $model] = $this->managed($data, $identity, true);
        return $this->locked($model, function (OODBBean $current) use ($order): array {
            $this->manifest($this->di['db']->getExistingModelById('ClientOrder', (int) $order->id), $current);
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $this->setFields($current->id, ['token_hash' => hash('sha256', $token)]);
            $this->audit($current->id, 'pairing', 'rotated', null);
            return ['order_id' => (int) $order->id, 'token' => $token, 'api_path' => '/api/guest/servicessl/bootstrap'];
        });
    }
    public function revokePairing(array $data, $identity): bool
    {
        [, $model] = $this->managed($data, $identity);
        return $this->locked($model, function (OODBBean $current): bool {
            $this->setFields($current->id, ['token_hash' => null]); $this->audit($current->id, 'pairing', 'revoked', null); return true;
        });
    }
    public function bootstrap(array $data): array
    {
        $id = filter_var($data['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $token = $data['token'] ?? null;
        if (!$id || !is_string($token) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) throw new \FOSSBilling\InformationException('Invalid SSL pairing credentials.');
        $order = $this->di['db']->findOne('ClientOrder', 'id = ? AND service_type = ?', [$id, 'ssl']);
        $model = $order ? $this->di['mod_service']('order')->getOrderService($order) : null;
        if (!$model instanceof OODBBean || !$model->id) throw new \FOSSBilling\InformationException('Invalid SSL pairing credentials.');
        return $this->locked($model, function (OODBBean $current) use ($id, $token): array {
            $row = $this->row($current->id);
            if (!$row || !$row['token_hash'] || !hash_equals($row['token_hash'], hash('sha256', $token))) {
                throw new \FOSSBilling\InformationException('Invalid SSL pairing credentials.');
            }
            $order = $this->di['db']->getExistingModelById('ClientOrder', $id);
            if ((int) $order->client_id !== (int) $current->client_id) throw new \FOSSBilling\InformationException('Invalid SSL pairing credentials.');
            return $this->manifest($order, $current)['config'];
        });
    }
    public function toApiArray(OODBBean $model, $deep = true, $identity = null): array
    {
        $config = $this->config($model); $info = $this->issuer($config['issuer'])->describe(); $row = $this->row($model->id);
        $remote = $row && $row['remote_payload'] ? $this->decrypt($row['remote_payload']) : [];
        $certificates = $this->di['db']->getAll('SELECT id, expires_at, fingerprint, revoked_at, created_at FROM ssl_certificate WHERE service_id = :id ORDER BY id DESC LIMIT 20', [':id' => $model->id]);
        return ['id' => (int) $model->id, 'domain_name' => $model->domain_name,
            'domains' => Domain::identifiers($model->domain_name, $config['domain_mode']), 'issuer' => $config['issuer'],
            'issuer_label' => $info['label'], 'environment' => $config['environment'], 'managed' => $info['managed'], 'emulated' => $info['emulated'],
            'status' => $row ? $model->status : 'migration_required', 'remote_state' => $row['remote_state'] ?? null,
            'coverage_expires_at' => $row['desired_expires_at'] ?? null, 'directory_url' => $remote['directory_url'] ?? null,
            'client_ready' => $remote['client_ready'] ?? false, 'pending_action' => $row['pending_action'] ?? null,
            'last_error' => $row['last_error'] ?? null, 'pairing_enabled' => !empty($row['token_hash']),
            'legacy_certificates' => $certificates, 'created_at' => $model->created_at, 'updated_at' => $model->updated_at];
    }
    public function history(array $data, $identity): array
    {
        [, $model] = $this->managed($data, $identity);
        return $this->di['db']->getAll('SELECT action, outcome, created_at FROM ssl_event WHERE service_id = :id ORDER BY id DESC LIMIT 30', [':id' => $model->id]);
    }
    public function download(array $data, $identity): array
    {
        [, $model] = $this->managed($data, $identity);
        $id = filter_var($data['certificate_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $format = $data['format'] ?? 'fullchain';
        if (!$id || !in_array($format, ['certificate','chain','fullchain'], true)) throw new \FOSSBilling\InformationException('Invalid legacy certificate download.');
        $stmt = $this->di['pdo']->prepare('SELECT * FROM ssl_certificate WHERE id = ? AND service_id = ?'); $stmt->execute([$id, $model->id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) throw new \FOSSBilling\InformationException('Certificate not found.');
        return ['pem' => $row[$format], 'filename' => $model->domain_name . '-' . $format . '.pem'];
    }
    public function delete(\Model_ClientOrder|OODBBean|null $order, ?OODBBean $model): void
    {
        if (!$model || !$model->id) return;
        // Preserve a failed remote deletion for retry; never silently orphan an active issuer subscription.
        if ($order) $this->lifecycle($order, $model, 'delete', 'deleted', $this->orderExpiry($order));
        elseif ($this->row($model->id)) throw new \FOSSBilling\InformationException('Delete the SSL subscription through its order.');
        $this->di['pdo']->prepare('DELETE FROM ssl_subscription WHERE service_id = ?')->execute([$model->id]);
        $this->di['pdo']->prepare('DELETE FROM ssl_event WHERE service_id = ?')->execute([$model->id]);
        $this->di['pdo']->prepare('DELETE FROM ssl_certificate WHERE service_id = ?')->execute([$model->id]);
        $this->di['db']->trash($model);
    }
    public function cron(): array
    {
        $rows = $this->di['db']->getAll('SELECT co.id FROM client_order co JOIN ssl_subscription ss ON ss.service_id = co.service_id WHERE co.service_type = :type AND ss.next_sync_at <= :now ORDER BY ss.next_sync_at ASC LIMIT 100', [':type' => 'ssl', ':now' => time()]);
        $count = ['checked' => 0, 'failed' => 0]; $started = time();
        foreach ($rows as $item) {
            $model = null;
            try {
                $order = $this->di['db']->getExistingModelById('ClientOrder', (int) $item['id']);
                $model = $this->di['mod_service']('order')->getOrderService($order);
                $this->syncOrder($order, $model); ++$count['checked'];
            } catch (\Throwable $e) {
                ++$count['failed'];
                // Failed billing actions wait for the normal order retry without starving other subscriptions.
                if (isset($model) && $model instanceof OODBBean && $model->id) $this->setFields($model->id, ['next_sync_at' => time() + 300]);
            }
            if (time() - $started >= 20) break;
        }
        return $count;
    }
    public static function onAfterAdminCronRun(\Box_Event $event): bool
    {
        $event->getDi()['mod_service']('servicessl')->cron(); return true;
    }
    public static function onAfterAdminOrderActivate(\Box_Event $event): bool { return self::afterOrder($event); }
    public static function onAfterAdminOrderRenew(\Box_Event $event): bool { return self::afterOrder($event); }
    public static function onAfterAdminOrderUncancel(\Box_Event $event): bool { return self::afterOrder($event); }
    private static function afterOrder(\Box_Event $event): bool
    {
        $di = $event->getDi(); $id = (int) ($event->getParameters()['id'] ?? 0);
        $order = $di['db']->findOne('ClientOrder', 'id = ? AND service_type = ?', [$id, 'ssl']);
        if ($order) {
            try { $di['mod_service']('servicessl')->syncOrder($order, $di['mod_service']('order')->getOrderService($order)); }
            catch (\Throwable $e) { /* Durable intent/error is visible on the order and retried by cron. */ }
        }
        return true;
    }
    private function managed(array $data, $identity, bool $active = false): array
    {
        $admin = $identity instanceof \Model_Admin || $identity instanceof \Box\Mod\Staff\Entity\Admin;
        $client = $identity instanceof \Model_Client || $identity instanceof \Box\Mod\Client\Entity\Client;
        $id = filter_var($data['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ((!$admin && !$client) || !$id) throw new \FOSSBilling\InformationException('SSL order not found.');
        $order = $this->di['db']->getExistingModelById('ClientOrder', $id, 'SSL order not found.');
        $identityId = method_exists($identity, 'getId') ? $identity->getId() : $identity->id;
        if ($order->service_type !== 'ssl' || ($client && (int) $order->client_id !== (int) $identityId)) throw new \FOSSBilling\InformationException('SSL order not found.');
        if ($active && $this->effectiveState($order) !== 'active') throw new \FOSSBilling\InformationException('SSL subscription is not active or has expired.');
        $model = $this->di['mod_service']('order')->getOrderService($order);
        if (!$model instanceof OODBBean || !$model->id || (int) $model->client_id !== (int) $order->client_id) throw new \FOSSBilling\InformationException('SSL service not found.');
        return [$order, $model];
    }
    private function locked(OODBBean $model, callable $operation): mixed
    {
        $stmt = $this->di['pdo']->prepare('SELECT GET_LOCK(?, 0)'); $name = 'ssl-service-' . $model->id; $stmt->execute([$name]);
        if ((int) $stmt->fetchColumn() !== 1) throw new \FOSSBilling\InformationException('Another SSL operation is in progress. Retry shortly.');
        try { return $operation($this->di['db']->load('service_ssl', $model->id)); }
        finally { $this->di['pdo']->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]); }
    }
    private function audit(int|string $serviceId, string $action, string $outcome, ?string $operation): void
    {
        $this->di['pdo']->prepare('INSERT INTO ssl_event (service_id, action, outcome, operation_id, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$serviceId, $action, $outcome, $operation]);
    }
    private function domain(mixed $name): string
    { try { return Domain::normalize($name); } catch (\InvalidArgumentException $e) { throw new \FOSSBilling\InformationException($e->getMessage()); } }
    private function decode(?string $value): array { return json_decode($value ?? '', true) ?: []; }
    private function encrypt(array $data): string { return $this->di['crypt']->encrypt(json_encode($data, JSON_THROW_ON_ERROR)); }
    private function decrypt(?string $value): array
    {
        $plain = $this->di['crypt']->decrypt($value);
        if (!is_string($plain)) throw new \FOSSBilling\InformationException('SSL data cannot be decrypted. Contact support.');
        return json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
    }
    private function config(OODBBean $model): array { return $this->validatedConfig($this->decrypt($model->config)); }
    private function touch(OODBBean $model): void { $model->updated_at = gmdate('Y-m-d H:i:s'); $this->di['db']->store($model); }
    public function install(): bool
    {
        if (!extension_loaded('curl') || !extension_loaded('openssl')) throw new \FOSSBilling\InformationException('SSL module requires PHP cURL and OpenSSL.');
        foreach (explode('-- statement --', file_get_contents(__DIR__ . '/schema.sql')) as $sql) {
            if (trim($sql)) $this->di['pdo']->exec($sql);
        }
        return true;
    }
    public function update(array $manifest): bool { return $this->install(); }
    public function uninstall(): bool { return true; } // Preserve subscriptions, credentials and legacy certificates.
}
