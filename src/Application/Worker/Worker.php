<?php

declare(strict_types=1);

namespace App\Application\Worker;

use App\Application\Messaging\OutboxRelay;
use App\Application\Messaging\RetryLaterException;
use App\Application\Port\LoggerInterface;
use App\Application\Port\MessageQueueInterface;
use App\Application\Port\TracerInterface;
use App\Application\UseCase\HandleIncomingMessageUseCase;
use App\Domain\Exception\InvalidMessageException;
use App\Domain\Messaging\Message;

/**
 * O laço do worker, uma volta por chamada de tick():
 *   1. publica no tópico o que está na outbox (as mensagens gravadas junto com o estado);
 *   2. lê a fila, trata cada mensagem e a confirma.
 *
 * Uma mensagem só é confirmada depois de tratada. Se o tratamento falha, ela fica na fila, é
 * reentregue e, na quinta falha, vai para a DLQ.
 */
final class Worker
{
    public function __construct(
        private readonly OutboxRelay $relay,
        private readonly MessageQueueInterface $queue,
        private readonly HandleIncomingMessageUseCase $handler,
        private readonly TracerInterface $tracer,
        private readonly LoggerInterface $logger,
        /** O que mais roda a cada volta (no serviço de OS, a varredura dos prazos da saga). */
        private readonly ?\Closure $afterConsume = null,
    ) {
    }

    /** @return array{published: int, processed: int, failed: int} */
    public function tick(int $waitSeconds = 20): array
    {
        // O relay nunca lança: o tópico fora do ar não impede o consumo da fila.
        $published            = $this->relay->relay();
        [$processed, $failed] = $this->consume($waitSeconds);
        if ($this->afterConsume !== null) {
            ($this->afterConsume)();
        }

        // O que o tratamento acabou de gravar sai na mesma volta, sem esperar a próxima.
        $published += $this->relay->relay();

        return ['published' => $published, 'processed' => $processed, 'failed' => $failed];
    }

    /** @return array{0: int, 1: int} processadas e com falha */
    private function consume(int $waitSeconds): array
    {
        $processed = 0;
        $failed    = 0;

        foreach ($this->queue->receive(10, $waitSeconds) as $received) {
            $startedAt = microtime(true);
            $context   = [];

            try {
                $message = Message::fromJson($received->body);
                $context = [
                    'message_id'     => $message->id,
                    'message_type'   => $message->type,
                    'saga_id'        => $message->sagaId,
                    'os_id'          => $message->osId,
                    'correlation_id' => $message->correlationId,
                ];

                $this->tracer->startMessage($message->type, $message->trace);
                $outcome = $this->handler->execute($message);
                $this->tracer->endMessage(false);

                $this->queue->delete($received);
                $processed++;

                $this->logger->info('message.processed', $context + [
                    'outcome'     => $outcome,
                    'duration_ms' => round((microtime(true) - $startedAt) * 1000, 1),
                ]);
            } catch (RetryLaterException $e) {
                // Válida, mas ainda não aplicável: fica na fila e volta depois do visibility
                // timeout. Não é erro; se nunca ficar aplicável, a DLQ mostra.
                $this->tracer->endMessage(false);
                $this->logger->info('message.processed', $context + [
                    'outcome'     => 'retry',
                    'reason'      => $e->getMessage(),
                    'duration_ms' => round((microtime(true) - $startedAt) * 1000, 1),
                ]);
            } catch (InvalidMessageException $e) {
                // Fora do envelope: reentregar não conserta. Fica na fila até a DLQ, à vista.
                $failed++;
                $this->logger->error('message.invalid', ['exception_message' => $e->getMessage()]);
            } catch (\Throwable $e) {
                $this->tracer->endMessage(true);
                $failed++;
                $this->logger->error('message.processed', $context + [
                    'outcome'           => 'error',
                    'duration_ms'       => round((microtime(true) - $startedAt) * 1000, 1),
                    'exception_class'   => $e::class,
                    'exception_message' => $e->getMessage(),
                ]);
            }
        }

        return [$processed, $failed];
    }
}
