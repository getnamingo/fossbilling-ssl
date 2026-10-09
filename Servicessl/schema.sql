CREATE TABLE IF NOT EXISTS service_ssl (
 id BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
 client_id BIGINT(20) NOT NULL, domain_name VARCHAR(253) NOT NULL,
 config MEDIUMTEXT NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'pending_setup',
 csr TEXT NULL, provider_state MEDIUMTEXT NOT NULL, challenges TEXT NOT NULL,
 retry_at BIGINT(20) NOT NULL DEFAULT 0, last_error VARCHAR(500) NULL,
 certificate_expires_at DATETIME NULL, last_issued_at DATETIME NULL,
 created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 KEY idx_ssl_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- statement --
CREATE TABLE IF NOT EXISTS ssl_certificate (
 id BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
 service_id BIGINT(20) NOT NULL, certificate TEXT NOT NULL, chain MEDIUMTEXT NOT NULL,
 fullchain MEDIUMTEXT NOT NULL, expires_at DATETIME NOT NULL,
 fingerprint VARCHAR(64) NOT NULL, revoked_at DATETIME NULL, created_at DATETIME NOT NULL,
 UNIQUE KEY uniq_ssl_cert (service_id, fingerprint),
 CONSTRAINT fk_ssl_certificate FOREIGN KEY (service_id) REFERENCES service_ssl(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- statement --
CREATE TABLE IF NOT EXISTS ssl_subscription (
 service_id BIGINT(20) NOT NULL PRIMARY KEY, subscription_key CHAR(32) NOT NULL,
 desired_state VARCHAR(24) NOT NULL, desired_expires_at VARCHAR(32) NULL,
 remote_state VARCHAR(24) NULL, remote_payload MEDIUMTEXT NULL,
 pending_request MEDIUMTEXT NULL, pending_action VARCHAR(24) NULL,
 last_action VARCHAR(24) NULL, last_anchor VARCHAR(100) NULL,
 revision BIGINT(20) NOT NULL DEFAULT 0, next_sync_at BIGINT(20) NOT NULL DEFAULT 0,
 last_error VARCHAR(500) NULL, token_hash CHAR(64) NULL, updated_at DATETIME NOT NULL,
 UNIQUE KEY uniq_ssl_subscription (subscription_key), KEY idx_ssl_sync (next_sync_at),
 CONSTRAINT fk_ssl_subscription FOREIGN KEY (service_id) REFERENCES service_ssl(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- statement --
CREATE TABLE IF NOT EXISTS ssl_event (
 id BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY, service_id BIGINT(20) NOT NULL,
 action VARCHAR(24) NOT NULL, outcome VARCHAR(24) NOT NULL, operation_id CHAR(32) NULL,
 created_at DATETIME NOT NULL, KEY idx_ssl_event (service_id),
 CONSTRAINT fk_ssl_event FOREIGN KEY (service_id) REFERENCES service_ssl(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
