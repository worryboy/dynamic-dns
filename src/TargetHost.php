<?php

/**
 * Tracks one configured DNS target and keeps hostname parsing out of the main service.
 */
final class TargetHost
{
    private string $host;
    private string $zone;
    private string $subdomain;
    private string $zoneSource;

    public function __construct(string $host, string $zone, string $subdomain, string $zoneSource)
    {
        $this->host = $host;
        $this->zone = $zone;
        $this->subdomain = $subdomain;
        $this->zoneSource = $zoneSource;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function zone(): string
    {
        return $this->zone;
    }

    public function subdomain(): string
    {
        return $this->subdomain;
    }

    public function zoneSource(): string
    {
        return $this->zoneSource;
    }
}
