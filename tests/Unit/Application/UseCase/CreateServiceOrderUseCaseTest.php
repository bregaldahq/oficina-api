<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCase;

use App\Application\DTO\ServiceOrder\CreateServiceOrderInputDTO;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Saga\Saga;
use App\Domain\ValueObject\Document;
use App\Domain\ValueObject\LicensePlate;
use App\Infrastructure\Repository\PdoCustomerRepository;
use App\Infrastructure\Repository\PdoVehicleRepository;
use PHPUnit\Framework\TestCase;
use Tests\Support\SagaHarness;

class CreateServiceOrderUseCaseTest extends TestCase
{
    private SagaHarness $h;

    protected function setUp(): void
    {
        $this->h = new SagaHarness();
    }

    private function createWith(string $customerId, string $vehicleId): void
    {
        (new \App\Application\UseCase\ServiceOrder\CreateServiceOrderUseCase(
            new PdoCustomerRepository(),
            new PdoVehicleRepository(),
            new \Tests\Support\SequenceUuid(),
            $this->h->orchestrator,
            $this->h->commit,
            $this->h->clock,
        ))->execute(new CreateServiceOrderInputDTO($customerId, $vehicleId));
    }

    public function testCriaAOsEASagaNaMesmaGravacao(): void
    {
        $order = $this->h->create();

        $this->assertSame('RECEIVED', $this->h->order($order->getId())->getStatus());
        $this->assertSame(Saga::STEP_DIAGNOSIS, $this->h->saga($order->getId())->getStep());
        $this->assertSame([], $this->h->sagas->pending(), 'o pedido de diagnóstico já saiu da outbox');
    }

    public function testClienteInexistente(): void
    {
        $this->expectException(NotFoundException::class);

        $this->createWith('nao-existe', SagaHarness::VEHICLE);
    }

    public function testVeiculoInexistente(): void
    {
        $this->expectException(NotFoundException::class);

        $this->createWith(SagaHarness::CUSTOMER, 'nao-existe');
    }

    public function testVeiculoDeOutroCliente(): void
    {
        (new PdoCustomerRepository())->save(\App\Domain\Entity\Customer::create('cli-2', 'Ana', new Document('390.533.447-05')));
        (new PdoVehicleRepository())->save(\App\Domain\Entity\Vehicle::create('vei-2', 'cli-2', new LicensePlate('XYZ9A87'), 'VW', 'Gol', 2012));

        $this->expectException(DomainException::class);
        $this->createWith(SagaHarness::CUSTOMER, 'vei-2');
    }
}
