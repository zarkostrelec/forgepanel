<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

/**
 * Provider za ACME dns-01 challenge: postavlja i uklanja _acme-challenge TXT zapis.
 * Implementacije (npr. CloudflareDns) skrivaju gdje DNS živi.
 */
interface Dns01Provider
{
    /**
     * Postavi _acme-challenge.<domain> TXT = <value>.
     * @return string handle (npr. id zapisa) za kasniji clear()
     */
    public function set(string $domain, string $value): string;

    /** Ukloni prethodno postavljeni zapis (best-effort). */
    public function clear(string $handle): void;
}
