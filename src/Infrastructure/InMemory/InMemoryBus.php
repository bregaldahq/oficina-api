<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Application\Port\MessagePublisherInterface;
use App\Application\Port\MessageQueueInterface;
use App\Application\Port\ReceivedMessage;
use App\Domain\Messaging\Message;

/**
 * Tópico e fila em memória para os testes e o BDD: o que este serviço publica fica em
 * published(), e o que "os outros serviços" mandam entra por deliver(). Entrega pelo menos uma
 * vez, como o SQS: sem delete(), a mensagem volta no próximo receive().
 */
final class InMemoryBus implements MessagePublisherInterface, MessageQueueInterface
{
    /** @var list<Message> */
    private array $published = [];

    /** @var array<string, string> recibo => corpo */
    private array $queue = [];

    private int $sequence = 0;

    public function publish(Message $message): void
    {
        $this->published[] = $message;
    }

    /** @return list<Message> */
    public function published(): array
    {
        return $this->published;
    }

    /** @return list<string> */
    public function publishedTypes(): array
    {
        return array_map(static fn (Message $m): string => $m->type, $this->published);
    }

    public function deliver(Message|string $message): void
    {
        $this->queue['r' . ++$this->sequence] = is_string($message) ? $message : $message->toJson();
    }

    public function receive(int $max = 10, int $waitSeconds = 20): array
    {
        $out = [];
        foreach (array_slice($this->queue, 0, $max, true) as $receipt => $body) {
            $out[] = new ReceivedMessage($body, (string)$receipt);
        }

        return $out;
    }

    public function delete(ReceivedMessage $message): void
    {
        unset($this->queue[$message->receipt]);
    }

    public function queued(): int
    {
        return count($this->queue);
    }
}
