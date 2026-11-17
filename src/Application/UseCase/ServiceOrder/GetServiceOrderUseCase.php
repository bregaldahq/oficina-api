<?php

declare(strict_types=1);

namespace App\Application\UseCase\ServiceOrder;

use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\ServiceOrderRepositoryInterface;

class GetServiceOrderUseCase
{
    public function __construct(
        private readonly ServiceOrderRepositoryInterface $repository,
        private readonly ?\App\Application\Port\SagaRepositoryInterface $sagas = null,
    ) {
    }

    public function execute(string $id): ServiceOrder
    {
        $order = $this->repository->findById($id);

        if ($order === null) {
            throw new NotFoundException("Ordem de serviço não encontrada: {$id}");
        }

        return $order;
    }

    /**
     * O andamento da saga da OS, para a resposta da API (docs/fase-4/CONTRATOS.md §4). OS da
     * Fase 3, abertas antes da saga, não têm.
     *
     * @return array<string, mixed>|null
     */
    public function saga(string $orderId): ?array
    {
        return $this->sagas?->findByOsId($orderId)?->toArray();
    }
}
