<?php

declare(strict_types=1);

namespace App\Application\UseCase\ServiceOrder;

use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Event\EventDispatcherInterface;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\ServiceOrderRepositoryInterface;

/** Entrega do veículo ao cliente: manual, depois de a saga terminar (FINISHED → DELIVERED). */
final class DeliverServiceOrderUseCase
{
    public function __construct(
        private readonly ServiceOrderRepositoryInterface $orders,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    public function execute(string $orderId): ServiceOrder
    {
        $order = $this->orders->findById($orderId) ?? throw new NotFoundException("Ordem de serviço não encontrada: {$orderId}");
        $order->deliver();
        $this->orders->save($order);
        $this->events->dispatchAll($order->releaseEvents());

        return $order;
    }
}
