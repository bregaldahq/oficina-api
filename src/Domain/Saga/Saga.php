<?php

declare(strict_types=1);

namespace App\Domain\Saga;

use App\Domain\Event\SagaEvent;
use App\Domain\Exception\DomainException;

/**
 * O estado da saga de uma ordem de serviço (ADR-012). Guarda em que passo ela está, o prazo do
 * passo, os dados que os próximos comandos vão precisar e as compensações que ainda esperam
 * confirmação. Não decide nada sobre o fluxo: quem decide é o SagaOrchestrator; aqui ficam as
 * transições de estado e os comandos a publicar (releaseCommands), que o repositório grava na
 * outbox junto com a saga (ADR-017).
 *
 *   RUNNING ──(último passo)──► COMPLETED
 *      └──(falha, prazo, cancelamento)──► COMPENSATING ──(tudo confirmado)──► COMPENSATED
 *                                              └──(tentativas esgotadas)──► FAILED
 */
final class Saga
{
    public const RUNNING      = 'RUNNING';
    public const COMPENSATING = 'COMPENSATING';
    public const COMPLETED    = 'COMPLETED';
    public const COMPENSATED  = 'COMPENSATED';
    public const FAILED       = 'FAILED';

    public const STEP_DIAGNOSIS = 'DIAGNOSIS';
    public const STEP_BUDGET    = 'BUDGET';
    public const STEP_APPROVAL  = 'APPROVAL';
    public const STEP_PAYMENT   = 'PAYMENT';
    public const STEP_REPAIR    = 'REPAIR';
    public const STEP_DONE      = 'DONE';

    /** Cada compensação e o evento que a confirma (§6.2). */
    public const CONFIRMATIONS = [
        'execution.cancel'       => 'execution.cancelled',
        'billing.cancel-budget'  => 'budget.cancelled',
        'billing.refund-payment' => 'payment.refunded',
    ];

    private const DATE = 'Y-m-d\TH:i:s.v\Z';

    /** O tamanho da coluna sagas.reason (migration 004 da fundação). */
    public const REASON_MAX = 255;

    /** @var list<array{type: string, payload: array<string, mixed>}> */
    private array $commands = [];

    /** @var list<SagaEvent> marcos para a observabilidade, soltos depois do commit */
    private array $events = [];

    /**
     * @param array<string, mixed>                $data    dados dos próximos comandos
     * @param array<string, array<string, mixed>> $pending compensação => payload, até a confirmação
     * @param list<array<string, mixed>>          $history linha do tempo da saga
     */
    private function __construct(
        private readonly string $id,
        private readonly string $osId,
        private readonly string $correlationId,
        private string $state,
        private string $step,
        private ?string $failedStep,
        private ?string $reason,
        private array $data,
        private array $pending,
        private array $history,
        private ?\DateTimeImmutable $deadlineAt,
        private int $attempts,
        private bool $alerted,
        private readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
        private readonly int $version,
    ) {
    }

    /**
     * Abre a saga no passo de diagnóstico e pede a abertura da tarefa à Execução.
     *
     * @param array{id: string, name: string, email: string}             $customer
     * @param array{license_plate: string, brand: string, model: string} $vehicle
     */
    public static function start(
        string $id,
        string $osId,
        string $correlationId,
        array $customer,
        array $vehicle,
        string $description,
        \DateTimeImmutable $now,
        \DateTimeImmutable $deadline,
    ): self {
        $saga = new self(
            $id,
            $osId,
            $correlationId,
            self::RUNNING,
            self::STEP_DIAGNOSIS,
            null,
            null,
            ['customer' => $customer, 'vehicle' => $vehicle, 'description' => $description, 'items' => [], 'budget_id' => null, 'paid' => false],
            [],
            [],
            $deadline,
            0,
            false,
            $now,
            $now,
            0,
        );
        $saga->log($now, 'saga.started', self::STEP_DIAGNOSIS);
        $saga->record(SagaEvent::STARTED, $now, []);
        $saga->send('execution.request-diagnosis', [
            'customer_name' => $customer['name'],
            'vehicle'       => $vehicle,
            'description'   => $description,
        ]);

        return $saga;
    }

    /**
     * Hidratação a partir do banco (ADR-005).
     *
     * @param array<string, mixed>                $data
     * @param array<string, array<string, mixed>> $pending
     * @param list<array<string, mixed>>          $history
     */
    public static function reconstitute(
        string $id,
        string $osId,
        string $correlationId,
        string $state,
        string $step,
        ?string $failedStep,
        ?string $reason,
        array $data,
        array $pending,
        array $history,
        ?\DateTimeImmutable $deadlineAt,
        int $attempts,
        bool $alerted,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        int $version,
    ): self {
        return new self($id, $osId, $correlationId, $state, $step, $failedStep, $reason, $data, $pending, $history, $deadlineAt, $attempts, $alerted, $createdAt, $updatedAt, $version);
    }

    /**
     * Avança para o próximo passo, com o prazo dele, e publica o comando que o abre (se houver).
     *
     * @param array<string, mixed> $payload
     */
    public function advance(string $step, ?\DateTimeImmutable $deadline, \DateTimeImmutable $now, ?string $command = null, array $payload = []): void
    {
        $this->requireRunning('avançar');
        $this->stepCompleted($now);
        $this->step       = $step;
        $this->deadlineAt = $deadline;
        $this->alerted    = false;
        $this->updatedAt  = $now;
        $this->log($now, 'step.entered', $step);
        if ($command !== null) {
            $this->send($command, $payload);
        }
    }

    /** Guarda um dado de que os próximos passos precisam (itens, id do orçamento). */
    public function remember(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    /** Registra na linha do tempo um evento que não muda o passo (repair.started, payment.created). */
    public function note(string $event, \DateTimeImmutable $now, ?string $detail = null): void
    {
        $this->updatedAt = $now;
        $this->log($now, $event, $this->step, $detail);
    }

    /**
     * Desfaz o que já foi feito: publica as compensações e espera a confirmação de cada uma. Sem
     * compensação a fazer, a saga já termina compensada.
     *
     * @param array<string, array<string, mixed>> $compensations compensação => payload
     */
    public function compensate(string $reason, array $compensations, \DateTimeImmutable $now, \DateTimeImmutable $retryAt): void
    {
        $this->requireRunning('compensar');
        foreach (array_keys($compensations) as $type) {
            if (!isset(self::CONFIRMATIONS[$type])) {
                throw new DomainException("Compensação desconhecida: {$type}.");
            }
        }

        $this->failedStep = $this->step;
        $this->reason     = mb_substr($reason, 0, self::REASON_MAX);
        $this->pending    = $compensations;
        $this->attempts   = 1;
        $this->updatedAt  = $now;
        $this->log($now, 'saga.compensating', $this->step, $reason);

        if ($compensations === []) {
            $this->finishCompensation($now);

            return;
        }

        $this->state      = self::COMPENSATING;
        $this->deadlineAt = $retryAt;
        foreach ($compensations as $type => $payload) {
            $this->send($type, $payload);
        }
    }

    /**
     * Uma confirmação de compensação chegou. Devolve false se não era esperada. Vale também para
     * uma saga FAILED: confirmação atrasada ainda fecha a compensação (e encerra o alerta).
     */
    public function confirm(string $event, \DateTimeImmutable $now): bool
    {
        $command = array_search($event, self::CONFIRMATIONS, true);
        if (!in_array($this->state, [self::COMPENSATING, self::FAILED], true) || $command === false || !isset($this->pending[$command])) {
            return false;
        }

        unset($this->pending[$command]);
        $this->updatedAt = $now;
        $this->log($now, $event, $this->step);
        if ($this->pending === []) {
            $this->finishCompensation($now);
        }

        return true;
    }

    /**
     * O prazo da compensação venceu sem todas as confirmações: reenvia o que falta, com espera
     * crescente. Esgotadas as tentativas, a saga fica FAILED: é o caso que pede uma pessoa.
     */
    public function retryCompensation(\DateTimeImmutable $now, int $retrySeconds, int $maxAttempts): void
    {
        if ($this->state !== self::COMPENSATING) {
            throw new DomainException('Só se reenvia compensação de uma saga em compensação.');
        }

        $this->updatedAt = $now;
        if ($this->attempts >= $maxAttempts) {
            $this->state      = self::FAILED;
            $this->deadlineAt = null;
            $this->log($now, 'saga.failed', $this->step, 'compensações sem confirmação: ' . implode(', ', array_keys($this->pending)));
            $this->record(SagaEvent::FAILED, $now, [
                'failedStep' => $this->failedStep,
                'reason'     => $this->reason,
                'pending'    => implode(',', array_keys($this->pending)),
            ]);

            return;
        }

        $this->attempts++;
        $this->deadlineAt = $now->modify('+' . ($retrySeconds * $this->attempts) . ' seconds');
        $this->log($now, 'compensation.retried', $this->step, "tentativa {$this->attempts}");
        foreach ($this->pending as $type => $payload) {
            $this->send($type, $payload);
        }
    }

    /**
     * Publica um comando sem mudar o estado da saga: o caso de algo que outro serviço criou
     * depois de a saga já ter desfeito o passo (um orçamento gerado depois do cancelamento).
     *
     * @param array<string, mixed> $payload
     */
    public function sendLate(string $command, array $payload, \DateTimeImmutable $now, string $event): void
    {
        $this->updatedAt = $now;
        $this->log($now, $event, $this->step, $command);
        $this->send($command, $payload);
    }

    public function complete(\DateTimeImmutable $now): void
    {
        $this->requireRunning('concluir');
        $this->stepCompleted($now);
        $this->state      = self::COMPLETED;
        $this->step       = self::STEP_DONE;
        $this->deadlineAt = null;
        $this->updatedAt  = $now;
        $this->log($now, 'saga.completed', self::STEP_DONE);
    }

    /** O passo passou do prazo de alerta (diagnóstico, reparo): avisa uma vez e espera. */
    public function markStalled(\DateTimeImmutable $now): void
    {
        $this->alerted    = true;
        $this->deadlineAt = null;
        $this->updatedAt  = $now;
        $this->log($now, 'saga.stalled', $this->step);
    }

    public function isRunning(): bool
    {
        return $this->state === self::RUNNING;
    }

    public function isCompensating(): bool
    {
        return $this->state === self::COMPENSATING;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOsId(): string
    {
        return $this->osId;
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getStep(): string
    {
        return $this->step;
    }

    public function getFailedStep(): ?string
    {
        return $this->failedStep;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }

    /** @return array<string, array<string, mixed>> */
    public function getPending(): array
    {
        return $this->pending;
    }

    /** @return list<array<string, mixed>> */
    public function getHistory(): array
    {
        return $this->history;
    }

    public function getDeadlineAt(): ?\DateTimeImmutable
    {
        return $this->deadlineAt;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function isAlerted(): bool
    {
        return $this->alerted;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /** @return list<SagaEvent> */
    public function releaseEvents(): array
    {
        $events       = $this->events;
        $this->events = [];

        return $events;
    }

    /** @return list<array{type: string, payload: array<string, mixed>}> */
    public function releaseCommands(): array
    {
        $commands       = $this->commands;
        $this->commands = [];

        return $commands;
    }

    /**
     * O que a API mostra da saga junto com a OS.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'                    => $this->id,
            'state'                 => $this->state,
            'step'                  => $this->step,
            'failed_step'           => $this->failedStep,
            'reason'                => $this->reason,
            'deadline_at'           => $this->deadlineAt?->format(self::DATE),
            'pending_compensations' => array_keys($this->pending),
            'history'               => $this->history,
        ];
    }

    private function finishCompensation(\DateTimeImmutable $now): void
    {
        // Confirmação atrasada de uma saga FAILED: a mesma saga já contou como falha, e o
        // dashboard desconta as que se recuperaram (afterFailure).
        $afterFailure     = $this->state === self::FAILED;
        $this->state      = self::COMPENSATED;
        $this->deadlineAt = null;
        $this->log($now, 'saga.compensated', $this->step);
        $this->record(SagaEvent::COMPENSATED, $now, ['failedStep' => $this->failedStep, 'reason' => $this->reason, 'afterFailure' => $afterFailure]);
    }

    /** O passo atual terminou: quanto tempo ele levou, desde que a saga entrou nele. */
    private function stepCompleted(\DateTimeImmutable $now): void
    {
        $enteredAt = $this->createdAt;
        foreach (array_reverse($this->history) as $entry) {
            if (in_array($entry['event'] ?? null, ['saga.started', 'step.entered'], true) && is_string($entry['at'] ?? null)) {
                $enteredAt = \DateTimeImmutable::createFromFormat(self::DATE, $entry['at'], new \DateTimeZone('UTC')) ?: $this->createdAt;
                break;
            }
        }

        $this->record(SagaEvent::STEP_COMPLETED, $now, [
            'step'            => $this->step,
            'durationSeconds' => max(0, $now->getTimestamp() - $enteredAt->getTimestamp()),
        ]);
    }

    /** @param array<string, scalar|null> $attributes */
    private function record(string $name, \DateTimeImmutable $now, array $attributes): void
    {
        $this->events[] = new SagaEvent($name, $this->id, $this->osId, $this->correlationId, $attributes, $now);
    }

    private function requireRunning(string $action): void
    {
        if ($this->state !== self::RUNNING) {
            throw new DomainException("A saga está em {$this->state} e não pode {$action}.");
        }
    }

    /** @param array<string, mixed> $payload */
    private function send(string $type, array $payload): void
    {
        $this->commands[] = ['type' => $type, 'payload' => $payload];
    }

    private function log(\DateTimeImmutable $now, string $event, string $step, ?string $detail = null): void
    {
        $this->history[] = ['at' => $now->format(self::DATE), 'event' => $event, 'step' => $step, 'detail' => $detail];
    }
}
