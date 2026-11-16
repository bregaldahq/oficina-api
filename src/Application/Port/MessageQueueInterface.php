<?php

declare(strict_types=1);

namespace App\Application\Port;

interface MessageQueueInterface
{
    /** @return list<ReceivedMessage> */
    public function receive(int $max = 10, int $waitSeconds = 20): array;

    /** Confirma o processamento. Sem isso a fila reentrega e, na quinta vez, manda para a DLQ. */
    public function delete(ReceivedMessage $message): void;
}
