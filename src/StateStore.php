<?php

final class StateStore
{
    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);
    }

    public function get(string $key): ?string
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return null;
        }

        $value = trim((string) file_get_contents($path));
        return $value === '' ? null : $value;
    }

    public function put(string $key, string $value): void
    {
        $this->ensureDirectoryExists();
        file_put_contents($this->pathFor($key), $value . PHP_EOL, LOCK_EX);
    }

    public function ensureDirectoryExists(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (!mkdir($concurrentDirectory = $this->directory, 0775, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException(sprintf('Unable to create state directory: %s', $this->directory));
        }
    }

    private function pathFor(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $key;
    }
}

