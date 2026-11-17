<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Exception\ConcurrencyException;
use App\Domain\Exception\DuplicateMessageException;
use App\Domain\Messaging\Message;
use App\Domain\Saga\Saga;
use PHPUnit\Framework\TestCase;
use Tests\Support\SagaHarness;

/** O repositório da saga contra SQLite: a transação com a OS, a inbox, a trava e os prazos. */
class PdoSagaRepositoryTest extends TestCase
{
    private SagaHarness $h;

    protected function setUp(): void
    {
        $this->h = new SagaHarness();
    }

    public function testIdaEVoltaComOsDadosEAHistoria(): void
    {
        $osId = $this->h->until(Saga::STEP_APPROVAL);

        $saga = $this->h->saga($osId);
        $this->assertSame('bud-1', $saga->get('budget_id'));
        $this->assertSame([['kind' => 'service', 'id' => 'svc-1', 'quantity' => 1]], $saga->get('items'));
        $this->assertSame('Maria Silva', $saga->getData()['customer']['name']);
        $this->assertSame([], $saga->getPending());
        $this->assertGreaterThan(1, $saga->getVersion());
    }

    public function testMensagemRepetidaNaoGravaNadaNemAOs(): void
    {
        $osId  = $this->h->create()->getId();
        $saga  = $this->h->saga($osId);
        $order = $this->h->order($osId);
        $order->changeToDiagnosis();
        $this->h->sagas->save($saga, $order, [], 'm-1');

        $saga  = $this->h->saga($osId);
        $order = $this->h->order($osId);
        $saga->advance(Saga::STEP_BUDGET, null, new \DateTimeImmutable());
        try {
            $this->h->sagas->save($saga, $order, [], 'm-1');
            $this->fail('esperava DuplicateMessageException');
        } catch (DuplicateMessageException) {
        }
        $this->assertSame(Saga::STEP_DIAGNOSIS, $this->h->saga($osId)->getStep());
    }

    public function testDuasEscritasSobreAMesmaLeituraASegundaFalhaEDesfazAOs(): void
    {
        $osId = $this->h->create()->getId();
        $a    = $this->h->saga($osId);
        $b    = $this->h->saga($osId);
        $now  = new \DateTimeImmutable();

        $a->note('x', $now);
        $this->h->sagas->save($a, null);

        $order = $this->h->order($osId);
        $order->changeToDiagnosis();
        $b->note('y', $now);
        try {
            $this->h->sagas->save($b, $order);
            $this->fail('esperava ConcurrencyException');
        } catch (ConcurrencyException) {
        }
        $this->assertSame('RECEIVED', $this->h->order($osId)->getStatus(), 'a OS voltou junto');
    }

    public function testSegundaSagaParaAMesmaOsEConcorrencia(): void
    {
        $order = $this->h->create();
        $saga  = $this->h->orchestrator->start('outra', $this->h->order($order->getId()), 'c', '', new \DateTimeImmutable());

        $this->expectException(ConcurrencyException::class);
        $this->h->sagas->save($saga, null);
    }

    public function testPrazosVencidosDosMaisAtrasadosParaOsMaisNovos(): void
    {
        $first = $this->h->create()->getId();
        $this->h->clock->advance(10);
        $second = $this->h->create()->getId();

        $this->assertSame([], $this->h->sagas->findDue($this->h->clock->now()));

        $due = $this->h->sagas->findDue($this->h->clock->now()->modify('+1 hour'));
        $this->assertSame([$first, $second], array_map(static fn (Saga $s) => $s->getOsId(), $due));
        $this->assertCount(1, $this->h->sagas->findDue($this->h->clock->now()->modify('+1 hour'), 1));
    }

    public function testOutboxIlegivelNaoTravaAsOutras(): void
    {
        $this->h->pdo->exec("INSERT INTO outbox (id, type, body, occurred_at) VALUES ('ruim', 'x', 'não é json', '2026-01-01')");
        $osId = $this->h->create()->getId();

        $pending = $this->h->sagas->pending();

        $this->assertSame([], array_filter($pending, static fn (Message $m) => $m->osId === $osId), 'a da OS já saiu');
        $this->assertSame([], $pending, 'a ilegível fica no banco, fora da lista');
    }
}
