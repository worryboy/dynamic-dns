<?php

final class PublicIpResolver
{
    private array $ipv4Providers;
    private array $ipv6Providers;
    private Logger $logger;
    private int $connectTimeout;
    private int $requestTimeout;

    public function __construct(
        array $ipv4Providers,
        array $ipv6Providers,
        Logger $logger,
        int $connectTimeout,
        int $requestTimeout
    )
    {
        $this->ipv4Providers = $ipv4Providers;
        $this->ipv6Providers = $ipv6Providers;
        $this->logger = $logger;
        $this->connectTimeout = $connectTimeout;
        $this->requestTimeout = $requestTimeout;
    }

    public function resolve(string $family = 'ipv4'): ?string
    {
        $providers = $family === 'ipv6' ? $this->ipv6Providers : $this->ipv4Providers;

        foreach ($providers as $provider) {
            $ip = $this->requestIp($provider);
            if ($ip === null) {
                continue;
            }

            if ($this->isValidForFamily($ip, $family)) {
                return $ip;
            }

            $this->logger->warning('Public IP provider returned an invalid response', array(
                'provider' => $provider,
                'family' => $family,
            ));
        }

        return null;
    }

    private function requestIp(string $provider): ?string
    {
        $ch = curl_init($provider);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->requestTimeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, 'internetx-dyndns/1.0');

        $response = curl_exec($ch);
        if ($response === false) {
            $this->logger->warning('Public IP detection provider failed', array(
                'provider' => $provider,
                'error' => curl_error($ch),
            ));
            curl_close($ch);
            return null;
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($statusCode < 200 || $statusCode >= 300) {
            $this->logger->warning('Public IP detection provider returned a non-success status', array(
                'provider' => $provider,
                'status' => $statusCode,
            ));
            return null;
        }

        return trim($response);
    }

    private function isValidForFamily(string $value, string $family): bool
    {
        if ($family === 'ipv6') {
            return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }
}
