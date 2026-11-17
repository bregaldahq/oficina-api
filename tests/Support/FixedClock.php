<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Port\ClockInterface;

/** Relógio que anda um segundo a cada leitura, para a ordem dos eventos ser previsível. */
final class FixedClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(string $start = '2026-11-15T10:00:00Z')
    {
        $this->now = new \DateTimeImmutable($start);
    }

    public function now(): \DateTimeImmutable
    {
        $current   = $this->now;
        $this->now = $this->now->modify('+1 second');

        return $current;
    }
}
