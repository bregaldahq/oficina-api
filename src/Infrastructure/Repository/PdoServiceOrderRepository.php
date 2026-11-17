<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Entity\Customer;
use App\Domain\Entity\Vehicle;
use App\Domain\Repository\ServiceOrderRepositoryInterface;
use App\Domain\ValueObject\CustomerStatus;
use App\Domain\ValueObject\Document;
use App\Domain\ValueObject\LicensePlate;
use App\Infrastructure\Database\PdoConnection;
use PDO;

class PdoServiceOrderRepository implements ServiceOrderRepositoryInterface
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = PdoConnection::getInstance();
    }

    public function findById(string $id): ?ServiceOrder
    {
        $stmt = $this->pdo->prepare(
            'SELECT so.*,
                    c.name AS c_name, c.document AS c_document,
                    c.status AS c_status, c.email AS c_email, c.phone AS c_phone,
                    v.customer_id AS v_customer_id, v.license_plate AS v_plate,
                    v.brand AS v_brand, v.model AS v_model, v.year AS v_year
             FROM service_orders so
             JOIN customers c ON c.id = so.customer_id
             JOIN vehicles  v ON v.id = so.vehicle_id
             WHERE so.id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT so.*,
                    c.name AS c_name, c.document AS c_document,
                    c.status AS c_status, c.email AS c_email, c.phone AS c_phone,
                    v.customer_id AS v_customer_id, v.license_plate AS v_plate,
                    v.brand AS v_brand, v.model AS v_model, v.year AS v_year
             FROM service_orders so
             JOIN customers c ON c.id = so.customer_id
             JOIN vehicles  v ON v.id = so.vehicle_id
             ORDER BY so.created_at DESC'
        );
        assert($stmt !== false);
        return array_map(fn (array $row) => $this->hydrate($row), $stmt->fetchAll());
    }

    public function findActiveOrdered(): array
    {
        $stmt = $this->pdo->query(
            "SELECT so.*,
                    c.name AS c_name, c.document AS c_document,
                    c.status AS c_status, c.email AS c_email, c.phone AS c_phone,
                    v.customer_id AS v_customer_id, v.license_plate AS v_plate,
                    v.brand AS v_brand, v.model AS v_model, v.year AS v_year
             FROM service_orders so
             JOIN customers c ON c.id = so.customer_id
             JOIN vehicles  v ON v.id = so.vehicle_id
             WHERE so.status NOT IN ('FINISHED', 'DELIVERED', 'REJECTED', 'CANCELLED')
             ORDER BY CASE so.status
                          WHEN 'EXECUTING'         THEN 1
                          WHEN 'AWAITING_PAYMENT'  THEN 2
                          WHEN 'AWAITING_APPROVAL' THEN 3
                          WHEN 'DIAGNOSIS'         THEN 4
                          WHEN 'RECEIVED'          THEN 5
                          ELSE 6
                      END ASC,
                      so.created_at ASC"
        );
        assert($stmt !== false);
        return array_map(fn (array $row) => $this->hydrate($row), $stmt->fetchAll());
    }

    public function findByCustomerId(string $customerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT so.*,
                    c.name AS c_name, c.document AS c_document,
                    c.status AS c_status, c.email AS c_email, c.phone AS c_phone,
                    v.customer_id AS v_customer_id, v.license_plate AS v_plate,
                    v.brand AS v_brand, v.model AS v_model, v.year AS v_year
             FROM service_orders so
             JOIN customers c ON c.id = so.customer_id
             JOIN vehicles  v ON v.id = so.vehicle_id
             WHERE so.customer_id = :customer_id
             ORDER BY so.created_at DESC'
        );
        $stmt->execute([':customer_id' => $customerId]);

        return array_map(fn (array $row) => $this->hydrate($row), $stmt->fetchAll());
    }

    public function findByDocumentAndLicensePlate(string $document, string $licensePlate, ?string $status = null): array
    {
        $sql = 'SELECT so.*,
                       c.name AS c_name, c.document AS c_document,
                    c.status AS c_status, c.email AS c_email, c.phone AS c_phone,
                       v.customer_id AS v_customer_id, v.license_plate AS v_plate,
                       v.brand AS v_brand, v.model AS v_model, v.year AS v_year
                FROM service_orders so
                JOIN customers c ON c.id = so.customer_id
                JOIN vehicles  v ON v.id = so.vehicle_id
                WHERE c.document = :document AND v.license_plate = :plate';

        $params = [':document' => $document, ':plate' => $licensePlate];

        if ($status !== null) {
            $sql .= ' AND so.status = :status';
            $params[':status'] = $status;
        }

        $sql .= ' ORDER BY so.created_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(fn (array $row) => $this->hydrate($row), $stmt->fetchAll());
    }

    /**
     * Grava a OS. Se já houver uma transação aberta na conexão (a do repositório da saga, que
     * grava saga, OS, outbox e inbox juntas: ADR-012 e ADR-017), participa dela; senão abre a
     * própria (ADR-004).
     */
    public function save(ServiceOrder $order): void
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }

        try {
            $params = [
                ':id'           => $order->getId(),
                ':status'       => $order->getStatus(),
                ':total_amount' => $order->getTotalAmount(),
                ':budget_id'    => $order->getBudgetId(),
                ':payment_url'  => $order->getPaymentUrl(),
            ];

            $insert = fn () => $this->pdo->prepare(
                'INSERT INTO service_orders (id, customer_id, vehicle_id, status, total_amount, budget_id, payment_url)
                 VALUES (:id, :customer_id, :vehicle_id, :status, :total_amount, :budget_id, :payment_url)'
            )->execute($params + [
                ':customer_id' => $order->getCustomer()->getId(),
                ':vehicle_id'  => $order->getVehicle()->getId(),
            ]);

            if ($order->isNew()) {
                // OS nova: INSERT puro, para um id repetido falhar em vez de sobrescrever outra OS.
                $insert();
            } else {
                $update = $this->pdo->prepare(
                    'UPDATE service_orders
                     SET status = :status, total_amount = :total_amount, budget_id = :budget_id, payment_url = :payment_url
                     WHERE id = :id'
                );
                $update->execute($params);

                $exists = $this->pdo->prepare('SELECT 1 FROM service_orders WHERE id = :id');
                $exists->execute([':id' => $order->getId()]);
                if ($exists->fetchColumn() === false) {
                    $insert();
                }
            }

            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ServiceOrder
    {
        $customer = Customer::create(
            id: $row['customer_id'],
            name: $row['c_name'],
            document: new Document($row['c_document']),
            status: CustomerStatus::fromString(isset($row['c_status']) ? (string)$row['c_status'] : null),
            email: isset($row['c_email']) ? (string)$row['c_email'] : null,
            phone: isset($row['c_phone']) ? (string)$row['c_phone'] : null,
        );

        $vehicle = Vehicle::create(
            id: $row['vehicle_id'],
            customerId: $row['v_customer_id'],
            licensePlate: new LicensePlate($row['v_plate']),
            brand: $row['v_brand'],
            model: $row['v_model'],
            year: (int)$row['v_year'],
        );

        return ServiceOrder::reconstitute(
            id: $row['id'],
            customer: $customer,
            vehicle: $vehicle,
            status: $row['status'],
            totalAmount: round((float)$row['total_amount'], 2),
            createdAt: new \DateTimeImmutable($row['created_at']),
            budgetId: isset($row['budget_id']) ? (string)$row['budget_id'] : null,
            paymentUrl: isset($row['payment_url']) ? (string)$row['payment_url'] : null,
        );
    }
}
