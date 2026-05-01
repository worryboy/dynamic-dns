<?php

/**
 * DNS provider adapter for InterNetX / AutoDNS / SchlundTech via the XML interface.
 * It keeps provider identity separate from the generic worker flow.
 */
final class InterNetXXmlProvider implements DnsProvider
{
    private InterNetXXmlGatewayClient $gateway;

    public function __construct(InterNetXXmlGatewayClient $gateway)
    {
        $this->gateway = $gateway;
    }

    public function providerName(): string
    {
        return 'InterNetX';
    }

    public function interfaceName(): string
    {
        return 'XML';
    }

    public function authModel(): string
    {
        return 'auth_session';
    }

    public function setStage(string $stage): void
    {
        $this->gateway->setStage($stage);
    }

    public function hasOpenSession(): bool
    {
        return $this->gateway->hasSession();
    }

    public function open(): void
    {
        $this->gateway->createSession();
    }

    public function close(): void
    {
        $this->gateway->closeSession();
    }

    public function inspectTargets(string $zone, array $targets, bool $requireIpv4, bool $requireIpv6): array
    {
        return $this->gateway->inspectTargets($zone, $targets, $requireIpv4, $requireIpv6);
    }

    public function updateZoneRecords(DOMDocument $zoneDocument, string $zone, array $targets, ?string $ipv4, ?string $ipv6): void
    {
        $this->gateway->updateZoneRecords($zoneDocument, $zone, $targets, $ipv4, $ipv6);
    }
}
