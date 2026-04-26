<?php

/**
 * Writes simple structured logs and adds color when output goes to a terminal.
 */
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

    public function success(string $message, array $context = array()): void
    {
        $this->write('SUCCESS', $message, $context);
    }

    public function warning(string $message, array $context = array()): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function error(string $message, array $context = array()): void
    {
        $this->write('ERROR', $message, $context);
    }

    public function debug(string $message, array $context = array()): void
    {
        $this->write('DEBUG', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $levelLabel = $this->formatLevel($level);
        $line = sprintf(
            "[%s] %s %s%s\n",
            gmdate('c'),
            $levelLabel,
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

    private function formatLevel(string $level): string
    {
        if (!$this->shouldColorize()) {
            return $level;
        }

        $colors = array(
            'DEBUG' => "\033[2;37m",
            'INFO' => "\033[34m",
            'SUCCESS' => "\033[32m",
            'WARNING' => "\033[33m",
            'ERROR' => "\033[31m",
        );

        if (!isset($colors[$level])) {
            return $level;
        }

        return $colors[$level] . $level . "\033[0m";
    }

    private function shouldColorize(): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }

        return in_array($this->target, array('php://stdout', 'php://stderr'), true);
    }
}
