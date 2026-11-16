<?php

declare(strict_types=1);

namespace App\Application\UseCase\Saga;

use App\Application\Messaging\MessageFactory;
use App\Application\Messaging\OutboxRelay;
use App\Application\Port\SagaRepositoryInterface;
use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Event\EventDispatcherInterface;
use App\Domain\Saga\Saga;

/**
 * O fim comum de toda mudança na saga: grava saga, OS, outbox e inbox numa transação e, só
 * depois do commit, despacha os eventos de domínio da OS (histórico de status, New Relic,
 * e-mail; ADR-004) e publica a outbox.
 */
final class SagaCommit
{
    public function __construct(
        private readonly SagaRepositoryInterface $sagas,
        private readonly MessageFactory $messages,
        private readonly EventDispatcherInterface $events,
        private readonly ?OutboxRelay $relay = null,
    ) {
    }

    public function __invoke(Saga $saga, ?ServiceOrder $order, ?string $inboxMessageId = null): void
    {
        $this->sagas->save($saga, $order, $this->messages->fromSaga($saga), $inboxMessageId);
        if ($order !== null) {
            $this->events->dispatchAll($order->releaseEvents());
        }
        $this->relay?->relay();
    }
}
