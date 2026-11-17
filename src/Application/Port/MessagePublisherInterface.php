<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Messaging\Message;

interface MessagePublisherInterface
{
    public function publish(Message $message): void;
}
