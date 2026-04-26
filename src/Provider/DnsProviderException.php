<?php

/**
 * Base exception for provider failures that can expose sanitized diagnostics.
 */
class DnsProviderException extends RuntimeException
{
    private array $diagnostics;

    public function __construct(string $message, array $diagnostics = array())
    {
        parent::__construct($message);
        $this->diagnostics = $diagnostics;
    }

    public function diagnostics(): array
    {
        return $this->diagnostics;
    }
}
