<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

/**
 * Structured logger: one JSON line per event on stdout, in the exact shape of
 * docs/fase-3/CONTRATOS.md §7 — this is what Fluent Bit ships to New Relic Logs.
 *
 * Field order is fixed (timestamp, level, message, service, env, then context) so log lines
 * stay diff-friendly and easy to eyeball in `kubectl logs`.
 */
final class JsonLogger implements \App\Application\Port\LoggerInterface
{
    public const LEVELS = ['debug', 'info', 'warn', 'error'];

    /** @var resource */
    private $stream;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param resource|null            $stream defaults to stdout
     * @param (\Closure(): float)|null $clock  returns the current unix timestamp with microseconds
     */
    public function __construct(
        private readonly string $service = 'oficina-api',
        private readonly string $env = 'local',
        $stream = null,
        ?\Closure $clock = null,
    ) {
        if ($stream === null) {
            $stream = fopen('php://stdout', 'w');
            assert($stream !== false);
        }

        $this->stream = $stream;
        $this->clock  = $clock ?? static fn (): float => microtime(true);
    }

    public static function fromEnv(): self
    {
        $env = $_ENV['APP_ENV'] ?? 'local';
        assert(is_string($env));

        return new self('oficina-api', $env);
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warn(string $message, array $context = []): void
    {
        $this->log('warn', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!in_array($level, self::LEVELS, true)) {
            $level = 'info';
        }

        $line = array_merge([
            'timestamp' => $this->timestamp(),
            'level'     => $level,
            'message'   => $message,
            'service'   => $this->service,
            'env'       => $this->env,
        ], $context);

        $json = json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return;
        }

        fwrite($this->stream, $json . "\n");
    }

    /** ISO-8601 in UTC with millisecond precision, e.g. 2026-08-26T22:31:04.512Z */
    private function timestamp(): string
    {
        $now  = ($this->clock)();
        $date = \DateTimeImmutable::createFromFormat('U.u', number_format($now, 6, '.', ''));
        assert($date !== false);

        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
