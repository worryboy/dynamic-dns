<?php

final class XmlGatewayClient
{
    private Config $config;
    private Logger $logger;

    public function __construct(Config $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function updateZoneRecords(string $domain, array $subdomains, ?string $ipv4, ?string $ipv6): void
    {
        $this->config->validateForGateway();

        $zoneDocument = $this->fetchZone($domain);
        $requestDocument = $this->buildUpdateRequest($zoneDocument, $domain, $subdomains, $ipv4, $ipv6);
        $result = $this->request($requestDocument->saveXML());
        $resultDocument = $this->loadXml($result, 'update zone records response');

        $xpath = new DOMXPath($resultDocument);
        $entries = $xpath->query('status/type');
        if ($entries->length > 0 && $entries->item(0)->nodeValue !== 'success') {
            throw new RuntimeException('InterNetX rejected the zone update request.');
        }
    }

    private function fetchZone(string $domain): DOMDocument
    {
        $request = $this->loadXmlTemplate($this->config->xmlGetZone());
        $this->applyAuthentication($request);
        $request->getElementsByTagName('name')->item(0)->nodeValue = $domain;
        $request->getElementsByTagName('system_ns')->item(0)->nodeValue = $this->config->systemNs();

        $this->logger->info('Fetching current InterNetX zone records', array('domain' => $domain));
        $result = $this->request($request->saveXML());
        $document = $this->loadXml($result, 'zone lookup response');

        $xpath = new DOMXPath($document);
        $entries = $xpath->query('status/status');
        if ($entries->length > 0 && $entries->item(0)->nodeValue === 'error') {
            throw new RuntimeException('InterNetX did not return zone records.');
        }

        return $document;
    }

    private function buildUpdateRequest(
        DOMDocument $zoneDocument,
        string $domain,
        array $subdomains,
        ?string $ipv4,
        ?string $ipv6
    ): DOMDocument {
        $request = $this->loadXmlTemplate($this->config->xmlPutZone());
        $this->applyAuthentication($request);

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
                'Expected exactly one %s record for %s.%s in InterNetX zone data.',
                $type,
                $subdomain,
                $domain
            ));
        }

        $entries->item(0)->nodeValue = $value;
    }

    private function request(string $body): string
    {
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
            throw new RuntimeException('InterNetX request failed: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException(sprintf('InterNetX request returned HTTP %d.', $statusCode));
        }

        return $response;
    }

    private function applyAuthentication(DOMDocument $request): void
    {
        if ($this->config->authMode() === 'trusted_application') {
            $this->replaceWithTrustedApplicationAuthentication($request);
            return;
        }

        $request->getElementsByTagName('user')->item(0)->nodeValue = $this->config->user();
        $request->getElementsByTagName('password')->item(0)->nodeValue = $this->config->password();
        $request->getElementsByTagName('context')->item(0)->nodeValue = $this->config->context();
    }

    private function replaceWithTrustedApplicationAuthentication(DOMDocument $request): void
    {
        $root = $request->documentElement;
        if ($root === null) {
            throw new RuntimeException('XML request has no root element.');
        }

        $legacyAuth = $request->getElementsByTagName('auth')->item(0);
        if ($legacyAuth !== null && $legacyAuth->parentNode !== null) {
            $legacyAuth->parentNode->removeChild($legacyAuth);
        }

        $authentication = $request->createElement('authentication');
        $trustedApplication = $request->createElement('trusted_application');
        $trustedApplication->appendChild($request->createElement('uuid', (string) $this->config->trustedAppUuid()));
        $trustedApplication->appendChild($request->createElement('password', (string) $this->config->trustedAppPassword()));
        $application = $request->createElement('application');
        $application->appendChild($request->createElement('name', (string) $this->config->trustedAppName()));
        $trustedApplication->appendChild($application);
        $authentication->appendChild($trustedApplication);

        $insertBefore = null;
        foreach ($root->childNodes as $childNode) {
            if ($childNode->nodeType === XML_ELEMENT_NODE) {
                $insertBefore = $childNode;
                break;
            }
        }

        if ($insertBefore === null) {
            $root->appendChild($authentication);
            return;
        }

        $root->insertBefore($authentication, $insertBefore);
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
}
