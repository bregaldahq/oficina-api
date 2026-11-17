<?php

declare(strict_types=1);

namespace App\Application\UseCase\ServiceOrder;

use App\Application\Port\ClockInterface;
use App\Application\Port\SagaRepositoryInterface;
use App\Application\UseCase\Saga\SagaCommit;
use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\ServiceOrderRepositoryInterface;
use App\Domain\Saga\SagaOrchestrator;

/** O admin cancela a OS: a saga desfaz o que já existe (docs/fase-4/CONTRATOS.md §6.2). */
final class CancelServiceOrderUseCase
{
    public function __construct(
        private readonly SagaRepositoryInterface $sagas,
        private readonly ServiceOrderRepositoryInterface $orders,
        private readonly SagaOrchestrator $orchestrator,
        private readonly SagaCommit $commit,
        private readonly ClockInterface $clock,
    ) {
    }

    public function execute(string $orderId, string $reason): ServiceOrder
    {
        $order = $this->orders->findById($orderId) ?? throw new NotFoundException("Ordem de serviço não encontrada: {$orderId}");
        if (trim($reason) === '') {
            throw new DomainException('Informe o motivo do cancelamento.');
        }
        if (mb_strlen($reason) > \App\Domain\Saga\Saga::REASON_MAX) {
            throw new DomainException('O motivo do cancelamento passa de ' . \App\Domain\Saga\Saga::REASON_MAX . ' caracteres.');
        }
        $saga = $this->sagas->findByOsId($orderId) ?? throw new DomainException('Esta ordem de serviço não tem saga para cancelar.');

        $this->orchestrator->cancel($saga, $order, $reason, $this->clock->now());
        ($this->commit)($saga, $order);

        return $order;
    }
}
