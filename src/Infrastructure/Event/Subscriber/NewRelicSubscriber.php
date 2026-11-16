<?php

declare(strict_types=1);

namespace App\Infrastructure\Event\Subscriber;

use App\Domain\Event\SagaEvent;
use App\Domain\Event\ServiceOrderCreatedEvent;
use App\Domain\Event\ServiceOrderStatusChangedEvent;
use App\Domain\Repository\ServiceOrderStatusHistoryRepositoryInterface;
use App\Infrastructure\Context\RequestContext;

/**
 * Emits the business custom events of CONTRATOS.md §7 to New Relic.
 *
 * The recorder is injected; the default one calls newrelic_record_custom_event() only when the
 * PHP agent extension is loaded and is a silent no-op otherwise, so tests and the local
 * environment run untouched.
 */
final class NewRelicSubscriber
{
    /** @var \Closure(string, array<string, mixed>): void */
    private readonly \Closure $recorder;

    /** @param (\Closure(string, array<string, mixed>): void)|null $recorder */
    public function __construct(
        private readonly RequestContext $context,
        private readonly ServiceOrderStatusHistoryRepositoryInterface $history,
        private readonly string $env = 'local',
        ?\Closure $recorder = null,
    ) {
        $this->recorder = $recorder ?? self::defaultRecorder();
    }

    /** @return \Closure(string, array<string, mixed>): void */
    public static function defaultRecorder(): \Closure
    {
        return static function (string $name, array $attributes): void {
            if (!function_exists('newrelic_record_custom_event')) {
                return;
            }

            newrelic_record_custom_event($name, $attributes);
        };
    }

    public function onCreated(ServiceOrderCreatedEvent $event): void
    {
        ($this->recorder)('ServiceOrderCreated', [
            'orderId'       => $event->orderId,
            'customerId'    => $event->customerId,
            'vehicleId'     => $event->vehicleId,
            'correlationId' => $this->context->getCorrelationId(),
            'env'           => $this->env,
        ]);
    }

    public function onStatusChanged(ServiceOrderStatusChangedEvent $event): void
    {
        ($this->recorder)('ServiceOrderStatusChanged', [
            'orderId'         => $event->orderId,
            'fromStatus'      => $event->previousStatus,
            'toStatus'        => $event->newStatus,
            'durationSeconds' => $this->durationSeconds($event),
            'orderAgeSeconds' => $this->orderAgeSeconds($event),
            'totalAmount'     => $event->totalAmount,
            'correlationId'   => $this->context->getCorrelationId(),
            'env'             => $this->env,
        ]);
    }

    /** Os marcos da saga (docs/fase-4/CONTRATOS.md §8): SagaStarted, SagaStepCompleted, SagaCompensated, SagaFailed. */
    public function onSaga(SagaEvent $event): void
    {
        ($this->recorder)($event->name, [
            'sagaId'        => $event->sagaId,
            'orderId'       => $event->orderId,
            'correlationId' => $event->correlationId,
            ...$event->attributes,
            'env' => $this->env,
        ]);
    }

    /** Seconds elapsed since the previous transition of this order; null when unknown. */
    private function durationSeconds(ServiceOrderStatusChangedEvent $event): ?int
    {
        try {
            $previous = $this->history->findLastChangedAtBefore($event->orderId, $event->occurredAt());
        } catch (\Throwable) {
            return null;
        }

        if ($previous === null) {
            return null;
        }

        return max(0, $event->occurredAt()->getTimestamp() - $previous->getTimestamp());
    }

    /**
     * Seconds since the order was opened; null when unknown.
     *
     * On the transition into DELIVERED this is the order's lead time. Summing the durations of
     * the individual transitions would not be: an order whose early history fell outside the
     * query window would be counted with only part of its life.
     */
    private function orderAgeSeconds(ServiceOrderStatusChangedEvent $event): ?int
    {
        try {
            $openedAt = $this->history->findFirstChangedAt($event->orderId);
        } catch (\Throwable) {
            return null;
        }

        if ($openedAt === null) {
            return null;
        }

        return max(0, $event->occurredAt()->getTimestamp() - $openedAt->getTimestamp());
    }
}
