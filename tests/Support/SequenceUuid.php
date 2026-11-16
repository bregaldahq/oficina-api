<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\UuidGeneratorInterface;

final class SequenceUuid implements UuidGeneratorInterface
{
    private int $next = 1;

    public function generate(): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $this->next++);
    }
}
