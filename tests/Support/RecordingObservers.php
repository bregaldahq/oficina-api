<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Port\LoggerInterface;
use App\Application\Port\TracerInterface;

/** Logger e rastreador que só anotam, para os testes conferirem. */
final class RecordingObservers implements LoggerInterface, TracerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $logs = [];

    /** @var list<string> */
    public array $traces = [];

    public function info(string $message, array $context = []): void
    {
        $this->logs[] = ['level' => 'info', 'message' => $message, 'context' => $context];
    }

    public function error(string $message, array $context = []): void
    {
        $this->logs[] = ['level' => 'error', 'message' => $message, 'context' => $context];
    }

    public function currentTrace(): ?string
    {
        return 'trace-do-teste';
    }

    public function startMessage(string $type, ?string $trace): void
    {
        $this->traces[] = "start:{$type}";
    }

    public function endMessage(bool $failed): void
    {
        $this->traces[] = 'end:' . ($failed ? 'falha' : 'ok');
    }

    /** @return list<string> */
    public function messages(): array
    {
        return array_map(static fn (array $l): string => $l['message'], $this->logs);
    }
}
