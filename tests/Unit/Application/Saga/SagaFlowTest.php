<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Saga;

use App\Domain\Exception\DomainException;
use App\Domain\Saga\Saga;
use PHPUnit\Framework\TestCase;
use Tests\Support\SagaHarness;

/**
 * A saga da OS de ponta a ponta dentro do serviço (docs/fase-4/CONTRATOS.md §6, no oficina-api):
 * os comandos que ela publica, os eventos que ela aceita, o status da OS a cada passo, as
 * compensações e os prazos.
 */
class SagaFlowTest extends TestCase
{
    private SagaHarness $h;

    protected function setUp(): void
    {
        $this->h = new SagaHarness();
    }

    public function testAbrirAOsAbreASagaEPedeODiagnostico(): void
    {
        $order = $this->h->create('Barulho no freio');

        $saga = $this->h->saga($order->getId());
        $this->assertSame('RECEIVED', $this->h->order($order->getId())->getStatus());
        $this->assertSame([Saga::RUNNING, Saga::STEP_DIAGNOSIS], [$saga->getState(), $saga->getStep()]);
        $this->assertSame(['execution.request-diagnosis'], $this->h->commands(), 'a API publica na hora, sem esperar o worker');
        $this->assertSame([
            'customer_name' => 'Maria Silva',
            'vehicle'       => ['license_plate' => 'ABC1D23', 'brand' => 'Fiat', 'model' => 'Uno'],
            'description'   => 'Barulho no freio',
        ], $this->h->lastCommand('execution.request-diagnosis'));

        $message = $this->h->bus->published()[0];
        $this->assertSame([$saga->getId(), $order->getId(), 'os', 'corr-1'], [$message->sagaId, $message->osId, $message->producer, $message->correlationId]);
    }

    public function testCaminhoFelizDoDiagnosticoAoReparoConcluido(): void
    {
        $osId  = $this->h->create()->getId();
        $items = [['kind' => 'service', 'id' => 'svc-1', 'quantity' => 1], ['kind' => 'part', 'id' => 'p-1', 'quantity' => 2]];

        $this->h->reply($osId, 'diagnosis.started', ['task_id' => $osId]);
        $this->assertSame('DIAGNOSIS', $this->h->order($osId)->getStatus());

        $this->h->reply($osId, 'diagnosis.completed', ['task_id' => $osId, 'items' => $items]);
        $this->assertSame(Saga::STEP_BUDGET, $this->h->saga($osId)->getStep());
        $this->assertSame(
            ['customer' => ['id' => 'cli-1', 'name' => 'Maria Silva', 'email' => 'maria@example.com'], 'items' => $items],
            $this->h->lastCommand('billing.generate-budget'),
        );

        $this->h->reply($osId, 'budget.generated', ['budget_id' => 'bud-1', 'total_amount' => 558]);
        $order = $this->h->order($osId);
        $this->assertSame(['AWAITING_APPROVAL', 'bud-1', 558.0], [$order->getStatus(), $order->getBudgetId(), $order->getTotalAmount()]);

        $this->h->reply($osId, 'budget.approved', ['budget_id' => 'bud-1']);
        $this->assertSame('AWAITING_PAYMENT', $this->h->order($osId)->getStatus());
        $this->assertSame(
            ['budget_id' => 'bud-1', 'expires_at' => '2026-11-16T10:10:00.000Z'],
            $this->h->lastCommand('billing.charge'),
            'a cobrança expira junto com o prazo do passo de pagamento',
        );

        $this->h->reply($osId, 'payment.created', ['payment_id' => 'pay-1', 'payment_url' => 'https://pagar']);
        $this->assertSame('https://pagar', $this->h->order($osId)->getPaymentUrl());

        $this->h->reply($osId, 'payment.confirmed', ['payment_id' => 'pay-1', 'amount' => 558]);
        $this->assertSame(['EXECUTING', null], [$this->h->order($osId)->getStatus(), $this->h->order($osId)->getPaymentUrl()]);
        $this->assertSame(['items' => $items], $this->h->lastCommand('execution.start-repair'));

        $this->h->reply($osId, 'repair.started', ['task_id' => $osId]);
        $this->h->reply($osId, 'repair.completed', ['task_id' => $osId]);

        $saga = $this->h->saga($osId);
        $this->assertSame([Saga::COMPLETED, Saga::STEP_DONE, null], [$saga->getState(), $saga->getStep(), $saga->getDeadlineAt()]);
        $this->assertSame('FINISHED', $this->h->order($osId)->getStatus());
        $this->assertSame(
            ['execution.request-diagnosis', 'billing.generate-budget', 'billing.charge', 'execution.start-repair'],
            $this->h->commands(),
        );
        $this->assertSame([
            'RECEIVED→DIAGNOSIS', 'DIAGNOSIS→AWAITING_APPROVAL', 'AWAITING_APPROVAL→AWAITING_PAYMENT',
            'AWAITING_PAYMENT→EXECUTING', 'EXECUTING→FINISHED',
        ], $this->h->history($osId), 'o worker grava o histórico de status, como a API gravava');
        $this->assertContains('repair.started', array_column($saga->getHistory(), 'event'));

        $this->assertSame('DELIVERED', $this->h->deliver($osId)->getStatus(), 'a entrega continua manual');
    }

    public function testEventoAdiantadoDentroDoPassoValePelosDois(): void
    {
        $osId = $this->h->create()->getId();

        $this->h->reply($osId, 'diagnosis.completed', ['items' => []]);
        $this->assertSame(['RECEIVED→DIAGNOSIS'], $this->h->transitions);
        $this->assertSame(Saga::STEP_BUDGET, $this->h->saga($osId)->getStep());

        $osId = $this->h->until(Saga::STEP_PAYMENT);
        $this->h->reply($osId, 'payment.confirmed', ['amount' => 558]);
        $this->assertSame('EXECUTING', $this->h->order($osId)->getStatus());
    }

    public function testEventoForaDeHoraEIgnoradoEMensagemRepetidaEDuplicata(): void
    {
        // Evento de um passo que a saga já passou: descartado (o adiantado volta para a fila,
        // testEventoAdiantadoVoltaParaAFilaEValeQuandoASagaChegaNoPasso).
        $past = $this->h->until(Saga::STEP_BUDGET);
        $this->h->reply($past, 'diagnosis.started');
        $this->assertSame([Saga::RUNNING, Saga::STEP_BUDGET, 0], [$this->h->saga($past)->getState(), $this->h->saga($past)->getStep(), $this->h->bus->queued()]);
        $this->assertSame('ignored', end($this->h->observers->logs)['context']['outcome'] ?? null);

        $osId                 = $this->h->create()->getId();
        $this->h->transitions = [];
        $message              = $this->h->reply($osId, 'diagnosis.started', [], 'mesma');
        $this->h->bus->deliver($message);
        $this->h->tick();
        $this->assertSame('duplicate', end($this->h->observers->logs)['context']['outcome'] ?? null);
        $this->assertSame(['RECEIVED→DIAGNOSIS'], $this->h->transitions);
    }

    public function testEventoSemSagaOuDeOutraSagaEIgnorado(): void
    {
        $osId = $this->h->create()->getId();

        $this->h->bus->deliver(new \App\Domain\Messaging\Message('x', 'diagnosis.started', 'execucao', 'outra-saga', $osId, 't', 'c'));
        $this->h->bus->deliver(new \App\Domain\Messaging\Message('y', 'diagnosis.started', 'execucao', 's', 'os-desconhecida', 't', 'c'));
        $this->h->tick();

        $this->assertSame('RECEIVED', $this->h->order($osId)->getStatus());
        $this->assertSame(['ignored', 'ignored'], array_column(array_column($this->h->observers->logs, 'context'), 'outcome'));
    }

    public function testDiagnosticoSemConsertoCancelaSemCompensacao(): void
    {
        $osId = $this->h->create()->getId();
        $this->h->reply($osId, 'diagnosis.started');

        $this->h->reply($osId, 'diagnosis.failed', ['reason' => 'motor fundido']);

        $saga = $this->h->saga($osId);
        $this->assertSame([Saga::COMPENSATED, Saga::STEP_DIAGNOSIS, 'motor fundido'], [$saga->getState(), $saga->getFailedStep(), $saga->getReason()]);
        $this->assertSame('CANCELLED', $this->h->order($osId)->getStatus());
        $this->assertSame(['execution.request-diagnosis'], $this->h->commands(), 'a Execução já encerrou a tarefa');
    }

    public function testOrcamentoQueFalhaCancelaATarefaEEsperaAConfirmacao(): void
    {
        $osId = $this->h->until(Saga::STEP_BUDGET);

        $this->h->reply($osId, 'budget.failed', ['reason' => 'INSUFFICIENT_STOCK']);

        $this->assertSame('CANCELLED', $this->h->order($osId)->getStatus(), 'o cliente vê o resultado na hora');
        $this->assertSame(Saga::COMPENSATING, $this->h->saga($osId)->getState());
        $this->assertSame(['reason' => 'INSUFFICIENT_STOCK'], $this->h->lastCommand('execution.cancel'));

        $this->h->reply($osId, 'execution.cancelled');
        $this->assertSame(Saga::COMPENSATED, $this->h->saga($osId)->getState());
    }

    public function testClienteRecusaOOrcamento(): void
    {
        $osId = $this->h->until(Saga::STEP_APPROVAL);

        $this->h->reply($osId, 'budget.rejected', ['budget_id' => 'bud-1']);

        $this->assertSame('REJECTED', $this->h->order($osId)->getStatus());
        $this->assertSame(['execution.cancel'], array_keys($this->h->saga($osId)->getPending()), 'o Faturamento já liberou as peças');
    }

    public function testPagamentoRecusadoCancelaOrcamentoETarefa(): void
    {
        $osId = $this->h->until(Saga::STEP_PAYMENT);

        $this->h->reply($osId, 'payment.failed', ['payment_id' => 'pay-1', 'reason' => 'cc_rejected']);

        $this->assertSame(['billing.cancel-budget', 'execution.cancel'], array_keys($this->h->saga($osId)->getPending()));
        $this->assertSame(['budget_id' => 'bud-1', 'reason' => 'cc_rejected'], $this->h->lastCommand('billing.cancel-budget'));

        $this->h->reply($osId, 'budget.cancelled', ['budget_id' => 'bud-1']);
        $this->assertSame(Saga::COMPENSATING, $this->h->saga($osId)->getState(), 'falta a tarefa');
        $this->h->reply($osId, 'execution.cancelled');
        $this->assertSame(Saga::COMPENSATED, $this->h->saga($osId)->getState());
    }

    public function testReparoQueFalhaEstornaECancelaOOrcamento(): void
    {
        $osId = $this->h->until(Saga::STEP_REPAIR);

        $this->h->reply($osId, 'repair.failed', ['reason' => 'peça com defeito']);

        $this->assertSame('CANCELLED', $this->h->order($osId)->getStatus());
        $this->assertSame(['billing.refund-payment', 'billing.cancel-budget'], array_keys($this->h->saga($osId)->getPending()));
        $this->assertSame(['budget_id' => 'bud-1', 'reason' => 'peça com defeito'], $this->h->lastCommand('billing.refund-payment'));

        $this->h->reply($osId, 'payment.refunded', ['payment_id' => 'pay-1']);
        $this->h->reply($osId, 'payment.refunded', ['payment_id' => 'pay-1'], 'outro-id');
        $this->h->reply($osId, 'budget.cancelled', ['budget_id' => 'bud-1']);
        $this->assertSame(Saga::COMPENSATED, $this->h->saga($osId)->getState());
    }

    public function testPrazoDaAprovacaoVencidoCompensa(): void
    {
        $osId = $this->h->until(Saga::STEP_APPROVAL);
        $this->h->clock->advance(599);
        $this->h->tick();
        $this->assertSame(Saga::RUNNING, $this->h->saga($osId)->getState(), 'ainda dentro do prazo');

        $this->h->clock->advance(2);
        $this->h->tick();

        $saga = $this->h->saga($osId);
        $this->assertSame([Saga::COMPENSATING, 'prazo de aprovação vencido'], [$saga->getState(), $saga->getReason()]);
        $this->assertSame('CANCELLED', $this->h->order($osId)->getStatus());
        $this->assertSame(['billing.cancel-budget', 'execution.cancel'], array_keys($saga->getPending()));
        $this->assertContains('saga.timeout.applied', $this->h->observers->messages());

        // O cliente aprovou atrasado: a saga já está compensando e não volta atrás.
        $this->h->reply($osId, 'budget.approved', ['budget_id' => 'bud-1']);
        $this->assertSame('CANCELLED', $this->h->order($osId)->getStatus());
    }

    public function testPrazoDoOrcamentoVencidoCancelaPelaOs(): void
    {
        $osId = $this->h->until(Saga::STEP_BUDGET);
        $this->h->clock->advance(301);

        $this->h->tick();

        $this->assertSame(['budget_id' => null, 'reason' => 'prazo de orçamento vencido'], $this->h->lastCommand('billing.cancel-budget'));
    }

    public function testDiagnosticoParadoSoAlertaUmaVez(): void
    {
        $osId = $this->h->create()->getId();
        $this->h->clock->advance(1801);

        $this->h->tick();
        $this->h->clock->advance(5000);
        $this->h->tick();

        $this->assertSame(Saga::RUNNING, $this->h->saga($osId)->getState(), 'quem decide cancelar é o admin');
        $this->assertSame(1, count(array_filter($this->h->observers->messages(), static fn ($m) => $m === 'saga.stalled')));
        $this->assertTrue($this->h->saga($osId)->isAlerted());

        $this->h->reply($osId, 'diagnosis.completed', ['items' => []]);
        $this->assertFalse($this->h->saga($osId)->isAlerted(), 'o passo seguinte volta a ter prazo');
    }

    public function testCompensacaoSemConfirmacaoEReenviadaEDepoisFalha(): void
    {
        $osId = $this->h->until(Saga::STEP_BUDGET);
        $this->h->reply($osId, 'budget.failed', ['reason' => 'ITEM_NOT_FOUND']);
        $this->assertSame(1, count(array_filter($this->h->commands(), static fn ($t) => $t === 'execution.cancel')));

        $this->h->clock->advance(61);
        $this->h->tick();
        $this->assertSame(2, count(array_filter($this->h->commands(), static fn ($t) => $t === 'execution.cancel')));
        $this->assertSame(2, $this->h->saga($osId)->getAttempts());

        $this->h->clock->advance(121);
        $this->h->tick();
        $this->h->clock->advance(181);
        $this->h->tick();

        $saga = $this->h->saga($osId);
        $this->assertSame(Saga::FAILED, $saga->getState());
        $this->assertSame(['execution.cancel'], $saga->toArray()['pending_compensations']);
        $this->assertContains('saga.failed', $this->h->observers->messages());
    }

    public function testAdminCancelaDesfazendoOQueExisteEmCadaPasso(): void
    {
        $diagnosis = $this->h->create()->getId();
        $this->h->cancel($diagnosis);
        $this->assertSame(['execution.cancel'], array_keys($this->h->saga($diagnosis)->getPending()));

        $repair = $this->h->until(Saga::STEP_REPAIR);
        $order  = $this->h->cancel($repair, 'cliente desistiu');
        $this->assertSame('CANCELLED', $order->getStatus());
        $this->assertSame(['billing.refund-payment', 'billing.cancel-budget', 'execution.cancel'], array_keys($this->h->saga($repair)->getPending()));
    }

    public function testCancelamentoRecusadoDepoisDoReparoOuSemMotivo(): void
    {
        $osId = $this->h->until(Saga::STEP_REPAIR);
        $this->h->reply($osId, 'repair.completed');

        try {
            $this->h->cancel($osId);
            $this->fail('cancelou depois de terminado');
        } catch (DomainException $e) {
            $this->assertStringContainsString('não pode mais ser cancelada', $e->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->h->cancel($this->h->create()->getId(), ' ');
    }

    public function testVisaoDaSagaNaResposta(): void
    {
        $osId = $this->h->until(Saga::STEP_APPROVAL);

        $view = $this->h->saga($osId)->toArray();

        $this->assertSame([Saga::RUNNING, Saga::STEP_APPROVAL, '2026-11-16T10:10:00.000Z'], [$view['state'], $view['step'], $view['deadline_at']]);
        $this->assertSame(['saga.started', 'diagnosis.started', 'step.entered', 'step.entered'], array_column($view['history'], 'event'));
    }

    public function testOrcamentoGeradoDepoisDoCancelamentoECanceladoTambem(): void
    {
        $osId = $this->h->until(Saga::STEP_BUDGET);
        $this->h->cancel($osId);
        $this->h->reply($osId, 'execution.cancelled');
        $this->h->reply($osId, 'budget.cancelled', ['budget_id' => null]);
        $this->assertSame(Saga::COMPENSATED, $this->h->saga($osId)->getState());

        // O Faturamento recebeu o pedido de orçamento depois do cancelamento e gerou mesmo assim.
        $this->h->reply($osId, 'budget.generated', ['budget_id' => 'bud-tarde', 'total_amount' => 100]);

        $this->assertSame(['budget_id' => 'bud-tarde', 'reason' => 'orçamento gerado depois do cancelamento da OS'], $this->h->lastCommand('billing.cancel-budget'));
        $this->assertSame([Saga::COMPENSATED, 'CANCELLED'], [$this->h->saga($osId)->getState(), $this->h->order($osId)->getStatus()]);
        $this->assertContains('budget.generated.late', array_column($this->h->saga($osId)->toArray()['history'], 'event'));
    }

    public function testEventoSemOIdDoContratoEIgnorado(): void
    {
        $osId = $this->h->until(Saga::STEP_BUDGET);
        $this->h->reply($osId, 'budget.generated', ['total_amount' => 10]);
        $this->assertSame([Saga::STEP_BUDGET, 'DIAGNOSIS'], [$this->h->saga($osId)->getStep(), $this->h->order($osId)->getStatus()]);

        $payment = $this->h->until(Saga::STEP_PAYMENT);
        $this->h->reply($payment, 'payment.created', ['payment_id' => 'pay-2']);
        $this->assertSame('https://pagar', $this->h->order($payment)->getPaymentUrl(), 'sem url, o link anterior fica');
    }

    public function testConfirmacaoAtrasadaFechaASagaQueFalhou(): void
    {
        $osId = $this->h->until(Saga::STEP_BUDGET);
        $this->h->reply($osId, 'budget.failed', ['reason' => 'ITEM_NOT_FOUND']);
        foreach ([61, 121, 181] as $seconds) {
            $this->h->clock->advance($seconds);
            $this->h->tick();
        }
        $this->assertSame(Saga::FAILED, $this->h->saga($osId)->getState());

        $this->h->reply($osId, 'execution.cancelled');

        $this->assertSame([Saga::COMPENSATED, []], [$this->h->saga($osId)->getState(), $this->h->saga($osId)->getPending()]);
    }

    public function testSagaComDefeitoNaoParaAVarreduraDasOutras(): void
    {
        $broken = $this->h->until(Saga::STEP_APPROVAL);
        $fine   = $this->h->until(Saga::STEP_APPROVAL);
        // A OS foi mexida por fora: o cancelamento do prazo vai falhar nela.
        $this->h->pdo->prepare('UPDATE service_orders SET status = ? WHERE id = ?')->execute(['DELIVERED', $broken]);
        $this->h->clock->advance(601);

        $this->h->tick();

        $this->assertSame(Saga::RUNNING, $this->h->saga($broken)->getState(), 'volta na próxima volta');
        $this->assertSame(Saga::COMPENSATING, $this->h->saga($fine)->getState());
        $this->assertContains('saga.sweep_failed', $this->h->observers->messages());
    }

    public function testMotivoDeCancelamentoLongoDemaisERecusado(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('255');

        $this->h->cancel($this->h->create()->getId(), str_repeat('a', 256));
    }

    public function testMotivoLongoDeOutroServicoECortadoNaSaga(): void
    {
        $osId = $this->h->until(Saga::STEP_BUDGET);

        $this->h->reply($osId, 'budget.failed', ['reason' => str_repeat('é', 300)]);

        $this->assertSame(Saga::REASON_MAX, mb_strlen((string)$this->h->saga($osId)->getReason()));
    }

    public function testMarcosDaSagaParaOObservabilidade(): void
    {
        $osId = $this->h->until(Saga::STEP_APPROVAL);
        $this->h->clock->advance(30);
        $this->h->reply($osId, 'budget.approved', ['budget_id' => 'bud-1']);

        $mine = array_values(array_filter($this->h->sagaEvents, static fn ($e) => $e->orderId === $osId));
        $this->assertSame(
            ['SagaStarted', 'SagaStepCompleted', 'SagaStepCompleted', 'SagaStepCompleted'],
            array_map(static fn ($e) => $e->name, $mine),
        );
        $this->assertSame(['step' => 'APPROVAL', 'durationSeconds' => 30], $mine[3]->attributes, 'quanto o cliente levou para aprovar');
        $this->assertSame('corr-1', $mine[0]->correlationId);

        $failing = $this->h->until(Saga::STEP_BUDGET);
        $this->h->reply($failing, 'budget.failed', ['reason' => 'ITEM_NOT_FOUND']);
        foreach ([61, 121, 181] as $seconds) {
            $this->h->clock->advance($seconds);
            $this->h->tick();
        }
        $last = $this->lastSagaEvent($failing);
        $this->assertSame('SagaFailed', $last->name);
        $this->assertSame(['failedStep' => 'BUDGET', 'reason' => 'ITEM_NOT_FOUND', 'pending' => 'execution.cancel'], $last->attributes);

        $this->h->reply($failing, 'execution.cancelled');
        $last = $this->lastSagaEvent($failing);
        $this->assertSame(['SagaCompensated', ['failedStep' => 'BUDGET', 'reason' => 'ITEM_NOT_FOUND', 'afterFailure' => true]], [$last->name, $last->attributes]);
    }

    private function lastSagaEvent(string $osId): \App\Domain\Event\SagaEvent
    {
        $mine = array_values(array_filter($this->h->sagaEvents, static fn ($e) => $e->orderId === $osId));
        $this->assertNotSame([], $mine);

        return $mine[count($mine) - 1];
    }

    public function testEventoAdiantadoVoltaParaAFilaEValeQuandoASagaChegaNoPasso(): void
    {
        $osId = $this->h->until(Saga::STEP_BUDGET);

        // O SQS standard não garante ordem: a aprovação chega antes do orçamento gerado.
        $this->h->reply($osId, 'budget.approved', ['budget_id' => 'bud-1']);

        $this->assertSame([Saga::STEP_BUDGET, 1], [$this->h->saga($osId)->getStep(), $this->h->bus->queued()], 'não foi descartada');
        $retry = array_values(array_filter($this->h->observers->logs, static fn (array $l) => ($l['context']['outcome'] ?? null) === 'retry'));
        $this->assertSame(['info', 'budget.approved'], [$retry[0]['level'], $retry[0]['context']['message_type']], 'reentrega não é erro');

        $this->h->reply($osId, 'budget.generated', ['budget_id' => 'bud-1', 'total_amount' => 558]);
        $this->h->worker->tick(0);

        $this->assertSame([Saga::STEP_PAYMENT, 'AWAITING_PAYMENT', 0], [$this->h->saga($osId)->getStep(), $this->h->order($osId)->getStatus(), $this->h->bus->queued()]);
    }

    public function testEventoDePassoJaPassadoContinuaIgnorado(): void
    {
        $osId = $this->h->until(Saga::STEP_PAYMENT);

        $this->h->reply($osId, 'diagnosis.completed', ['items' => []]);

        $this->assertSame([Saga::STEP_PAYMENT, 0], [$this->h->saga($osId)->getStep(), $this->h->bus->queued()]);
    }
}
