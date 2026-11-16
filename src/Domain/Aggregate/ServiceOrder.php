<?php

declare(strict_types=1);

namespace App\Domain\Aggregate;

use App\Domain\Entity\Customer;
use App\Domain\Entity\Vehicle;
use App\Domain\Event\DomainEventInterface;
use App\Domain\Event\ServiceOrderCreatedEvent;
use App\Domain\Event\ServiceOrderStatusChangedEvent;
use App\Domain\Exception\InvalidStatusTransitionException;

/**
 * A ordem de serviço e a máquina de estados dela (ADR-003): cada transição é um método nomeado,
 * nunca um setStatus. Na Fase 4 quem move a OS é a saga (docs/fase-4/CONTRATOS.md §6.4, no
 * oficina-api); itens e preços ficam no serviço de Faturamento, e aqui chegam só o id do
 * orçamento, o total e o link de pagamento.
 *
 *   RECEIVED → DIAGNOSIS → AWAITING_APPROVAL → AWAITING_PAYMENT → EXECUTING → FINISHED → DELIVERED
 *                                 └→ REJECTED
 *   RECEIVED, DIAGNOSIS, AWAITING_APPROVAL, AWAITING_PAYMENT, EXECUTING → CANCELLED
 */
class ServiceOrder
{
    public const STATUS_RECEIVED          = 'RECEIVED';
    public const STATUS_DIAGNOSIS         = 'DIAGNOSIS';
    public const STATUS_AWAITING_APPROVAL = 'AWAITING_APPROVAL';
    public const STATUS_AWAITING_PAYMENT  = 'AWAITING_PAYMENT';
    public const STATUS_EXECUTING         = 'EXECUTING';
    public const STATUS_REJECTED          = 'REJECTED';
    public const STATUS_FINISHED          = 'FINISHED';
    public const STATUS_DELIVERED         = 'DELIVERED';
    public const STATUS_CANCELLED         = 'CANCELLED';

    /** De onde ainda dá para cancelar: tudo antes de o reparo terminar. */
    private const CANCELLABLE = [
        self::STATUS_RECEIVED,
        self::STATUS_DIAGNOSIS,
        self::STATUS_AWAITING_APPROVAL,
        self::STATUS_AWAITING_PAYMENT,
        self::STATUS_EXECUTING,
    ];

    private float $totalAmount = 0.00;

    private ?string $budgetId = null;

    private ?string $paymentUrl = null;

    /** @var list<DomainEventInterface> */
    private array $domainEvents = [];

    private bool $isNew = false;

    private function __construct(
        private readonly string $id,
        private readonly Customer $customer,
        private readonly Vehicle $vehicle,
        private string $status,
        private readonly \DateTimeImmutable $createdAt,
    ) {
    }

    public static function create(string $id, Customer $customer, Vehicle $vehicle): self
    {
        $order        = new self($id, $customer, $vehicle, self::STATUS_RECEIVED, new \DateTimeImmutable());
        $order->isNew = true;
        $order->recordEvent(new ServiceOrderCreatedEvent($id, $customer->getId(), $vehicle->getId()));

        return $order;
    }

    /** Hidratação a partir do banco (ADR-005): não valida transição nem gera evento. */
    public static function reconstitute(
        string $id,
        Customer $customer,
        Vehicle $vehicle,
        string $status,
        float $totalAmount,
        \DateTimeImmutable $createdAt,
        ?string $budgetId = null,
        ?string $paymentUrl = null,
    ): self {
        $order              = new self($id, $customer, $vehicle, $status, $createdAt);
        $order->totalAmount = $totalAmount;
        $order->budgetId    = $budgetId;
        $order->paymentUrl  = $paymentUrl;

        return $order;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getVehicle(): Vehicle
    {
        return $this->vehicle;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getTotalAmount(): float
    {
        return $this->totalAmount;
    }

    public function getBudgetId(): ?string
    {
        return $this->budgetId;
    }

    public function getPaymentUrl(): ?string
    {
        return $this->paymentUrl;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isNew(): bool
    {
        return $this->isNew;
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, self::CANCELLABLE, true);
    }

    /** @return list<DomainEventInterface> */
    public function releaseEvents(): array
    {
        $events             = $this->domainEvents;
        $this->domainEvents = [];

        return $events;
    }

    /** O mecânico começou o diagnóstico (evento diagnosis.started da Execução). */
    public function changeToDiagnosis(): void
    {
        $this->transition([self::STATUS_RECEIVED], self::STATUS_DIAGNOSIS);
    }

    /** O Faturamento gerou o orçamento: o total vem dele, já com o preço do catálogo. */
    public function sendForApproval(string $budgetId, float $totalAmount): void
    {
        $this->requireStatus([self::STATUS_DIAGNOSIS], self::STATUS_AWAITING_APPROVAL);
        $this->budgetId    = $budgetId;
        $this->totalAmount = round($totalAmount, 2);
        $this->transition([self::STATUS_DIAGNOSIS], self::STATUS_AWAITING_APPROVAL);
    }

    /** O cliente aprovou: agora a OS espera o pagamento (Fase 4), não vai direto para a execução. */
    public function approve(): void
    {
        $this->transition([self::STATUS_AWAITING_APPROVAL], self::STATUS_AWAITING_PAYMENT);
    }

    public function reject(): void
    {
        $this->transition([self::STATUS_AWAITING_APPROVAL], self::STATUS_REJECTED);
    }

    /** O link de pagamento que o cliente abre. Só faz sentido esperando o pagamento. */
    public function recordPaymentLink(string $paymentUrl): void
    {
        $this->requireStatus([self::STATUS_AWAITING_PAYMENT], self::STATUS_AWAITING_PAYMENT);
        $this->paymentUrl = $paymentUrl;
    }

    /** O pagamento foi confirmado: o reparo pode começar. O link deixa de valer. */
    public function startExecution(): void
    {
        $this->transition([self::STATUS_AWAITING_PAYMENT], self::STATUS_EXECUTING);
        $this->paymentUrl = null;
    }

    public function finish(): void
    {
        $this->transition([self::STATUS_EXECUTING], self::STATUS_FINISHED);
    }

    public function deliver(): void
    {
        $this->transition([self::STATUS_FINISHED], self::STATUS_DELIVERED);
    }

    /** Compensação da saga ou cancelamento pelo admin. Depois de terminado o reparo, não cabe mais. */
    public function cancel(): void
    {
        $this->transition(self::CANCELLABLE, self::STATUS_CANCELLED);
        $this->paymentUrl = null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'customer'     => $this->customer->toArray(),
            'vehicle'      => $this->vehicle->toArray(),
            'status'       => $this->status,
            'total_amount' => $this->totalAmount,
            'budget_id'    => $this->budgetId,
            'payment_url'  => $this->paymentUrl,
            'created_at'   => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }

    /** @param list<string> $from */
    private function transition(array $from, string $to): void
    {
        $this->requireStatus($from, $to);
        $previous     = $this->status;
        $this->status = $to;
        $this->recordEvent(new ServiceOrderStatusChangedEvent($this->id, $previous, $to, $this->totalAmount));
    }

    /** @param list<string> $required */
    private function requireStatus(array $required, string $target): void
    {
        if (!in_array($this->status, $required, true)) {
            throw new InvalidStatusTransitionException(
                "Transição inválida de '{$this->status}' para '{$target}'. Status requerido: '" . implode("' ou '", $required) . "'."
            );
        }
    }

    private function recordEvent(DomainEventInterface $event): void
    {
        $this->domainEvents[] = $event;
    }
}
