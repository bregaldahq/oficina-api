<?php

declare(strict_types=1);

namespace App\Application\Port;

/** Rastreamento distribuído pela fila (docs/fase-4/CONTRATOS.md §8). Sem agente, é no-op. */
interface TracerInterface
{
    /** O contexto de trace a gravar no envelope de quem publica. */
    public function currentTrace(): ?string;

    /** Abre a transação de background de uma mensagem e continua o trace que ela trouxe. */
    public function startMessage(string $type, ?string $trace): void;

    public function endMessage(bool $failed): void;
}
