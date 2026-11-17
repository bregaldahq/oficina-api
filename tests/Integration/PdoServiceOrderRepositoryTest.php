<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Entity\Customer;
use App\Domain\Entity\Vehicle;
use App\Domain\ValueObject\Document;
use App\Domain\ValueObject\LicensePlate;
use App\Infrastructure\Database\PdoConnection;
use App\Infrastructure\Repository\PdoCustomerRepository;
use App\Infrastructure\Repository\PdoServiceOrderRepository;
use App\Infrastructure\Repository\PdoVehicleRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\OsDatabase;

class PdoServiceOrderRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PdoServiceOrderRepository $repo;
    private PdoCustomerRepository $customerRepo;
    private PdoVehicleRepository $vehicleRepo;

    protected function setUp(): void
    {
        $this->pdo          = OsDatabase::create();
        $this->repo         = new PdoServiceOrderRepository();
        $this->customerRepo = new PdoCustomerRepository();
        $this->vehicleRepo  = new PdoVehicleRepository();
    }

    protected function tearDown(): void
    {
        PdoConnection::reset();
    }

    private function makeCustomer(string $id = 'cust-1'): Customer
    {
        return Customer::create($id, 'João Silva', new Document('529.982.247-25'));
    }

    private function makeVehicle(string $id = 'veh-1', string $customerId = 'cust-1'): Vehicle
    {
        return Vehicle::create($id, $customerId, new LicensePlate('ABC-1234'), 'Toyota', 'Corolla', 2020);
    }

    public function testSavesAndFindsOrder(): void
    {
        $customer = $this->makeCustomer();
        $vehicle  = $this->makeVehicle();
        $this->customerRepo->save($customer);
        $this->vehicleRepo->save($vehicle);

        $order = ServiceOrder::create('order-1', $customer, $vehicle);
        $this->repo->save($order);

        $found = $this->repo->findById('order-1');

        $this->assertNotNull($found);
        $this->assertSame('order-1', $found->getId());
        $this->assertSame('RECEIVED', $found->getStatus());
    }

    public function testTransactionRollbackOnDuplicatePrimaryKey(): void
    {
        $customer = $this->makeCustomer();
        $vehicle  = $this->makeVehicle();
        $this->customerRepo->save($customer);
        $this->vehicleRepo->save($vehicle);

        $order1 = ServiceOrder::create('order-dup', $customer, $vehicle);
        $this->repo->save($order1);

        $order2          = ServiceOrder::create('order-dup', $customer, $vehicle);
        $exceptionThrown = false;

        try {
            $this->repo->save($order2);
        } catch (\Exception) {
            $exceptionThrown = true;
        }

        $this->assertTrue($exceptionThrown);

        $found = $this->repo->findById('order-dup');
        $this->assertNotNull($found);
        $this->assertSame('RECEIVED', $found->getStatus());
    }

    public function testGravaOrcamentoLinkEStatusNovosDaFase4(): void
    {
        $customer = $this->makeCustomer();
        $vehicle  = $this->makeVehicle();
        $this->customerRepo->save($customer);
        $this->vehicleRepo->save($vehicle);
        $order = ServiceOrder::create('order-1', $customer, $vehicle);
        $this->repo->save($order);

        $loaded = $this->repo->findById('order-1');
        $this->assertNotNull($loaded);
        $loaded->changeToDiagnosis();
        $loaded->sendForApproval('budget-9', 558.0);
        $loaded->approve();
        $loaded->recordPaymentLink('https://pagar');
        $this->repo->save($loaded);

        $found = $this->repo->findById('order-1');
        $this->assertNotNull($found);
        $this->assertSame('AWAITING_PAYMENT', $found->getStatus());
        $this->assertSame('budget-9', $found->getBudgetId());
        $this->assertSame('https://pagar', $found->getPaymentUrl());
        $this->assertSame(558.0, $found->getTotalAmount());
    }

    public function testParticipaDaTransacaoAbertaPorOutroRepositorio(): void
    {
        $this->customerRepo->save($this->makeCustomer());
        $this->vehicleRepo->save($this->makeVehicle());

        $this->pdo->beginTransaction();
        $this->repo->save(ServiceOrder::create('order-1', $this->makeCustomer(), $this->makeVehicle()));
        $this->assertTrue($this->pdo->inTransaction(), 'não fecha a transação de quem abriu');
        $this->pdo->rollBack();

        $this->assertNull($this->repo->findById('order-1'), 'o rollback de quem abriu desfaz a OS');
    }

    public function testSaveOfExistingOrderPersistsStatus(): void
    {
        $customer = $this->makeCustomer();
        $vehicle  = $this->makeVehicle();
        $this->customerRepo->save($customer);
        $this->vehicleRepo->save($vehicle);

        $order = ServiceOrder::create('order-1', $customer, $vehicle);
        $this->repo->save($order);

        $loaded = $this->repo->findById('order-1');
        $this->assertNotNull($loaded);
        $loaded->changeToDiagnosis();
        $this->repo->save($loaded);

        $found = $this->repo->findById('order-1');
        $this->assertNotNull($found);
        $this->assertSame('DIAGNOSIS', $found->getStatus());
    }

    public function testFindByDocumentAndLicensePlate(): void
    {
        $customer = $this->makeCustomer();
        $vehicle  = $this->makeVehicle();
        $this->customerRepo->save($customer);
        $this->vehicleRepo->save($vehicle);

        $order = ServiceOrder::create('order-1', $customer, $vehicle);
        $this->repo->save($order);

        $results = $this->repo->findByDocumentAndLicensePlate('52998224725', 'ABC1234');

        $this->assertCount(1, $results);
        $this->assertSame('order-1', $results[0]->getId());
    }

    public function testFindByDocumentAndLicensePlateWithStatusFilter(): void
    {
        $customer = $this->makeCustomer();
        $vehicle  = $this->makeVehicle();
        $this->customerRepo->save($customer);
        $this->vehicleRepo->save($vehicle);

        $order = ServiceOrder::create('order-1', $customer, $vehicle);
        $this->repo->save($order);

        $results = $this->repo->findByDocumentAndLicensePlate('52998224725', 'ABC1234', 'DIAGNOSIS');
        $this->assertCount(0, $results);

        $results = $this->repo->findByDocumentAndLicensePlate('52998224725', 'ABC1234', 'RECEIVED');
        $this->assertCount(1, $results);
    }

    public function testFindActiveOrderedPrioritizesStatusThenOldestAndExcludesClosed(): void
    {
        $this->customerRepo->save($this->makeCustomer());
        $this->vehicleRepo->save($this->makeVehicle());

        $insert = function (string $id, string $status, string $createdAt): void {
            $stmt = $this->pdo->prepare(
                'INSERT INTO service_orders (id, customer_id, vehicle_id, status, total_amount, created_at)
                 VALUES (:id, :customer_id, :vehicle_id, :status, 0, :created_at)'
            );
            $stmt->execute([
                ':id'          => $id,
                ':customer_id' => 'cust-1',
                ':vehicle_id'  => 'veh-1',
                ':status'      => $status,
                ':created_at'  => $createdAt,
            ]);
        };

        // Inserted shuffled on purpose; closed orders must be excluded entirely.
        $insert('received-new', 'RECEIVED', '2026-01-05 10:00:00');
        $insert('received-old', 'RECEIVED', '2026-01-01 10:00:00');
        $insert('diagnosis', 'DIAGNOSIS', '2026-01-02 10:00:00');
        $insert('awaiting', 'AWAITING_APPROVAL', '2026-01-03 10:00:00');
        $insert('executing', 'EXECUTING', '2026-01-04 10:00:00');
        $insert('payment', 'AWAITING_PAYMENT', '2026-01-04 11:00:00');
        $insert('cancelled', 'CANCELLED', '2026-01-01 07:00:00');
        $insert('rejected', 'REJECTED', '2026-01-01 06:00:00');
        $insert('finished', 'FINISHED', '2026-01-01 09:00:00');
        $insert('delivered', 'DELIVERED', '2026-01-01 08:00:00');

        $ids = array_map(fn ($o) => $o->getId(), $this->repo->findActiveOrdered());

        $this->assertSame([
            'executing',
            'payment',
            'awaiting',
            'diagnosis',
            'received-old',
            'received-new',
        ], $ids);
    }
}
