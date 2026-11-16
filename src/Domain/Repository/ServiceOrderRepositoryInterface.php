<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Aggregate\ServiceOrder;

interface ServiceOrderRepositoryInterface
{
    public function findById(string $id): ?ServiceOrder;

    /** @return ServiceOrder[] */
    public function findAll(): array;

    /**
     * Lists open orders for the workshop board: closed orders (FINISHED, DELIVERED)
     * are excluded, the rest are ranked by workflow priority
     * (EXECUTING > AWAITING_APPROVAL > DIAGNOSIS > RECEIVED), oldest first within a status.
     *
     * @return ServiceOrder[]
     */
    public function findActiveOrdered(): array;

    /**
     * Orders owned by a customer, newest first. Used by GET /api/service-orders/me.
     *
     * @return ServiceOrder[]
     */
    public function findByCustomerId(string $customerId): array;

    /** @return ServiceOrder[] */
    public function findByDocumentAndLicensePlate(string $document, string $licensePlate, ?string $status = null): array;

    /**
     * Upsert: creates or updates the order.
     * When items are present, also inserts into service_order_services/parts
     * and decrements parts_inventory.stock_quantity (within a transaction).
     */
    public function save(ServiceOrder $order): void;
}
