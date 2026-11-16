<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Application\Messaging\MessageFactory;
use App\Application\Messaging\OutboxRelay;
use App\Application\Port\LoggerInterface;
use App\Application\UseCase\HandleIncomingMessageUseCase;
use App\Application\UseCase\Saga\SagaCommit;
use App\Application\UseCase\Saga\SweepSagasUseCase;
use App\Application\UseCase\ServiceOrder\CancelServiceOrderUseCase;
use App\Application\UseCase\ServiceOrder\CreateServiceOrderUseCase;
use App\Application\UseCase\ServiceOrder\DeliverServiceOrderUseCase;
use App\Application\Worker\Worker;
use App\Domain\Event\EventDispatcherInterface;
use App\Domain\Event\SagaEvent;
use App\Domain\Event\ServiceOrderCreatedEvent;
use App\Domain\Event\ServiceOrderStatusChangedEvent;
use App\Domain\Saga\SagaOrchestrator;
use App\Domain\Saga\SagaTimeouts;
use App\Infrastructure\Aws\AwsClientFactory;
use App\Infrastructure\Aws\SnsMessagePublisher;
use App\Infrastructure\Aws\SqsMessageQueue;
use App\Infrastructure\Context\RequestContext;
use App\Infrastructure\Database\PdoConnection;
use App\Infrastructure\Event\InMemoryEventDispatcher;
use App\Infrastructure\Event\Subscriber\NewRelicSubscriber;
use App\Infrastructure\Event\Subscriber\StatusHistorySubscriber;
use App\Infrastructure\Logging\JsonLogger;
use App\Infrastructure\Notification\SmtpMailer;
use App\Infrastructure\Notification\StatusChangeEmailNotifier;
use App\Infrastructure\Repository\PdoCustomerRepository;
use App\Infrastructure\Repository\PdoSagaRepository;
use App\Infrastructure\Repository\PdoServiceOrderRepository;
use App\Infrastructure\Repository\PdoServiceOrderStatusHistoryRepository;
use App\Infrastructure\Repository\PdoVehicleRepository;
use App\Infrastructure\Time\SystemClock;
use App\Infrastructure\Tracing\NewRelicTracer;

/**
 * Monta a saga e o que ela usa a partir do ambiente. A API (public/index.php) e o worker
 * (bin/worker.php) usam a mesma montagem: a mudança de status feita pelo worker passa pelos
 * mesmos assinantes (histórico, New Relic, e-mail) que a feita pela API.
 */
final class ServiceFactory
{
    private ?PdoServiceOrderRepository $orders    = null;
    private ?PdoSagaRepository $sagas             = null;
    private ?EventDispatcherInterface $dispatcher = null;

    /** @param array<string, mixed> $env */
    public function __construct(
        private readonly array $env,
        private readonly JsonLogger $logger,
        private readonly RequestContext $context,
    ) {
    }

    public function orders(): PdoServiceOrderRepository
    {
        return $this->orders ??= new PdoServiceOrderRepository();
    }

    public function sagas(): PdoSagaRepository
    {
        return $this->sagas ??= new PdoSagaRepository(PdoConnection::getInstance(), $this->orders());
    }

    public function orchestrator(): SagaOrchestrator
    {
        return new SagaOrchestrator(SagaTimeouts::fromEnv($this->env));
    }

    /** O despachante com os assinantes da Fase 3: histórico de status, New Relic e e-mail. */
    public function events(): EventDispatcherInterface
    {
        if ($this->dispatcher !== null) {
            return $this->dispatcher;
        }

        $dispatcher = new InMemoryEventDispatcher();
        $history    = new PdoServiceOrderStatusHistoryRepository();
        $uuid       = new UuidGenerator();
        $appEnv     = $this->string('APP_ENV') ?: 'local';

        $statusHistory = new StatusHistorySubscriber($history, $uuid, $this->context, $this->logger);
        $newRelic      = new NewRelicSubscriber($this->context, $history, $appEnv);
        $dispatcher->subscribe(ServiceOrderCreatedEvent::class, static fn (ServiceOrderCreatedEvent $e) => $statusHistory->onCreated($e));
        $dispatcher->subscribe(ServiceOrderCreatedEvent::class, static fn (ServiceOrderCreatedEvent $e) => $newRelic->onCreated($e));
        $dispatcher->subscribe(ServiceOrderStatusChangedEvent::class, static fn (ServiceOrderStatusChangedEvent $e) => $statusHistory->onStatusChanged($e));
        $dispatcher->subscribe(ServiceOrderStatusChangedEvent::class, static fn (ServiceOrderStatusChangedEvent $e) => $newRelic->onStatusChanged($e));
        $dispatcher->subscribe(SagaEvent::class, static fn (SagaEvent $e) => $newRelic->onSaga($e));

        // E-mail só com SMTP configurado; sem ele, silêncio.
        if ($this->string('SMTP_HOST') !== '' && $this->string('MAIL_TO') !== '') {
            $notifier = new StatusChangeEmailNotifier(
                new SmtpMailer(
                    host: $this->string('SMTP_HOST'),
                    port: (int)($this->env['SMTP_PORT'] ?? 587),
                    username: $this->string('SMTP_USERNAME'),
                    password: $this->string('SMTP_PASSWORD'),
                    from: $this->string('MAIL_FROM') ?: 'no-reply@oficina.local',
                ),
                $this->string('MAIL_TO'),
            );
            $dispatcher->subscribe(ServiceOrderStatusChangedEvent::class, static fn (ServiceOrderStatusChangedEvent $e) => $notifier->handle($e));
        }

        return $this->dispatcher = $dispatcher;
    }

    public function relay(LoggerInterface $logger): OutboxRelay
    {
        return new OutboxRelay(
            $this->sagas(),
            new SnsMessagePublisher($this->aws()->sns(), $this->string('MESSAGING_TOPIC_ARN')),
            $this->tracer(),
            $logger,
        );
    }

    public function commit(LoggerInterface $logger): SagaCommit
    {
        return new SagaCommit($this->sagas(), new MessageFactory(new UuidGenerator(), new SystemClock()), $this->events(), $this->relay($logger));
    }

    public function createServiceOrder(LoggerInterface $logger): CreateServiceOrderUseCase
    {
        return new CreateServiceOrderUseCase(
            new PdoCustomerRepository(),
            new PdoVehicleRepository(),
            new UuidGenerator(),
            $this->orchestrator(),
            $this->commit($logger),
            new SystemClock(),
        );
    }

    public function cancelServiceOrder(LoggerInterface $logger): CancelServiceOrderUseCase
    {
        return new CancelServiceOrderUseCase($this->sagas(), $this->orders(), $this->orchestrator(), $this->commit($logger), new SystemClock());
    }

    public function deliverServiceOrder(): DeliverServiceOrderUseCase
    {
        return new DeliverServiceOrderUseCase($this->orders(), $this->events());
    }

    public function worker(LoggerInterface $logger): Worker
    {
        $commit = $this->commit($logger);
        $sweep  = new SweepSagasUseCase($this->sagas(), $this->orders(), $this->orchestrator(), $commit, new SystemClock(), $logger);

        return new Worker(
            $this->relay($logger),
            new SqsMessageQueue($this->aws()->sqs(), $this->string('MESSAGING_QUEUE_URL')),
            new HandleIncomingMessageUseCase($this->sagas(), $this->orders(), $this->orchestrator(), $commit, new SystemClock()),
            $this->tracer(),
            $logger,
            static function () use ($sweep): void {
                $sweep->execute();
            },
        );
    }

    private function tracer(): NewRelicTracer
    {
        return new NewRelicTracer($this->string('NEW_RELIC_APP_NAME') ?: 'oficina-api');
    }

    private function aws(): AwsClientFactory
    {
        return new AwsClientFactory($this->env);
    }

    private function string(string $key): string
    {
        $value = $this->env[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
