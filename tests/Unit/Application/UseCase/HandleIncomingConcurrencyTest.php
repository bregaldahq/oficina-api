<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCase;

use App\Application\Messaging\MessageFactory;
use App\Application\Messaging\RetryLaterException;
use App\Application\Port\SagaRepositoryInterface;
use App\Application\UseCase\HandleIncomingMessageUseCase;
use App\Application\UseCase\Saga\SagaCommit;
use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Exception\ConcurrencyException;
use App\Domain\Messaging\Message;
use App\Domain\Saga\Saga;
use App\Infrastructure\Event\InMemoryEventDispatcher;
use PHPUnit\Framework\TestCase;
use Tests\Support\SagaHarness;
use Tests\Support\SequenceUuid;

/** Colisão de versão ao gravar a saga: nova tentativa na hora; se persistir, a mensagem volta à fila. */
final class HandleIncomingConcurrencyTest extends TestCase
{
    private SagaHarness $h;

    protected function setUp(): void
    {
        $this->h = new SagaHarness();
    }

    private function handler(int $collisions): HandleIncomingMessageUseCase
    {
        $real  = $this->h->sagas;
        $sagas = new class ($real, $collisions) implements SagaRepositoryInterface {
            public function __construct(private readonly SagaRepositoryInterface $real, private int $collisions)
            {
            }

            public function findByOsId(string $osId): ?Saga
            {
                return $this->real->findByOsId($osId);
            }

            public function findDue(\DateTimeImmutable $now, int $limit = 50): array
            {
                return $this->real->findDue($now, $limit);
            }

            public function save(Saga $saga, ?ServiceOrder $order, array $outbox = [], ?string $inboxMessageId = null): void
            {
                if ($this->collisions-- > 0) {
                    throw new ConcurrencyException('outra gravação ganhou');
                }
                $this->real->save($saga, $order, $outbox, $inboxMessageId);
            }
        };
        $commit = new SagaCommit($sagas, new MessageFactory(new SequenceUuid(), $this->h->clock), new InMemoryEventDispatcher());

        return new HandleIncomingMessageUseCase($sagas, $this->h->orders, $this->h->orchestrator, $commit, $this->h->clock);
    }

    private function started(string $osId): Message
    {
        return new Message('evt-x', 'diagnosis.started', 'execucao', $this->h->saga($osId)->getId(), $osId, '2026-11-16T10:00:00.000Z', 'corr-1', []);
    }

    public function testColisaoPassageiraETentadaDeNovoNaHora(): void
    {
        $osId = $this->h->create()->getId();

        $this->assertSame(HandleIncomingMessageUseCase::OUTCOME_OK, $this->handler(2)->execute($this->started($osId)));
        $this->assertSame('DIAGNOSIS', $this->h->order($osId)->getStatus());
    }

    public function testColisaoQuePersisteDevolveAMensagemAFila(): void
    {
        $osId = $this->h->create()->getId();

        $this->expectException(RetryLaterException::class);
        $this->handler(3)->execute($this->started($osId));
    }
}
