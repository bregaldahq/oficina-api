<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Um marco da saga, para observabilidade (docs/fase-4/CONTRATOS.md §8, no oficina-api): saga
 * aberta, passo concluído, compensação concluída, compensação esgotada. Despachado só depois do
 * commit, pelo SagaCommit, como os eventos da OS.
 */
final class SagaEvent implements DomainEventInterface
{
    public const STARTED        = 'SagaStarted';
    public const STEP_COMPLETED = 'SagaStepCompleted';
    public const COMPENSATED    = 'SagaCompensated';
    public const FAILED         = 'SagaFailed';

    /** @param array<string, scalar|null> $attributes os atributos próprios de cada marco */
    public function __construct(
        public readonly string $name,
        public readonly string $sagaId,
        public readonly string $orderId,
        public readonly string $correlationId,
        public readonly array $attributes,
        private readonly \DateTimeImmutable $occurredAt,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
