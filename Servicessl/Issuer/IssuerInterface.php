<?php
/** Copyright 2026 Namingo. SPDX-License-Identifier: Apache-2.0 */
declare(strict_types=1);
namespace Box\Mod\Servicessl\Issuer;

interface IssuerInterface
{
    /** Public capabilities. Never return management credentials here. */
    public function describe(): array;

    /**
     * Idempotently set absolute domain coverage, expiry and account state.
     * An issuer MUST enforce these on ACME newOrder, including bound accounts.
     * No certificate or ACME account private key belongs in this interface.
     */
    public function reconcile(array $request, array $previous): array;
}
