<?php

/**
 * Small contract between the generic worker and a concrete DNS provider interface.
 * Implementations own provider authentication, target inspection, and live updates.
 */
interface DnsProvider
{
    public function providerName(): string;

    public function interfaceName(): string;

    public function authModel(): string;

    public function setStage(string $stage): void;

    public function hasOpenSession(): bool;

    public function open(): void;

    public function close(): void;

    public function inspectTargets(string $zone, array $targets, bool $requireIpv4, bool $requireIpv6): array;

    public function updateZoneRecords(DOMDocument $zoneDocument, string $zone, array $targets, ?string $ipv4, ?string $ipv6): void;
}
