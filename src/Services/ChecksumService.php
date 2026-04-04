<?php

declare(strict_types=1);

namespace H3mantd\DataMigrations\Services;

use RuntimeException;

class ChecksumService
{
    public function compute(string $filePath): string
    {
        if (! file_exists($filePath)) {
            throw new RuntimeException("Migration file not found: {$filePath}");
        }

        return hash('sha256', (string) file_get_contents($filePath));
    }

    public function hasDrifted(string $filePath, string $storedChecksum): bool
    {
        return $this->compute($filePath) !== $storedChecksum;
    }
}
