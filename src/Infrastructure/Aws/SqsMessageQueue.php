<?php

declare(strict_types=1);

namespace App\Infrastructure\Aws;

use App\Application\Port\MessageQueueInterface;
use App\Application\Port\ReceivedMessage;
use Aws\Sqs\SqsClient;

/** A fila do próprio serviço. A assinatura do tópico entrega o corpo cru (raw delivery). */
final class SqsMessageQueue implements MessageQueueInterface
{
    public function __construct(
        private readonly SqsClient $sqs,
        private readonly string $queueUrl,
    ) {
    }

    public function receive(int $max = 10, int $waitSeconds = 20): array
    {
        $result = $this->sqs->receiveMessage([
            'QueueUrl'            => $this->queueUrl,
            'MaxNumberOfMessages' => max(1, min(10, $max)),
            'WaitTimeSeconds'     => max(0, min(20, $waitSeconds)),
        ]);

        $messages = [];
        foreach ((array)($result['Messages'] ?? []) as $raw) {
            $messages[] = new ReceivedMessage((string)($raw['Body'] ?? ''), (string)($raw['ReceiptHandle'] ?? ''));
        }

        return $messages;
    }

    public function delete(ReceivedMessage $message): void
    {
        $this->sqs->deleteMessage([
            'QueueUrl'      => $this->queueUrl,
            'ReceiptHandle' => $message->receipt,
        ]);
    }
}
