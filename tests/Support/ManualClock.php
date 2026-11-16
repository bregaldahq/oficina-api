<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Port\ClockInterface;

/** Relógio que só anda quando o teste manda: os prazos da saga ficam sob controle. */
final class ManualClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(string $start = '2026-11-16T10:00:00Z')
    {
        $this->now = new \DateTimeImmutable($start);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify("+{$seconds} seconds");
    }
}
