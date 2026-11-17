<?php

declare(strict_types=1);

namespace App\Infrastructure\Tracing;

use App\Application\Port\TracerInterface;

/**
 * Leva o trace distribuído pela fila com o agente PHP do New Relic
 * (docs/fase-4/CONTRATOS.md §8, no oficina-api). Sem a extensão carregada, tudo é no-op, para
 * não quebrar os testes nem o ambiente local.
 */
final class NewRelicTracer implements TracerInterface
{
    private readonly bool $enabled;

    public function __construct(private readonly string $appName, ?bool $enabled = null)
    {
        $this->enabled = $enabled ?? extension_loaded('newrelic');
    }

    public function currentTrace(): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $headers = [];
        newrelic_insert_distributed_trace_headers($headers);

        $encoded = json_encode($headers);

        return $encoded === false || $encoded === '[]' ? null : $encoded;
    }

    public function startMessage(string $type, ?string $trace): void
    {
        if (!$this->enabled) {
            return;
        }

        // Um processo de longa duração é uma transação só para o agente: cada mensagem abre a sua.
        newrelic_end_transaction();
        newrelic_start_transaction($this->appName);
        newrelic_background_job(true);
        newrelic_name_transaction('message/' . $type);

        $headers = $trace === null ? null : json_decode($trace, true);
        if (is_array($headers)) {
            newrelic_accept_distributed_trace_headers($headers, 'Queue');
        }
    }

    public function endMessage(bool $failed): void
    {
        if (!$this->enabled) {
            return;
        }

        if ($failed) {
            newrelic_notice_error('falha ao processar a mensagem');
        }
        newrelic_end_transaction();
    }
}
