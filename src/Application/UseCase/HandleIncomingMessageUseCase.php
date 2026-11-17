<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Application\Messaging\RetryLaterException;
use App\Application\Port\ClockInterface;
use App\Application\Port\SagaRepositoryInterface;
use App\Application\UseCase\Saga\SagaCommit;
use App\Domain\Exception\ConcurrencyException;
use App\Domain\Exception\DuplicateMessageException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Messaging\Message;
use App\Domain\Repository\ServiceOrderRepositoryInterface;
use App\Domain\Saga\SagaOrchestrator;

/**
 * Os eventos que a Execução e o Faturamento publicam para a saga
 * (docs/fase-4/CONTRATOS.md §5.3, no oficina-api).
 */
final class HandleIncomingMessageUseCase
{
    public const OUTCOME_OK        = 'ok';
    public const OUTCOME_DUPLICATE = 'duplicate';
    public const OUTCOME_IGNORED   = 'ignored';

    public function __construct(
        private readonly SagaRepositoryInterface $sagas,
        private readonly ServiceOrderRepositoryInterface $orders,
        private readonly SagaOrchestrator $orchestrator,
        private readonly SagaCommit $commit,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Novas tentativas na hora, com a saga recarregada, quando outra gravação ganhou a corrida. */
    private const CONCURRENCY_ATTEMPTS = 3;

    public function execute(Message $message): string
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->apply($message);
            } catch (ConcurrencyException $e) {
                // A API, a varredura de prazos ou outra mensagem gravou a mesma saga antes.
                if ($attempt >= self::CONCURRENCY_ATTEMPTS) {
                    throw new RetryLaterException('A saga mudou durante o processamento: ' . $e->getMessage(), 0, $e);
                }
            }
        }
    }

    private function apply(Message $message): string
    {
        $saga = $this->sagas->findByOsId($message->osId);
        if ($saga === null || $saga->getId() !== $message->sagaId) {
            // Evento de uma OS sem saga (ou de outra saga): nada a fazer aqui.
            return self::OUTCOME_IGNORED;
        }

        $order = $this->orders->findById($message->osId);
        if ($order === null) {
            throw new NotFoundException("A saga {$saga->getId()} aponta para uma OS que não existe.");
        }

        if (!$this->orchestrator->handle($saga, $order, $message->type, $message->payload, $this->clock->now())) {
            if ($this->orchestrator->isAhead($saga, $message->type)) {
                throw new RetryLaterException("{$message->type} chegou antes de a saga entrar no passo dele (está em {$saga->getStep()}).");
            }

            // Fora de hora, repetido por outro caminho, ou de saga encerrada (§5.4).
            return self::OUTCOME_IGNORED;
        }

        try {
            ($this->commit)($saga, $order, $message->id);
        } catch (DuplicateMessageException) {
            return self::OUTCOME_DUPLICATE;
        }

        return self::OUTCOME_OK;
    }
}
