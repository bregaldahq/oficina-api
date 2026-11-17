<?php

declare(strict_types=1);

namespace App\Application\Port;

/** Uma mensagem tirada da fila: o corpo cru e o recibo para confirmá-la. */
final class ReceivedMessage
{
    public function __construct(
        public readonly string $body,
        public readonly string $receipt,
    ) {
    }
}
