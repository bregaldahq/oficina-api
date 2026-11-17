<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Messaging\Message;

/** As mensagens gravadas junto com o estado e ainda não publicadas no tópico. */
interface OutboxInterface
{
    /** @return list<Message> as mais antigas primeiro */
    public function pending(int $limit = 25): array;

    public function markPublished(Message $message): void;
}
