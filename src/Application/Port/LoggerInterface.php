<?php

declare(strict_types=1);

namespace App\Application\Port;

interface LoggerInterface
{
    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void;

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void;
}
