<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\DTO\ServiceOrder\CreateServiceOrderInputDTO;
use App\Application\Messaging\MessageFactory;
use App\Application\Messaging\OutboxRelay;
use App\Application\UseCase\HandleIncomingMessageUseCase;
use App\Application\UseCase\Saga\SagaCommit;
use App\Application\UseCase\Saga\SweepSagasUseCase;
use App\Application\UseCase\ServiceOrder\CancelServiceOrderUseCase;
use App\Application\UseCase\ServiceOrder\CreateServiceOrderUseCase;
use App\Application\UseCase\ServiceOrder\DeliverServiceOrderUseCase;
use App\Application\Worker\Worker;
use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Entity\Customer;
use App\Domain\Entity\Vehicle;
use App\Domain\Event\ServiceOrderStatusChangedEvent;
use App\Domain\Messaging\Message;
use App\Domain\Saga\Saga;
use App\Domain\Saga\SagaOrchestrator;
use App\Domain\Saga\SagaTimeouts;
use App\Domain\ValueObject\Document;
use App\Domain\ValueObject\LicensePlate;
use App\Infrastructure\Context\RequestContext;
use App\Infrastructure\Event\InMemoryEventDispatcher;
use App\Infrastructure\Event\Subscriber\StatusHistorySubscriber;
use App\Infrastructure\InMemory\InMemoryBus;
use App\Infrastructure\Repository\PdoCustomerRepository;
use App\Infrastructure\Repository\PdoSagaRepository;
use App\Infrastructure\Repository\PdoServiceOrderRepository;
use App\Infrastructure\Repository\PdoServiceOrderStatusHistoryRepository;
use App\Infrastructure\Repository\PdoVehicleRepository;
use PDO;

/**
 * O serviço de OS inteiro: repositórios de produção sobre SQLite em memória, o barramento em
 * memória no lugar de SNS e SQS, o despachante com o histórico de status e um relógio manual.
 * Os outros dois serviços são simulados por `reply()`, que entrega o evento que eles mandariam.
 */
final class SagaHarness
{
    public const CUSTOMER = 'cli-1';
    public const VEHICLE  = 'vei-1';

    public PDO $pdo;
    public PdoServiceOrderRepository $orders;
    public PdoSagaRepository $sagas;
    public InMemoryBus $bus;
    public ManualClock $clock;
    public RecordingObservers $observers;
    public SagaTimeouts $timeouts;
    public SagaOrchestrator $orchestrator;
    public SagaCommit $commit;
    public Worker $worker;
    public SweepSagasUseCase $sweep;

    /** @var list<string> "DE→PARA" de cada mudança de status despachada */
    public array $transitions = [];

    private SequenceUuid $uuid;
    private int $sequence = 0;

    public function __construct(?SagaTimeouts $timeouts = null)
    {
        $this->pdo          = OsDatabase::create();
        $this->uuid         = new SequenceUuid();
        $this->orders       = new PdoServiceOrderRepository();
        $this->sagas        = new PdoSagaRepository($this->pdo, $this->orders);
        $this->bus          = new InMemoryBus();
        $this->clock        = new ManualClock();
        $this->observers    = new RecordingObservers();
        $this->timeouts     = $timeouts ?? new SagaTimeouts(budget: 300, approval: 600, payment: 600, workAlert: 1800, compensationRetry: 60, maxCompensationAttempts: 3);
        $this->orchestrator = new SagaOrchestrator($this->timeouts);

        $events  = new InMemoryEventDispatcher();
        $history = new StatusHistorySubscriber(new PdoServiceOrderStatusHistoryRepository($this->pdo), $this->uuid, new RequestContext());
        $events->subscribe(ServiceOrderStatusChangedEvent::class, function (ServiceOrderStatusChangedEvent $e) use ($history): void {
            $this->transitions[] = $e->previousStatus . '→' . $e->newStatus;
            $history->onStatusChanged($e);
        });

        $relay        = new OutboxRelay($this->sagas, $this->bus, $this->observers, $this->observers);
        $this->commit = new SagaCommit($this->sagas, new MessageFactory($this->uuid, $this->clock), $events, $relay);
        $this->sweep  = new SweepSagasUseCase($this->sagas, $this->orders, $this->orchestrator, $this->commit, $this->clock, $this->observers);
        $handler      = new HandleIncomingMessageUseCase($this->sagas, $this->orders, $this->orchestrator, $this->commit, $this->clock);
        $sweep        = $this->sweep;
        $this->worker = new Worker($relay, $this->bus, $handler, $this->observers, $this->observers, static function () use ($sweep): void {
            $sweep->execute();
        });

        (new PdoCustomerRepository())->save(Customer::create(self::CUSTOMER, 'Maria Silva', new Document('529.982.247-25'), email: 'maria@example.com'));
        (new PdoVehicleRepository())->save(Vehicle::create(self::VEHICLE, self::CUSTOMER, new LicensePlate('ABC1D23'), 'Fiat', 'Uno', 2015));
    }

    public function create(string $description = 'Barulho no freio'): ServiceOrder
    {
        return (new CreateServiceOrderUseCase(
            new PdoCustomerRepository(),
            new PdoVehicleRepository(),
            $this->uuid,
            $this->orchestrator,
            $this->commit,
            $this->clock,
        ))->execute(new CreateServiceOrderInputDTO(self::CUSTOMER, self::VEHICLE, $description), 'corr-1');
    }

    public function cancel(string $osId, string $reason = 'cliente desistiu'): ServiceOrder
    {
        return (new CancelServiceOrderUseCase($this->sagas, $this->orders, $this->orchestrator, $this->commit, $this->clock))->execute($osId, $reason);
    }

    public function deliver(string $osId): ServiceOrder
    {
        return (new DeliverServiceOrderUseCase($this->orders, new InMemoryEventDispatcher()))->execute($osId);
    }

    /**
     * O outro serviço responde: o evento entra na fila do OS e o worker dá uma volta.
     *
     * @param array<string, mixed> $payload
     */
    public function reply(string $osId, string $type, array $payload = [], ?string $id = null): Message
    {
        $saga    = $this->saga($osId);
        $message = new Message(
            $id ?? 'evt-' . ++$this->sequence,
            $type,
            str_starts_with($type, 'budget.') || str_starts_with($type, 'payment.') ? 'faturamento' : 'execucao',
            $saga->getId(),
            $osId,
            $this->clock->now()->format('Y-m-d\TH:i:s.v\Z'),
            'corr-1',
            $payload,
        );
        $this->bus->deliver($message);
        $this->worker->tick(0);

        return $message;
    }

    /** Uma volta do worker sem mensagem: só a varredura dos prazos. */
    public function tick(): void
    {
        $this->worker->tick(0);
    }

    public function saga(string $osId): Saga
    {
        return $this->sagas->findByOsId($osId) ?? throw new \RuntimeException("sem saga para {$osId}");
    }

    public function order(string $osId): ServiceOrder
    {
        return $this->orders->findById($osId) ?? throw new \RuntimeException("sem OS {$osId}");
    }

    /** @return list<string> */
    public function commands(): array
    {
        return $this->bus->publishedTypes();
    }

    /** @return array<string, mixed> o payload do último comando desse tipo */
    public function lastCommand(string $type): array
    {
        $found = null;
        foreach ($this->bus->published() as $message) {
            if ($message->type === $type) {
                $found = $message;
            }
        }

        return $found?->payload ?? throw new \RuntimeException("comando {$type} não publicado");
    }

    /** Leva a OS até um passo da saga, respondendo como os outros serviços responderiam. */
    public function until(string $step): string
    {
        $osId = $this->create()->getId();
        $path = [
            Saga::STEP_BUDGET   => [['diagnosis.started', []], ['diagnosis.completed', ['task_id' => 't', 'items' => [['kind' => 'service', 'id' => 'svc-1', 'quantity' => 1]]]]],
            Saga::STEP_APPROVAL => [['budget.generated', ['budget_id' => 'bud-1', 'total_amount' => 558.0]]],
            Saga::STEP_PAYMENT  => [['budget.approved', ['budget_id' => 'bud-1']], ['payment.created', ['payment_id' => 'pay-1', 'payment_url' => 'https://pagar']]],
            Saga::STEP_REPAIR   => [['payment.confirmed', ['payment_id' => 'pay-1', 'amount' => 558.0]]],
        ];
        foreach ($path as $target => $events) {
            if ($this->saga($osId)->getStep() === $step) {
                break;
            }
            foreach ($events as [$type, $payload]) {
                $this->reply($osId, $type, $payload);
            }
        }

        return $osId;
    }

    /** @return list<string> as mudanças de status gravadas no histórico da OS */
    public function history(string $osId): array
    {
        $stmt = $this->pdo->prepare('SELECT from_status, to_status FROM service_order_status_history WHERE service_order_id = :id ORDER BY changed_at, rowid');
        $stmt->execute(['id' => $osId]);

        return array_map(static fn (array $r): string => ($r['from_status'] ?? '') . '→' . $r['to_status'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
