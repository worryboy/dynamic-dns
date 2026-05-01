<?php

/**
 * Sends optional Pushover alerts when a real public IP change should be reported.
 * It stays out of the main DNS flow so notification failures can stay non-fatal.
 */
final class PushoverNotifier
{
    private const API_URL = 'https://api.pushover.net/1/messages.json';

    private Logger $logger;
    private string $appKey;
    private string $userKey;
    private string $locationPrefix;
    private int $connectTimeout;
    private int $requestTimeout;

    public function __construct(
        Logger $logger,
        string $appKey,
        string $userKey,
        string $locationPrefix,
        int $connectTimeout,
        int $requestTimeout
    ) {
        $this->logger = $logger;
        $this->appKey = $appKey;
        $this->userKey = $userKey;
        $this->locationPrefix = $locationPrefix;
        $this->connectTimeout = $connectTimeout;
        $this->requestTimeout = $requestTimeout;
    }

    public function enabled(): bool
    {
        return $this->appKey !== '' && $this->userKey !== '' && $this->locationPrefix !== '';
    }

    public function locationPrefix(): string
    {
        return $this->locationPrefix;
    }

    public function notifyIpChange(?string $ipv4, ?string $ipv6, bool $ipv4Changed, bool $ipv6Changed): void
    {
        if (!$this->enabled()) {
            $this->logger->info('Pushover disabled; skipping IP change notification', array(
                'pushover_enabled' => 'false',
            ));
            return;
        }

        $lines = array();
        if ($ipv4Changed && $ipv4 !== null) {
            $lines[] = sprintf('%s IPv4 Address: %s', $this->locationPrefix, $ipv4);
        }
        if ($ipv6Changed && $ipv6 !== null) {
            $lines[] = sprintf('%s IPv6 Address: %s', $this->locationPrefix, $ipv6);
        }

        if (empty($lines)) {
            $this->logger->info('Pushover notification skipped because no public IP change occurred', array(
                'pushover_enabled' => 'true',
            ));
            return;
        }

        $body = http_build_query(array(
            'token' => $this->appKey,
            'user' => $this->userKey,
            'title' => 'Dynamic DNS Worker IP Change',
            'message' => implode("\n", $lines),
        ));

        $ch = curl_init(self::API_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->requestTimeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Pushover request failed: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException(sprintf('Pushover request returned HTTP %d.', $statusCode));
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !isset($decoded['status']) || (int) $decoded['status'] !== 1) {
            throw new RuntimeException('Pushover request returned an unexpected response.');
        }

        $this->logger->success('Pushover notification sent successfully', array(
            'pushover_enabled' => 'true',
            'location_prefix' => $this->locationPrefix,
            'ipv4_changed' => $ipv4Changed ? 'true' : 'false',
            'ipv6_changed' => $ipv6Changed ? 'true' : 'false',
        ));
    }
}
