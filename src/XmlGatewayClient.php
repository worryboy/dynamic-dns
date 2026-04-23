<?php

final class XmlGatewayClient
{
    private const TASK_AUTH_SESSION_CREATE = '1321001';
    private const TASK_AUTH_SESSION_DELETE = '1321003';
    private const TASK_ZONE_INQUIRY = '0205';
    private const TASK_ZONE_UPDATE = '0202';

    private Config $config;
    private Logger $logger;
    private ?string $sessionHash = null;
    private string $sessionAuthVariant = 'none';
    private ?string $sessionOwnerUser = null;
    private ?string $sessionOwnerContext = null;
    private string $stage = 'unknown';

    public function __construct(Config $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function setStage(string $stage): void
    {
        $this->stage = $stage;
    }

    public function hasSession(): bool
    {
        return $this->sessionHash !== null;
    }

    public function createSession(string $authVariant, ?string $ownerUser, ?string $ownerContext): void
    {
        $this->config->validateForGateway();
        $ownerBlockIncluded = $ownerUser !== null && $ownerContext !== null;
        $this->debug('Preparing InterNetX AuthSessionCreate request', array(
            'stage' => $this->stage,
            'auth_variant' => $authVariant,
            'auth_mode' => 'session_create',
            'credentials_auth' => 'true',
            'owner_block_included' => $ownerBlockIncluded ? 'true' : 'false',
        ));

        $request = $this->buildSessionCreateRequest($ownerUser, $ownerContext);
        $response = $this->request(
            $request->saveXML(),
            'AuthSessionCreate',
            self::TASK_AUTH_SESSION_CREATE,
            'auth_session_create',
            'session_create',
            $authVariant,
            false,
            $ownerBlockIncluded
        );

        $document = $this->loadXml($response, 'AuthSessionCreate response');
        $diagnostics = $this->extractResponseDiagnostics($document);
        $this->assertApiSuccess($diagnostics, 'Session login failed', 'AuthSessionCreate', false);

        $hash = $this->firstText($document, '//auth_session/hash');
        if ($hash === null || $hash === '') {
            throw new InterNetXApiException('InterNetX authentication/session creation failed: response did not contain a session hash.', array(
                'operation' => 'AuthSessionCreate',
                'task_code' => self::TASK_AUTH_SESSION_CREATE,
                'stage' => $this->stage,
                'auth_variant' => $authVariant,
                'owner_block_included' => $ownerBlockIncluded ? 'true' : 'false',
                'session_established' => 'false',
            ));
        }

        $this->sessionHash = $hash;
        $this->sessionAuthVariant = $authVariant;
        $this->sessionOwnerUser = $ownerUser;
        $this->sessionOwnerContext = $ownerContext;
        $this->logger->info('InterNetX session login successful', array(
            'auth_variant' => $authVariant,
            'auth_mode' => 'auth_session',
            'owner_block_included' => $ownerBlockIncluded ? 'true' : 'false',
            'session_hash' => $this->maskSecret($hash),
            'session_persisted' => 'false',
        ));
    }

    public function closeSession(): void
    {
        if ($this->sessionHash === null) {
            $this->debug('No InterNetX session to close', array('stage' => $this->stage));
            return;
        }

        $hash = $this->sessionHash;
        $ownerBlockIncluded = $this->sessionOwnerUser !== null && $this->sessionOwnerContext !== null;
        $this->debug('Preparing InterNetX AuthSessionDelete request', array(
            'stage' => $this->stage,
            'auth_variant' => $this->sessionAuthVariant,
            'auth_mode' => 'session_delete',
            'session_hash' => $this->maskSecret($hash),
            'owner_block_included' => $ownerBlockIncluded ? 'true' : 'false',
        ));

        $request = $this->buildSessionDeleteRequest($hash, $this->sessionOwnerUser, $this->sessionOwnerContext);
        $response = $this->request(
            $request->saveXML(),
            'AuthSessionDelete',
            self::TASK_AUTH_SESSION_DELETE,
            'session_cleanup',
            'session_delete',
            $this->sessionAuthVariant,
            false,
            $ownerBlockIncluded
        );

        $document = $this->loadXml($response, 'AuthSessionDelete response');
        $diagnostics = $this->extractResponseDiagnostics($document);
        $this->assertApiSuccess($diagnostics, 'Session cleanup failed', 'AuthSessionDelete', true);

        $this->sessionHash = null;
        $this->logger->info('InterNetX session closed', array(
            'auth_variant' => $this->sessionAuthVariant,
            'auth_mode' => 'session_delete',
            'owner_block_included' => $ownerBlockIncluded ? 'true' : 'false',
            'session_hash' => $this->maskSecret($hash),
        ));
        $this->sessionAuthVariant = 'none';
        $this->sessionOwnerUser = null;
        $this->sessionOwnerContext = null;
    }

    public function updateZoneRecords(string $domain, array $subdomains, ?string $ipv4, ?string $ipv6): void
    {
        if ($this->config->dryRun()) {
            throw new RuntimeException('Refusing XML zone update mutation because DRY_RUN is enabled.');
        }

        $this->assertSessionEstablished('live zone update');
        $this->config->validateForGateway();

        $zoneDocument = $this->fetchZone($domain);
        $requestDocument = $this->buildUpdateRequest($zoneDocument, $domain, $subdomains, $ipv4, $ipv6);
        $result = $this->request(
            $requestDocument->saveXML(),
            'ZoneUpdate',
            self::TASK_ZONE_UPDATE,
            'live_mutation',
            'auth_session',
            $this->sessionAuthVariant,
            true,
            $this->sessionOwnerUser !== null && $this->sessionOwnerContext !== null
        );
        $resultDocument = $this->loadXml($result, 'update zone records response');
        $diagnostics = $this->extractResponseDiagnostics($resultDocument);
        $this->assertApiSuccess($diagnostics, 'Live zone update failed', 'ZoneUpdate', true);
    }

    public function validateTargetAccess(string $domain, array $subdomains, bool $ipv6Enabled): void
    {
        $this->assertSessionEstablished('read-only target validation');
        $this->config->validateForGateway();

        $zoneDocument = $this->fetchZone($domain);
        foreach ($subdomains as $subdomain) {
            $this->assertRecordExists($zoneDocument, $domain, $subdomain, 'A');
            if ($ipv6Enabled) {
                $this->assertRecordExists($zoneDocument, $domain, $subdomain, 'AAAA');
            }
        }
    }

    private function fetchZone(string $domain): DOMDocument
    {
        $this->assertSessionEstablished('read-only zone inquiry');

        $request = $this->loadXmlTemplate($this->config->xmlGetZone());
        $this->replaceWithSessionAuthentication($request);
        $request->getElementsByTagName('name')->item(0)->nodeValue = $domain;
        $this->applyOptionalSystemNs($request);

        $this->logger->info('Fetching current InterNetX zone records', array(
            'domain' => $domain,
            'api_call_type' => 'read_only_preflight',
            'auth_mode' => 'auth_session',
            'auth_variant' => $this->sessionAuthVariant,
            'owner_block_included' => $this->sessionOwnerUser !== null && $this->sessionOwnerContext !== null ? 'true' : 'false',
            'mutation' => 'false',
        ));
        $result = $this->request(
            $request->saveXML(),
            'ZoneInfo',
            self::TASK_ZONE_INQUIRY,
            'read_only_preflight',
            'auth_session',
            $this->sessionAuthVariant,
            false,
            $this->sessionOwnerUser !== null && $this->sessionOwnerContext !== null
        );
        $document = $this->loadXml($result, 'zone lookup response');
        $diagnostics = $this->extractResponseDiagnostics($document);
        $this->assertApiSuccess($diagnostics, 'Read-only zone validation failed after successful session login', 'ZoneInfo', true);

        return $document;
    }

    private function buildSessionCreateRequest(?string $ownerUser, ?string $ownerContext): DOMDocument
    {
        $owner = $this->ownerBlockXml($ownerUser, $ownerContext);
        return $this->loadXml(sprintf(
            '<?xml version="1.0" encoding="utf-8"?><request><auth><user>%s</user><context>%s</context><password>%s</password></auth>%s<task><code>%s</code></task></request>',
            htmlspecialchars($this->config->user(), ENT_XML1),
            htmlspecialchars($this->config->context(), ENT_XML1),
            htmlspecialchars($this->config->password(), ENT_XML1),
            $owner,
            self::TASK_AUTH_SESSION_CREATE
        ), 'AuthSessionCreate request');
    }

    private function buildSessionDeleteRequest(string $hash, ?string $ownerUser, ?string $ownerContext): DOMDocument
    {
        $owner = $this->ownerBlockXml($ownerUser, $ownerContext);
        return $this->loadXml(sprintf(
            '<?xml version="1.0" encoding="utf-8"?><request><auth><user>%s</user><context>%s</context><password>%s</password></auth>%s<task><code>%s</code><auth_session><hash>%s</hash></auth_session></task></request>',
            htmlspecialchars($this->config->user(), ENT_XML1),
            htmlspecialchars($this->config->context(), ENT_XML1),
            htmlspecialchars($this->config->password(), ENT_XML1),
            $owner,
            self::TASK_AUTH_SESSION_DELETE,
            htmlspecialchars($hash, ENT_XML1)
        ), 'AuthSessionDelete request');
    }

    private function ownerBlockXml(?string $ownerUser, ?string $ownerContext): string
    {
        if ($ownerUser === null || $ownerContext === null) {
            return '';
        }

        return sprintf(
            '<owner><user>%s</user><context>%s</context></owner>',
            htmlspecialchars($ownerUser, ENT_XML1),
            htmlspecialchars($ownerContext, ENT_XML1)
        );
    }

    private function applyOptionalSystemNs(DOMDocument $request): void
    {
        $nodes = $request->getElementsByTagName('system_ns');
        if ($nodes->length === 0) {
            return;
        }

        $node = $nodes->item(0);
        if ($node === null) {
            return;
        }

        if ($this->config->systemNs() !== '') {
            $node->nodeValue = $this->config->systemNs();
            return;
        }

        if ($node->parentNode !== null) {
            $node->parentNode->removeChild($node);
        }
    }

    private function buildUpdateRequest(
        DOMDocument $zoneDocument,
        string $domain,
        array $subdomains,
        ?string $ipv4,
        ?string $ipv6
    ): DOMDocument {
        $request = $this->loadXmlTemplate($this->config->xmlPutZone());
        $this->replaceWithSessionAuthentication($request);

        $zone = $zoneDocument->getElementsByTagName('zone')->item(0);
        if ($zone === null) {
            throw new RuntimeException(sprintf('No zone payload returned for domain %s.', $domain));
        }

        foreach (array('created', 'changed', 'domainsafe', 'owner', 'updated_by') as $tagName) {
            $node = $zone->getElementsByTagName($tagName)->item(0);
            if ($node !== null && $node->parentNode !== null) {
                $node->parentNode->removeChild($node);
            }
        }

        $fragment = $request->importNode($zone, true);
        $request->getElementsByTagName('task')->item(0)->appendChild($fragment);

        foreach ($subdomains as $subdomain) {
            if ($ipv4 !== null) {
                $this->replaceRecordValue($request, $subdomain, 'A', $ipv4, $domain);
            }

            if ($ipv6 !== null) {
                $this->replaceRecordValue($request, $subdomain, 'AAAA', $ipv6, $domain);
            }
        }

        return $request;
    }

    private function replaceRecordValue(
        DOMDocument $request,
        string $subdomain,
        string $type,
        string $value,
        string $domain
    ): void {
        $xpath = new DOMXPath($request);
        $query = sprintf("//task/zone/rr[name='%s' and type='%s']/value", $subdomain, $type);
        $entries = $xpath->query($query);
        if ($entries->length !== 1) {
            throw new RuntimeException(sprintf(
                'DNS target matching failed after successful authenticated zone read: expected exactly one %s record for %s.%s.',
                $type,
                $subdomain,
                $domain
            ));
        }

        $entries->item(0)->nodeValue = $value;
    }

    private function assertRecordExists(DOMDocument $document, string $domain, string $subdomain, string $type): void
    {
        $xpath = new DOMXPath($document);
        $query = sprintf("//zone/rr[name='%s' and type='%s']/value", $subdomain, $type);
        $entries = $xpath->query($query);
        if ($entries->length !== 1) {
            throw new RuntimeException(sprintf(
                'DNS target matching failed after successful authenticated zone read: expected exactly one %s record for %s.%s.',
                $type,
                $subdomain,
                $domain
            ));
        }
    }

    private function request(
        string $body,
        string $operation,
        string $taskCode,
        string $apiCallType,
        string $authMode,
        string $authVariant,
        bool $mutation,
        bool $ownerBlockIncluded
    ): string {
        if ($mutation && $this->config->dryRun()) {
            throw new RuntimeException('Refusing XML mutation request because DRY_RUN is enabled.');
        }

        $context = $this->requestContext($operation, $taskCode, $apiCallType, $authMode, $authVariant, $mutation, $ownerBlockIncluded);
        $context['payload'] = $this->sanitizeXml($body);
        $this->debug('InterNetX XML request prepared', $context);

        $ch = curl_init($this->config->host());
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/xml; charset=utf-8'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->config->connectTimeout());
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->config->requestTimeout());
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new InterNetXApiException('InterNetX transport request failed: ' . $error, array_merge(
                $this->requestContext($operation, $taskCode, $apiCallType, $authMode, $authVariant, $mutation, $ownerBlockIncluded),
                array(
                    'transport_success' => 'false',
                    'session_established' => $this->hasSession() ? 'true' : 'false',
                )
            ));
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $responseContext = $this->requestContext($operation, $taskCode, $apiCallType, $authMode, $authVariant, $mutation, $ownerBlockIncluded);
        $responseContext['http_status'] = $statusCode;
        $responseContext['transport_success'] = $statusCode >= 200 && $statusCode < 300 ? 'true' : 'false';
        $responseContext['payload'] = $this->sanitizeXml($response);

        $diagnostics = array();
        try {
            $diagnostics = $this->extractResponseDiagnostics($this->loadXml($response, $operation . ' response diagnostics'));
            $responseContext = array_merge($responseContext, $diagnostics);
            $businessSuccess = $this->apiBusinessSuccess($diagnostics);
            $responseContext['api_business_success'] = $businessSuccess === null ? 'unknown' : ($businessSuccess ? 'true' : 'false');
        } catch (Throwable $exception) {
            $responseContext['response_parse_error'] = $exception->getMessage();
            $responseContext['api_business_success'] = 'unknown';
        }
        $this->debug('InterNetX XML response received', $responseContext);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new InterNetXApiException(sprintf('InterNetX transport request returned HTTP %d.', $statusCode), array_merge(
                $this->requestContext($operation, $taskCode, $apiCallType, $authMode, $authVariant, $mutation, $ownerBlockIncluded),
                $diagnostics,
                array(
                    'http_status' => (string) $statusCode,
                    'transport_success' => 'false',
                    'session_established' => $this->hasSession() ? 'true' : 'false',
                )
            ));
        }

        return $response;
    }

    private function requestContext(
        string $operation,
        string $taskCode,
        string $apiCallType,
        string $authMode,
        string $authVariant,
        bool $mutation,
        bool $ownerBlockIncluded
    ): array {
        return array(
            'operation' => $operation,
            'task_code' => $taskCode,
            'api_call_type' => $apiCallType,
            'stage' => $this->stage,
            'mutation' => $mutation ? 'true' : 'false',
            'dry_run' => $this->config->dryRun() ? 'true' : 'false',
            'owner_block_included' => $ownerBlockIncluded ? 'true' : 'false',
            'auth_mode' => $authMode,
            'auth_variant' => $authVariant,
            'session_established' => $this->hasSession() ? 'true' : 'false',
        );
    }

    private function replaceWithSessionAuthentication(DOMDocument $request): void
    {
        $this->assertSessionEstablished('session authentication replacement');

        $root = $request->documentElement;
        if ($root === null) {
            throw new RuntimeException('XML request has no root element.');
        }

        foreach (array('auth', 'auth_session', 'owner') as $tagName) {
            $node = $request->getElementsByTagName($tagName)->item(0);
            if ($node !== null && $node->parentNode !== null) {
                $node->parentNode->removeChild($node);
            }
        }

        $authSession = $request->createElement('auth_session');
        $authSession->appendChild($request->createElement('hash', (string) $this->sessionHash));

        $insertBefore = null;
        foreach ($root->childNodes as $childNode) {
            if ($childNode->nodeType === XML_ELEMENT_NODE) {
                $insertBefore = $childNode;
                break;
            }
        }

        if ($insertBefore === null) {
            $root->appendChild($authSession);
        } else {
            $root->insertBefore($authSession, $insertBefore);
        }

        $this->insertOwnerBlock($request, $root, $authSession);
    }

    private function insertOwnerBlock(DOMDocument $request, DOMElement $root, DOMElement $authSession): void
    {
        if ($this->sessionOwnerUser === null || $this->sessionOwnerContext === null) {
            return;
        }

        $owner = $request->createElement('owner');
        $owner->appendChild($request->createElement('user', $this->sessionOwnerUser));
        $owner->appendChild($request->createElement('context', $this->sessionOwnerContext));

        if ($authSession->nextSibling === null) {
            $root->appendChild($owner);
            return;
        }

        $root->insertBefore($owner, $authSession->nextSibling);
    }

    private function assertSessionEstablished(string $operation): void
    {
        if ($this->sessionHash !== null) {
            return;
        }

        throw new RuntimeException(sprintf('Cannot run %s before successful InterNetX AuthSessionCreate.', $operation));
    }

    private function extractResponseDiagnostics(DOMDocument $document): array
    {
        $diagnostics = array();
        $this->addFirstText($diagnostics, 'response_result_status_code', $document, '//response/result/status/code');
        $this->addFirstText($diagnostics, 'response_result_status_type', $document, '//response/result/status/type');
        $this->addObjectText($diagnostics, 'response_result_status_object', $document, '//response/result/status/object');
        $this->addFirstText($diagnostics, 'response_result_msg_code', $document, '//response/result/msg/code');
        $this->addFirstText($diagnostics, 'response_result_msg_text', $document, '//response/result/msg/text');
        $this->addFirstText($diagnostics, 'stid', $document, '//response/stid');

        return $diagnostics;
    }

    private function assertApiSuccess(array $diagnostics, string $failurePrefix, string $operation, bool $sessionExpected): void
    {
        $businessSuccess = $this->apiBusinessSuccess($diagnostics);
        $msgText = (string) ($diagnostics['response_result_msg_text'] ?? '');

        if ($businessSuccess === null) {
            throw new InterNetXApiException($failurePrefix . ': InterNetX response did not include result status diagnostics.', array_merge(
                $diagnostics,
                array(
                    'operation' => $operation,
                    'stage' => $this->stage,
                    'session_established' => $this->hasSession() || $sessionExpected ? 'true' : 'false',
                )
            ));
        }

        if ($businessSuccess) {
            return;
        }

        $errorText = $msgText !== '' ? $msgText : ($diagnostics['response_result_status_object'] ?? ($diagnostics['response_result_status_code'] ?? 'business failure'));
        throw new InterNetXApiException(trim($failurePrefix . ': ' . $errorText), array_merge(
            $diagnostics,
            array(
                'operation' => $operation,
                'stage' => $this->stage,
                'session_established' => $this->hasSession() || $sessionExpected ? 'true' : 'false',
            )
        ));
    }

    private function apiBusinessSuccess(array $diagnostics): ?bool
    {
        $statusType = strtolower((string) ($diagnostics['response_result_status_type'] ?? ''));
        $statusCode = (string) ($diagnostics['response_result_status_code'] ?? '');
        $msgCode = (string) ($diagnostics['response_result_msg_code'] ?? '');

        if ($statusType === '' && $statusCode === '' && $msgCode === '') {
            return null;
        }

        if ($statusType !== '' && !in_array($statusType, array('success', 'successful'), true)) {
            return false;
        }

        if ($statusCode !== '' && strtoupper($statusCode[0]) === 'E') {
            return false;
        }

        if ($msgCode !== '' && strtoupper($msgCode[0]) === 'E') {
            return false;
        }

        return true;
    }

    private function firstText(DOMDocument $document, string $query): ?string
    {
        $xpath = new DOMXPath($document);
        $entries = $xpath->query($query);
        if ($entries === false || $entries->length === 0) {
            return null;
        }

        return trim($entries->item(0)->nodeValue);
    }

    private function addFirstText(array &$diagnostics, string $key, DOMDocument $document, string $query): void
    {
        $value = $this->firstText($document, $query);
        if ($value !== null && $value !== '') {
            $diagnostics[$key] = $value;
        }
    }

    private function addObjectText(array &$diagnostics, string $key, DOMDocument $document, string $query): void
    {
        $type = $this->firstText($document, $query . '/type');
        $value = $this->firstText($document, $query . '/value');
        if ($type === null && $value === null) {
            return;
        }

        $diagnostics[$key] = trim((string) $type . ':' . (string) $value, ':');
    }

    private function debug(string $message, array $context = array()): void
    {
        if (!$this->config->debug()) {
            return;
        }

        $this->logger->debug($message, $context);
    }

    private function loadXmlTemplate(string $path): DOMDocument
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('XML template not found: %s', $path));
        }

        return $this->loadXml((string) file_get_contents($path), basename($path));
    }

    private function loadXml(string $xml, string $context): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new RuntimeException(sprintf('Invalid XML received while processing %s.', $context));
        }

        $document->formatOutput = true;
        return $document;
    }

    private function sanitizeXml(string $xml): string
    {
        $sanitized = preg_replace('/<password>.*?<\/password>/is', '<password>[redacted]</password>', $xml);
        $sanitized = preg_replace('/<user>.*?<\/user>/is', '<user>[redacted]</user>', (string) $sanitized);
        $sanitized = preg_replace('/<hash>.*?<\/hash>/is', '<hash>[redacted]</hash>', (string) $sanitized);

        return trim(str_replace(array("\n", "\r"), ' ', (string) $sanitized));
    }

    private function maskSecret(string $value): string
    {
        if (strlen($value) <= 8) {
            return '[redacted]';
        }

        return substr($value, 0, 4) . '...' . substr($value, -4);
    }
}
