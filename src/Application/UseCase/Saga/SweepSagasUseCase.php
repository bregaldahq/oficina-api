<?php

declare(strict_types=1);

namespace App\Application\UseCase\Saga;

use App\Application\Port\ClockInterface;
use App\Application\Port\LoggerInterface;
use App\Application\Port\SagaRepositoryInterface;
use App\Domain\Exception\ConcurrencyException;
use App\Domain\Repository\ServiceOrderRepositoryInterface;
use App\Domain\Saga\SagaOrchestrator;

/**
 * Varre as sagas com o prazo vencido (§6.3), a cada volta do worker: compensa o passo que
 * venceu, alerta o que está parado com o mecânico e reenvia compensação sem confirmação.
 */
final class SweepSagasUseCase
{
    public function __construct(
        private readonly SagaRepositoryInterface $sagas,
        private readonly ServiceOrderRepositoryInterface $orders,
        private readonly SagaOrchestrator $orchestrator,
        private readonly SagaCommit $commit,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array<string, int> quantas sagas por resultado */
    public function execute(): array
    {
        $now     = $this->clock->now();
        $summary = [];

        foreach ($this->sagas->findDue($now) as $saga) {
            try {
                $outcome = $this->sweep($saga, $now);
            } catch (\Throwable $e) {
                // Uma saga com defeito não pode parar a varredura das outras: ela volta na
                // próxima volta e, enquanto isso, fica no log.
                $this->logger->error('saga.sweep_failed', [
                    'saga_id'           => $saga->getId(),
                    'os_id'             => $saga->getOsId(),
                    'exception_class'   => $e::class,
                    'exception_message' => $e->getMessage(),
                ]);
                continue;
            }
            if ($outcome !== null) {
                $summary[$outcome] = ($summary[$outcome] ?? 0) + 1;
            }
        }

        return $summary;
    }

    private function sweep(\App\Domain\Saga\Saga $saga, \DateTimeImmutable $now): ?string
    {
        $order = $this->orders->findById($saga->getOsId());
        if ($order === null) {
            return null;
        }

        $outcome = $this->orchestrator->timeout($saga, $order, $now);
        if ($outcome === SagaOrchestrator::OUTCOME_IGNORED) {
            return null;
        }

        try {
            ($this->commit)($saga, $order);
        } catch (ConcurrencyException) {
            // Um evento mexeu na saga ao mesmo tempo: a próxima volta olha de novo.
            return null;
        }

        $context = [
            'saga_id'        => $saga->getId(),
            'os_id'          => $saga->getOsId(),
            'correlation_id' => $saga->getCorrelationId(),
            'step'           => $saga->getStep(),
            'state'          => $saga->getState(),
        ];
        in_array($outcome, [SagaOrchestrator::OUTCOME_STALLED, SagaOrchestrator::OUTCOME_FAILED], true)
            ? $this->logger->error("saga.{$outcome}", $context)
            : $this->logger->info("saga.timeout.{$outcome}", $context);

        return $outcome;
    }
}
