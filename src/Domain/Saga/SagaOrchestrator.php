<?php

declare(strict_types=1);

namespace App\Domain\Saga;

use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Exception\DomainException;

/**
 * O fluxo da saga da ordem de serviço, num lugar só (ADR-012; docs/fase-4/CONTRATOS.md §6, no
 * oficina-api). Serviço de domínio: recebe a saga e a OS, aplica o evento e deixa nos dois o
 * que mudou e o que publicar. Quem grava os dois juntos, com a outbox, é o repositório.
 *
 * Regras de entrega (§5.4):
 * - evento de outro passo não é aplicado (`handle` devolve false). O de um passo já passado é
 *   descartado; o de um passo posterior volta para a fila (`isAhead`, adendo F4-5);
 * - evento adiantado dentro do passo vale pelos dois (`diagnosis.completed` sem
 *   `diagnosis.started`, `payment.confirmed` sem `payment.created`);
 * - ao entrar em compensação, a OS já muda de status, na mesma transação.
 */
final class SagaOrchestrator
{
    public const OUTCOME_APPLIED = 'applied';
    public const OUTCOME_IGNORED = 'ignored';
    public const OUTCOME_STALLED = 'stalled';
    public const OUTCOME_RETRIED = 'retried';
    public const OUTCOME_FAILED  = 'failed';

    /** O passo a que cada evento dos outros serviços pertence, na ordem da saga. */
    private const EVENT_STEP = [
        'diagnosis.started'   => Saga::STEP_DIAGNOSIS,
        'diagnosis.completed' => Saga::STEP_DIAGNOSIS,
        'diagnosis.failed'    => Saga::STEP_DIAGNOSIS,
        'budget.generated'    => Saga::STEP_BUDGET,
        'budget.failed'       => Saga::STEP_BUDGET,
        'budget.approved'     => Saga::STEP_APPROVAL,
        'budget.rejected'     => Saga::STEP_APPROVAL,
        'payment.created'     => Saga::STEP_PAYMENT,
        'payment.confirmed'   => Saga::STEP_PAYMENT,
        'payment.failed'      => Saga::STEP_PAYMENT,
        'repair.started'      => Saga::STEP_REPAIR,
        'repair.completed'    => Saga::STEP_REPAIR,
        'repair.failed'       => Saga::STEP_REPAIR,
    ];

    private const STEP_ORDER = [Saga::STEP_DIAGNOSIS, Saga::STEP_BUDGET, Saga::STEP_APPROVAL, Saga::STEP_PAYMENT, Saga::STEP_REPAIR];

    public function __construct(private readonly SagaTimeouts $timeouts)
    {
    }

    /**
     * O evento é de um passo posterior ao atual: chegou antes do evento que leva a saga até lá
     * (o SQS standard não garante ordem; o anterior pode estar sendo reentregue). Não se descarta:
     * vale de novo quando a saga alcançar o passo.
     */
    public function isAhead(Saga $saga, string $event): bool
    {
        $step = self::EVENT_STEP[$event] ?? null;
        $now  = array_search($saga->getStep(), self::STEP_ORDER, true);

        return $saga->isRunning() && $step !== null && $now !== false
                                  && array_search($step, self::STEP_ORDER, true) > $now;
    }

    public function start(string $sagaId, ServiceOrder $order, string $correlationId, string $description, \DateTimeImmutable $now): Saga
    {
        $customer = $order->getCustomer();
        $vehicle  = $order->getVehicle();

        return Saga::start(
            $sagaId,
            $order->getId(),
            $correlationId,
            ['id' => $customer->getId(), 'name' => $customer->getName(), 'email' => (string)$customer->getEmail()],
            [
                'license_plate' => (string)$vehicle->getLicensePlate(),
                'brand'         => $vehicle->getBrand(),
                'model'         => $vehicle->getModel(),
            ],
            $description,
            $now,
            $this->at($now, $this->timeouts->workAlert),
        );
    }

    /**
     * Aplica um evento dos outros serviços. Devolve false quando o evento não serve ao passo
     * atual (fora de hora, repetido por outro caminho, de uma saga já encerrada).
     *
     * @param array<string, mixed> $payload
     */
    public function handle(Saga $saga, ServiceOrder $order, string $event, array $payload, \DateTimeImmutable $now): bool
    {
        if (!$saga->isRunning()) {
            // Um orçamento gerado depois de a saga desfazer o passo (o cancelamento chegou ao
            // Faturamento antes do pedido de orçamento) prenderia as peças numa OS cancelada:
            // cancela esse orçamento, sem mudar a saga.
            $budgetId = $payload['budget_id'] ?? null;
            if ($event === 'budget.generated' && is_string($budgetId) && $budgetId !== '') {
                $saga->sendLate('billing.cancel-budget', ['budget_id' => $budgetId, 'reason' => 'orçamento gerado depois do cancelamento da OS'], $now, 'budget.generated.late');

                return true;
            }

            return $saga->confirm($event, $now);
        }

        return match ($saga->getStep()) {
            Saga::STEP_DIAGNOSIS => $this->onDiagnosis($saga, $order, $event, $payload, $now),
            Saga::STEP_BUDGET    => $this->onBudget($saga, $order, $event, $payload, $now),
            Saga::STEP_APPROVAL  => $this->onApproval($saga, $order, $event, $now),
            Saga::STEP_PAYMENT   => $this->onPayment($saga, $order, $event, $payload, $now),
            Saga::STEP_REPAIR    => $this->onRepair($saga, $order, $event, $payload, $now),
            default              => false,
        };
    }

    /**
     * O prazo do passo venceu (§6.3). Orçamento, aprovação e pagamento compensam; diagnóstico e
     * reparo dependem do mecânico e só alertam; compensação sem confirmação é reenviada.
     */
    public function timeout(Saga $saga, ServiceOrder $order, \DateTimeImmutable $now): string
    {
        if ($saga->isCompensating()) {
            $saga->retryCompensation($now, $this->timeouts->compensationRetry, $this->timeouts->maxCompensationAttempts);

            return $saga->getState() === Saga::FAILED ? self::OUTCOME_FAILED : self::OUTCOME_RETRIED;
        }
        if (!$saga->isRunning()) {
            return self::OUTCOME_IGNORED;
        }

        $labels = [Saga::STEP_BUDGET => 'orçamento', Saga::STEP_APPROVAL => 'aprovação', Saga::STEP_PAYMENT => 'pagamento'];
        $label  = $labels[$saga->getStep()] ?? null;
        if ($label === null) {
            if ($saga->isAlerted()) {
                return self::OUTCOME_IGNORED;
            }
            $saga->markStalled($now);

            return self::OUTCOME_STALLED;
        }

        // No passo do orçamento o Faturamento pode ter gerado sem o evento ter chegado: o
        // cancelamento vai pela OS, e confirmar algo que não existe é sem efeito do lado de lá.
        $this->fail($saga, $order, "prazo de {$label} vencido", $now, cancelBudget: true, cancelTask: true);

        return self::OUTCOME_APPLIED;
    }

    /** O admin cancelou a OS: desfaz o que já existe, conforme o passo. */
    public function cancel(Saga $saga, ServiceOrder $order, string $reason, \DateTimeImmutable $now): void
    {
        if (!$saga->isRunning() || !$order->isCancellable()) {
            throw new DomainException('Esta ordem de serviço não pode mais ser cancelada.');
        }

        $step = $saga->getStep();
        $this->fail(
            $saga,
            $order,
            $reason,
            $now,
            refund: $step === Saga::STEP_REPAIR,
            cancelBudget: in_array($step, [Saga::STEP_BUDGET, Saga::STEP_APPROVAL, Saga::STEP_PAYMENT, Saga::STEP_REPAIR], true),
            cancelTask: true,
        );
    }

    /** @param array<string, mixed> $payload */
    private function onDiagnosis(Saga $saga, ServiceOrder $order, string $event, array $payload, \DateTimeImmutable $now): bool
    {
        switch ($event) {
            case 'diagnosis.started':
                $this->ensureDiagnosis($order);
                $saga->note($event, $now);

                return true;

            case 'diagnosis.completed':
                $this->ensureDiagnosis($order);
                $items = is_array($payload['items'] ?? null) ? array_values($payload['items']) : [];
                $saga->remember('items', $items);
                $saga->advance(Saga::STEP_BUDGET, $this->at($now, $this->timeouts->budget), $now, 'billing.generate-budget', [
                    'customer' => $saga->get('customer'),
                    'items'    => $items,
                ]);

                return true;

            case 'diagnosis.failed':
                // A Execução já encerrou a própria tarefa: não há o que desfazer lá.
                $this->fail($saga, $order, $this->reasonOf($payload, 'diagnóstico sem conserto'), $now);

                return true;
        }

        return false;
    }

    /** @param array<string, mixed> $payload */
    private function onBudget(Saga $saga, ServiceOrder $order, string $event, array $payload, \DateTimeImmutable $now): bool
    {
        switch ($event) {
            case 'budget.generated':
                $budgetId = (string)($payload['budget_id'] ?? '');
                if ($budgetId === '') {
                    // Fora do envelope do contrato: sem o id, a cobrança não teria o que cobrar.
                    return false;
                }
                $order->sendForApproval($budgetId, (float)($payload['total_amount'] ?? 0));
                $saga->remember('budget_id', $budgetId);
                $saga->advance(Saga::STEP_APPROVAL, $this->at($now, $this->timeouts->approval), $now);

                return true;

            case 'budget.failed':
                $this->fail($saga, $order, $this->reasonOf($payload, 'orçamento não gerado'), $now, cancelTask: true);

                return true;
        }

        return false;
    }

    private function onApproval(Saga $saga, ServiceOrder $order, string $event, \DateTimeImmutable $now): bool
    {
        switch ($event) {
            case 'budget.approved':
                $order->approve();
                $deadline = $this->at($now, $this->timeouts->payment);
                // A cobrança expira junto com o prazo do passo: pagamento depois disso não vale.
                $saga->advance(Saga::STEP_PAYMENT, $deadline, $now, 'billing.charge', [
                    'budget_id'  => $saga->get('budget_id'),
                    'expires_at' => $deadline->format('Y-m-d\TH:i:s.v\Z'),
                ]);

                return true;

            case 'budget.rejected':
                // O Faturamento já liberou o estoque ao registrar a recusa.
                $this->fail($saga, $order, 'orçamento recusado pelo cliente', $now, cancelTask: true, rejected: true);

                return true;
        }

        return false;
    }

    /** @param array<string, mixed> $payload */
    private function onPayment(Saga $saga, ServiceOrder $order, string $event, array $payload, \DateTimeImmutable $now): bool
    {
        switch ($event) {
            case 'payment.created':
                $url = (string)($payload['payment_url'] ?? '');
                if ($url === '') {
                    return false;
                }
                $order->recordPaymentLink($url);
                $saga->note($event, $now);

                return true;

            case 'payment.confirmed':
                $order->startExecution();
                $saga->remember('paid', true);
                $saga->advance(Saga::STEP_REPAIR, $this->at($now, $this->timeouts->workAlert), $now, 'execution.start-repair', [
                    'items' => $saga->get('items') ?? [],
                ]);

                return true;

            case 'payment.failed':
                $this->fail($saga, $order, $this->reasonOf($payload, 'pagamento recusado'), $now, cancelBudget: true, cancelTask: true);

                return true;
        }

        return false;
    }

    /** @param array<string, mixed> $payload */
    private function onRepair(Saga $saga, ServiceOrder $order, string $event, array $payload, \DateTimeImmutable $now): bool
    {
        switch ($event) {
            case 'repair.started':
                $saga->note($event, $now);

                return true;

            case 'repair.completed':
                $order->finish();
                $saga->complete($now);

                return true;

            case 'repair.failed':
                // Pago e com a tarefa encerrada pela própria Execução: estorna e cancela o orçamento.
                $this->fail($saga, $order, $this->reasonOf($payload, 'reparo não concluído'), $now, refund: true, cancelBudget: true);

                return true;
        }

        return false;
    }

    private function fail(
        Saga $saga,
        ServiceOrder $order,
        string $reason,
        \DateTimeImmutable $now,
        bool $refund = false,
        bool $cancelBudget = false,
        bool $cancelTask = false,
        bool $rejected = false,
    ): void {
        $budgetId      = $saga->get('budget_id');
        $compensations = [];
        if ($refund) {
            $compensations['billing.refund-payment'] = ['budget_id' => $budgetId, 'reason' => $reason];
        }
        if ($cancelBudget) {
            $compensations['billing.cancel-budget'] = ['budget_id' => $budgetId, 'reason' => $reason];
        }
        if ($cancelTask) {
            $compensations['execution.cancel'] = ['reason' => $reason];
        }

        // O cliente vê o resultado na hora; a saga segue confirmando as compensações (§6.2).
        $rejected ? $order->reject() : $order->cancel();
        $saga->compensate($reason, $compensations, $now, $this->at($now, $this->timeouts->compensationRetry));
    }

    private function ensureDiagnosis(ServiceOrder $order): void
    {
        if ($order->getStatus() === ServiceOrder::STATUS_RECEIVED) {
            $order->changeToDiagnosis();
        }
    }

    /** @param array<string, mixed> $payload */
    private function reasonOf(array $payload, string $default): string
    {
        $reason = $payload['reason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : $default;
    }

    private function at(\DateTimeImmutable $now, int $seconds): \DateTimeImmutable
    {
        return $now->modify("+{$seconds} seconds");
    }
}
