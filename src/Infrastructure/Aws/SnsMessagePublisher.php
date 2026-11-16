<?php

declare(strict_types=1);

namespace App\Infrastructure\Aws;

use App\Application\Port\MessagePublisherInterface;
use App\Domain\Messaging\Message;
use Aws\Sns\SnsClient;

/** Publica no tópico único de mensagens. O atributo `type` é o que o filtro de cada fila lê. */
final class SnsMessagePublisher implements MessagePublisherInterface
{
    public function __construct(
        private readonly SnsClient $sns,
        private readonly string $topicArn,
    ) {
    }

    public function publish(Message $message): void
    {
        $this->sns->publish([
            'TopicArn'          => $this->topicArn,
            'Message'           => $message->toJson(),
            'MessageAttributes' => [
                'type' => ['DataType' => 'String', 'StringValue' => $message->type],
            ],
        ]);
    }
}
