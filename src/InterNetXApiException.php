<?php

/**
 * Wraps API failures with the sanitized diagnostics we want to surface in logs.
 */
final class InterNetXApiException extends RuntimeException
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
