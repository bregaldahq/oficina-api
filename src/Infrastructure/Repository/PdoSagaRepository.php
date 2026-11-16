<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Application\Port\OutboxInterface;
use App\Application\Port\SagaRepositoryInterface;
use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Exception\ConcurrencyException;
use App\Domain\Exception\DuplicateMessageException;
use App\Domain\Exception\InvalidMessageException;
use App\Domain\Messaging\Message;
use App\Domain\Repository\ServiceOrderRepositoryInterface;
use App\Domain\Saga\Saga;
use PDO;
use PDOException;

/**
 * Saga, OS, outbox e inbox numa transação só (ADR-012 e ADR-017). O repositório da OS usa a
 * mesma conexão (PdoConnection) e participa da transação aberta aqui. SQL comum a MySQL e
 * SQLite: os testes rodam este mesmo código contra SQLite em memória.
 */
final class PdoSagaRepository implements SagaRepositoryInterface, OutboxInterface
{
    private const DATE = 'Y-m-d H:i:s.v';

    /** SQLSTATE de violação de restrição, igual em MySQL e SQLite. */
    private const INTEGRITY = '23000';

    public function __construct(
        private readonly PDO $pdo,
        private readonly ServiceOrderRepositoryInterface $orders,
    ) {
    }

    public function findByOsId(string $osId): ?Saga
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sagas WHERE os_id = :os');
        $stmt->execute(['os' => $osId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findDue(\DateTimeImmutable $now, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM sagas
             WHERE state IN ('RUNNING', 'COMPENSATING') AND deadline_at IS NOT NULL AND deadline_at <= :now
             ORDER BY deadline_at
             LIMIT :limit"
        );
        $stmt->bindValue('now', $now->setTimezone(new \DateTimeZone('UTC'))->format(self::DATE));
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_values(array_map(fn (array $row): Saga => $this->hydrate($row), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []));
    }

    public function save(Saga $saga, ?ServiceOrder $order, array $outbox = [], ?string $inboxMessageId = null): void
    {
        $this->pdo->beginTransaction();
        try {
            // A inbox primeiro: a mensagem repetida para aqui, antes de qualquer efeito.
            if ($inboxMessageId !== null) {
                $this->insertInbox($inboxMessageId);
            }
            // A OS antes da saga: a saga aponta para ela (chave estrangeira).
            if ($order !== null) {
                $this->orders->save($order);
            }
            $saga->getVersion() === 0 ? $this->insert($saga) : $this->update($saga);

            $stmt = $this->pdo->prepare('INSERT INTO outbox (id, type, body, occurred_at) VALUES (:id, :type, :body, :at)');
            foreach ($outbox as $message) {
                $stmt->execute(['id' => $message->id, 'type' => $message->type, 'body' => $message->toJson(), 'at' => $message->occurredAt]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function pending(int $limit = 25): array
    {
        $stmt = $this->pdo->prepare('SELECT body FROM outbox WHERE published_at IS NULL ORDER BY occurred_at, id LIMIT :limit');
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $messages = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $body) {
            try {
                $messages[] = Message::fromJson((string)$body);
            } catch (InvalidMessageException) {
                // Um item ilegível não trava os outros; fica pendente, à vista do alerta.
            }
        }

        return $messages;
    }

    public function markPublished(Message $message): void
    {
        $this->pdo->prepare('UPDATE outbox SET published_at = :now WHERE id = :id')
            ->execute(['now' => gmdate('Y-m-d H:i:s'), 'id' => $message->id]);
    }

    private function insertInbox(string $messageId): void
    {
        try {
            $this->pdo->prepare('INSERT INTO inbox (message_id, processed_at) VALUES (:id, :now)')
                ->execute(['id' => $messageId, 'now' => gmdate('Y-m-d H:i:s')]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === self::INTEGRITY) {
                throw new DuplicateMessageException("Mensagem {$messageId} já processada.", 0, $e);
            }
            throw $e;
        }
    }

    private function insert(Saga $saga): void
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO sagas (id, os_id, correlation_id, state, step, failed_step, reason, data, pending, history,
                                    deadline_at, attempts, alerted, version, created_at, updated_at)
                 VALUES (:id, :os, :corr, :state, :step, :failed, :reason, :data, :pending, :history,
                         :deadline, :attempts, :alerted, 1, :created, :updated)'
            )->execute($this->params($saga) + [
                'os'      => $saga->getOsId(),
                'corr'    => $saga->getCorrelationId(),
                'created' => $this->date($saga->getCreatedAt()),
            ]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === self::INTEGRITY) {
                throw new ConcurrencyException("Já existe saga para a OS {$saga->getOsId()}.", 0, $e);
            }
            throw $e;
        }
    }

    private function update(Saga $saga): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sagas SET state = :state, step = :step, failed_step = :failed, reason = :reason, data = :data,
                              pending = :pending, history = :history, deadline_at = :deadline, attempts = :attempts,
                              alerted = :alerted, updated_at = :updated, version = version + 1
             WHERE id = :id AND version = :version'
        );
        $stmt->execute($this->params($saga) + ['version' => $saga->getVersion()]);
        if ($stmt->rowCount() === 0) {
            throw new ConcurrencyException("A saga {$saga->getId()} mudou desde a leitura.");
        }
    }

    /** @return array<string, mixed> */
    private function params(Saga $saga): array
    {
        return [
            'id'       => $saga->getId(),
            'state'    => $saga->getState(),
            'step'     => $saga->getStep(),
            'failed'   => $saga->getFailedStep(),
            'reason'   => $saga->getReason(),
            'data'     => $this->json($saga->getData()),
            'pending'  => $this->json($saga->getPending()),
            'history'  => $this->json($saga->getHistory()),
            'deadline' => $saga->getDeadlineAt() !== null ? $this->date($saga->getDeadlineAt()) : null,
            'attempts' => $saga->getAttempts(),
            'alerted'  => $saga->isAlerted() ? 1 : 0,
            'updated'  => $this->date($saga->getUpdatedAt()),
        ];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Saga
    {
        $decode = static function (mixed $value): array {
            $data = json_decode((string)$value, true);

            return is_array($data) ? $data : [];
        };

        return Saga::reconstitute(
            (string)$row['id'],
            (string)$row['os_id'],
            (string)$row['correlation_id'],
            (string)$row['state'],
            (string)$row['step'],
            $row['failed_step'] !== null ? (string)$row['failed_step'] : null,
            $row['reason']      !== null ? (string)$row['reason'] : null,
            $decode($row['data']),
            $decode($row['pending']),
            array_values($decode($row['history'])),
            $row['deadline_at'] !== null ? new \DateTimeImmutable((string)$row['deadline_at'], new \DateTimeZone('UTC')) : null,
            (int)$row['attempts'],
            (bool)$row['alerted'],
            new \DateTimeImmutable((string)$row['created_at'], new \DateTimeZone('UTC')),
            new \DateTimeImmutable((string)$row['updated_at'], new \DateTimeZone('UTC')),
            (int)$row['version'],
        );
    }

    /** @param array<mixed> $value */
    private function json(array $value): string
    {
        // Lista vazia e objeto vazio têm de continuar distintos: pending é um objeto.
        return (string)json_encode($value === [] ? new \stdClass() : $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function date(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format(self::DATE);
    }
}
