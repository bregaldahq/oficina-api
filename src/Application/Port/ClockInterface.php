<?php

declare(strict_types=1);

namespace App\Application\Port;

interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
