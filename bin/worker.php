#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Worker do serviço de OS: publica a outbox, consome a fila e varre os prazos da saga, até
 * receber SIGTERM.
 * No cluster é o Deployment `oficina-api-worker` (mesma imagem da API).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Infrastructure\Config\EnvLoader;
use App\Infrastructure\Logging\JsonLogger;
use App\Infrastructure\ServiceFactory;

EnvLoader::loadFile(__DIR__ . '/../.env');
// No container as variáveis vêm do ambiente (ConfigMap e Secret), não de um .env.
$env = $_ENV + getenv();
EnvLoader::require(['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'MESSAGING_TOPIC_ARN', 'MESSAGING_QUEUE_URL']);

$logger = new JsonLogger('oficina-api', (string)($env['APP_ENV'] ?? 'local'));
$worker = (new ServiceFactory($env, $logger, new \App\Infrastructure\Context\RequestContext()))->worker($logger);

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = static function () use (&$running): void {
        $running = false;
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

// WORKER_MAX_TICKS limita as voltas (testes e e2e); sem ele o worker roda até ser parado.
$maxTicks = (int)($env['WORKER_MAX_TICKS'] ?? 0);
$wait     = (int)($env['WORKER_WAIT_SECONDS'] ?? 20);

$logger->info('worker.started', ['wait_seconds' => $wait]);

for ($tick = 1; $running && ($maxTicks === 0 || $tick <= $maxTicks); $tick++) {
    try {
        $worker->tick($wait);
    } catch (\Throwable $e) {
        // Falha de infraestrutura (rede, credencial): registra e tenta de novo, sem derrubar o pod.
        $logger->error('worker.tick_failed', [
            'exception_class'   => $e::class,
            'exception_message' => $e->getMessage(),
        ]);
        sleep(2);
    }
}

$logger->info('worker.stopped');
