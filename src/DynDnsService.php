<?php

final class DynDnsService
{
    private Config $config;
    private Logger $logger;
    private StateStore $stateStore;
    private PublicIpResolver $resolver;
    private XmlGatewayClient $gateway;

    public function __construct(
        Config $config,
        Logger $logger,
        StateStore $stateStore,
        PublicIpResolver $resolver,
        XmlGatewayClient $gateway
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->stateStore = $stateStore;
        $this->resolver = $resolver;
        $this->gateway = $gateway;
    }

    public function runOnce(): int
    {
        try {
            $this->stateStore->ensureDirectoryExists();

            $ipv4 = $this->resolver->resolve('ipv4');
            if ($ipv4 === null) {
                $this->logger->error('Public IP detection failed', array('family' => 'ipv4'));
                return 1;
            }

            $ipv6 = null;
            if ($this->config->ipv6Enabled()) {
                $ipv6 = $this->resolver->resolve('ipv6');
                if ($ipv6 === null) {
                    $this->logger->error('Public IP detection failed', array('family' => 'ipv6'));
                    return 1;
                }
            }

            $lastIpv4 = $this->stateStore->get('last_ipv4');
            $lastIpv6 = $this->config->ipv6Enabled() ? $this->stateStore->get('last_ipv6') : null;
            $ipv4Changed = $lastIpv4 !== $ipv4;
            $ipv6Changed = $this->config->ipv6Enabled() ? $lastIpv6 !== $ipv6 : false;

            if (!$ipv4Changed && !$ipv6Changed) {
                $context = array('ipv4' => $ipv4);
                if ($ipv6 !== null) {
                    $context['ipv6'] = $ipv6;
                }
                $this->logger->info('IP unchanged', $context);
                return 0;
            }

            $context = array(
                'ipv4' => $ipv4,
                'ipv4_changed' => $ipv4Changed ? 'true' : 'false',
            );
            if ($ipv6 !== null) {
                $context['ipv6'] = $ipv6;
                $context['ipv6_changed'] = $ipv6Changed ? 'true' : 'false';
            }

            $this->logger->info('Update attempted', $context);
            foreach ($this->config->domains() as $domain => $subdomains) {
                $this->gateway->updateZoneRecords($domain, $subdomains, $ipv4, $ipv6);
            }

            $this->stateStore->put('last_ipv4', $ipv4);
            if ($ipv6 !== null) {
                $this->stateStore->put('last_ipv6', $ipv6);
            }

            $successContext = array('ipv4' => $ipv4);
            if ($ipv6 !== null) {
                $successContext['ipv6'] = $ipv6;
            }

            $this->logger->info('Update successful', $successContext);
            return 0;
        } catch (Throwable $exception) {
            $this->logger->error('Update failed', array('error' => $exception->getMessage()));
            return 1;
        }
    }

    public function handleLegacyRequest(array $input): array
    {
        try {
            $domain = isset($input['domain']) ? trim((string) $input['domain']) : '';
            $pass = isset($input['pass']) ? (string) $input['pass'] : null;
            $ipv4 = isset($input['ipaddr']) ? trim((string) $input['ipaddr']) : null;
            $ipv6 = isset($input['ip6addr']) ? trim((string) $input['ip6addr']) : null;

            $this->assertLegacyCredentials($pass);
            $this->assertDomain($domain);

            if ($ipv4 === '') {
                $ipv4 = null;
            }
            if ($ipv6 === '') {
                $ipv6 = null;
            }
            if ($ipv4 === null && $ipv6 === null) {
                throw new InvalidArgumentException('No ipaddr or ip6addr was provided.');
            }

            if ($ipv4 !== null && !$this->isValidIpv4($ipv4)) {
                throw new InvalidArgumentException('ipaddr is not a valid IPv4 address.');
            }

            if ($ipv6 !== null && !$this->isValidIpv6($ipv6)) {
                throw new InvalidArgumentException('ip6addr is not a valid IPv6 address.');
            }

            $this->logger->info('Legacy update attempted', array(
                'domain' => $domain,
                'ipv4' => $ipv4,
                'ipv6' => $ipv6,
            ));

            $this->gateway->updateZoneRecords($domain, $this->config->subdomainsFor($domain), $ipv4, $ipv6);

            if ($ipv4 !== null) {
                $this->stateStore->put('last_ipv4', $ipv4);
            }
            if ($ipv6 !== null) {
                $this->stateStore->put('last_ipv6', $ipv6);
            }

            $context = array('domain' => $domain);
            if ($ipv4 !== null) {
                $context['ipv4'] = $ipv4;
            }
            if ($ipv6 !== null) {
                $context['ipv6'] = $ipv6;
            }

            $this->logger->info('Update successful', $context);
            return array('status' => 'success');
        } catch (Throwable $exception) {
            $this->logger->error('Update failed', array('error' => $exception->getMessage()));
            return array(
                'status' => 'failed',
                'msg' => $exception->getMessage(),
            );
        }
    }

    private function assertLegacyCredentials(?string $pass): void
    {
        $expected = $this->config->remotePass();
        if ($expected === null) {
            return;
        }

        if ($pass === null || !hash_equals($expected, $pass)) {
            throw new InvalidArgumentException('Bad credentials.');
        }
    }

    private function assertDomain(string $domain): void
    {
        if ($domain === '' || !$this->isValidDomain($domain)) {
            throw new InvalidArgumentException('domain is not valid.');
        }

        if (!$this->config->hasDomain($domain)) {
            throw new InvalidArgumentException('Unsupported domain.');
        }
    }

    private function isValidIpv4(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    private function isValidIpv6(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    private function isValidDomain(string $domain): bool
    {
        return preg_match("/^([a-z\\d](-*[a-z\\d])*)(\\.([a-z\\d](-*[a-z\\d])*))*$/i", $domain) === 1
            && preg_match("/^.{1,253}$/", $domain) === 1
            && preg_match("/^[^\\.]{1,63}(\\.[^\\.]{1,63})*$/", $domain) === 1;
    }
}
