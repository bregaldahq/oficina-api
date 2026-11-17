<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Exception\ConcurrencyException;
use App\Domain\Exception\DuplicateMessageException;
use App\Domain\Messaging\Message;
use App\Domain\Saga\Saga;

interface SagaRepositoryInterface
{
    public function findByOsId(string $osId): ?Saga;

    /**
     * Sagas em andamento ou em compensação com o prazo vencido, as mais atrasadas primeiro.
     *
     * @return list<Saga>
     */
    public function findDue(\DateTimeImmutable $now, int $limit = 50): array;

    /**
     * Grava numa transação (ADR-012 e ADR-017): a inbox, a OS, a saga e as mensagens de saída.
     * Avançar a saga e mudar o status da OS nunca ficam pela metade.
     *
     * @param list<Message> $outbox
     *
     * @throws DuplicateMessageException a mensagem de entrada já tinha sido tratada
     * @throws ConcurrencyException      a saga mudou desde a leitura
     */
    public function save(Saga $saga, ?ServiceOrder $order, array $outbox = [], ?string $inboxMessageId = null): void;
}
