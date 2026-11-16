<?php

declare(strict_types=1);

namespace App\Domain\Saga;

/** Os prazos da saga (docs/fase-4/CONTRATOS.md §6.3, no oficina-api), em segundos. */
final class SagaTimeouts
{
    public function __construct(
        public readonly int $budget = 300,
        public readonly int $approval = 259200,
        public readonly int $payment = 86400,
        public readonly int $workAlert = 172800,
        /** Espera antes de reenviar uma compensação sem confirmação; cresce a cada tentativa. */
        public readonly int $compensationRetry = 60,
        public readonly int $maxCompensationAttempts = 5,
    ) {
    }

    /** @param array<string, mixed> $env */
    public static function fromEnv(array $env): self
    {
        $int = static fn (string $key, int $default): int => is_numeric($env[$key] ?? null) ? (int)$env[$key] : $default;

        return new self(
            $int('SAGA_BUDGET_TIMEOUT_SECONDS', 300),
            $int('SAGA_APPROVAL_TIMEOUT_SECONDS', 259200),
            $int('SAGA_PAYMENT_TIMEOUT_SECONDS', 86400),
            $int('SAGA_WORK_ALERT_SECONDS', 172800),
            $int('SAGA_COMPENSATION_RETRY_SECONDS', 60),
            $int('SAGA_MAX_COMPENSATION_ATTEMPTS', 5),
        );
    }
}
