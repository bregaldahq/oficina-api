<?php

declare(strict_types=1);

namespace App\Application\UseCase\ServiceOrder;

use App\Application\DTO\ServiceOrder\CreateServiceOrderInputDTO;
use App\Application\Port\ClockInterface;
use App\Application\UseCase\Saga\SagaCommit;
use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\CustomerRepositoryInterface;
use App\Domain\Repository\VehicleRepositoryInterface;
use App\Domain\Saga\SagaOrchestrator;
use App\Domain\UuidGeneratorInterface;

/**
 * Abre a OS e, na mesma transação, a saga dela, com o pedido de diagnóstico na outbox
 * (docs/fase-4/CONTRATOS.md §6.1, no oficina-api).
 */
class CreateServiceOrderUseCase
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly VehicleRepositoryInterface $vehicleRepository,
        private readonly UuidGeneratorInterface $uuidGenerator,
        private readonly SagaOrchestrator $orchestrator,
        private readonly SagaCommit $commit,
        private readonly ClockInterface $clock,
    ) {
    }

    public function execute(CreateServiceOrderInputDTO $input, string $correlationId = ''): ServiceOrder
    {
        $customer = $this->customerRepository->findById($input->customerId);
        if ($customer === null) {
            throw new NotFoundException("Cliente não encontrado: {$input->customerId}");
        }

        $vehicle = $this->vehicleRepository->findById($input->vehicleId);
        if ($vehicle === null) {
            throw new NotFoundException("Veículo não encontrado: {$input->vehicleId}");
        }

        if ($vehicle->getCustomerId() !== $input->customerId) {
            throw new DomainException('O veículo não pertence ao cliente informado.');
        }

        $order = ServiceOrder::create(
            id: $this->uuidGenerator->generate(),
            customer: $customer,
            vehicle: $vehicle,
        );
        $saga = $this->orchestrator->start($this->uuidGenerator->generate(), $order, $correlationId, $input->description, $this->clock->now());

        ($this->commit)($saga, $order);

        return $order;
    }
}
