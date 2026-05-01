<?php

/**
 * Provides runtime-local ISO 8601 timestamps.
 */
final class Clock
{
    private static bool $configured = false;

    public static function nowIso8601(): string
    {
        self::configureRuntimeTimezone();
        return date('c');
    }

    private static function configureRuntimeTimezone(): void
    {
        if (self::$configured) {
            return;
        }

        $timezone = getenv('TZ');
        if ($timezone !== false && trim((string) $timezone) !== '') {
            @date_default_timezone_set((string) $timezone);
        }

        self::$configured = true;
    }
}
