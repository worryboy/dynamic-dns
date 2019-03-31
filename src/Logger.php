<?php

final class Logger
{
    private string $target;

    public function __construct(string $target = 'php://stdout')
    {
        $this->target = $target;
    }

    public function info(string $message, array $context = array()): void
    {
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = array()): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function error(string $message, array $context = array()): void
    {
        $this->write('ERROR', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $line = sprintf(
            "[%s] %s %s%s\n",
            gmdate('c'),
            $level,
            $message,
            $this->formatContext($context)
        );

        file_put_contents($this->target, $line, FILE_APPEND);
    }

    private function formatContext(array $context): string
    {
        if (empty($context)) {
            return '';
        }

        $pairs = array();
        foreach ($context as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_array($value)) {
                $value = implode(',', $value);
            }

            $pairs[] = sprintf('%s=%s', $key, str_replace(array("\n", "\r"), ' ', (string) $value));
        }

        return empty($pairs) ? '' : ' ' . implode(' ', $pairs);
    }
}

