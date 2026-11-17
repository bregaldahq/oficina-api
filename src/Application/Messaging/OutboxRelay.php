<?php

declare(strict_types=1);

namespace App\Application\Messaging;

use App\Application\Port\LoggerInterface;
use App\Application\Port\MessagePublisherInterface;
use App\Application\Port\OutboxInterface;
use App\Application\Port\TracerInterface;

/**
 * Publica no tópico o que está pendente na outbox.
 *
 * Roda em dois lugares: logo depois de cada ação da API, para o evento sair na hora, e em toda
 * volta do worker, que é a rede de segurança (o processo da API pode cair entre gravar e
 * publicar). Nunca lança: uma mensagem que não saiu continua pendente e a próxima passada tenta
 * de novo. Se cair entre publicar e marcar, a mensagem sai duas vezes e a inbox do destino
 * resolve (ADR-017).
 */
final class OutboxRelay
{
    public function __construct(
        private readonly OutboxInterface $outbox,
        private readonly MessagePublisherInterface $publisher,
        private readonly TracerInterface $tracer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return int quantas mensagens foram publicadas */
    public function relay(): int
    {
        try {
            $pending = $this->outbox->pending();
        } catch (\Throwable $e) {
            $this->logger->error('outbox.read_failed', ['exception_class' => $e::class, 'exception_message' => $e->getMessage()]);

            return 0;
        }

        $count = 0;
        foreach ($pending as $message) {
            try {
                $this->publisher->publish($message->withTrace($message->trace ?? $this->tracer->currentTrace()));
                $this->outbox->markPublished($message);
                $count++;
            } catch (\Throwable $e) {
                $this->logger->error('outbox.publish_failed', [
                    'message_id'        => $message->id,
                    'message_type'      => $message->type,
                    'saga_id'           => $message->sagaId,
                    'os_id'             => $message->osId,
                    'exception_class'   => $e::class,
                    'exception_message' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }
}
