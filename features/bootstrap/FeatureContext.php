<?php

declare(strict_types=1);

use App\Domain\Messaging\Message;
use App\Domain\Saga\Saga;
use Behat\Behat\Context\Context;
use PHPUnit\Framework\Assert;
use Tests\Support\SagaHarness;

/**
 * O serviço de OS de verdade (repositórios PDO sobre SQLite, orquestrador, worker, outbox), com
 * SNS e SQS trocados pelo barramento em memória. A Execução e o Faturamento são simulados pelas
 * respostas que eles mandariam, no formato do contrato.
 */
final class FeatureContext implements Context
{
    private SagaHarness $service;

    private string $osId = '';

    private ?Message $last = null;

    /** @var list<array{kind: string, id: string, quantity: int}> */
    private array $items = [
        ['kind' => 'service', 'id' => 'svc-oleo', 'quantity' => 1],
        ['kind' => 'part', 'id' => 'part-pastilha', 'quantity' => 2],
    ];

    public function __construct()
    {
        $this->service = new SagaHarness();
    }

    /** @param array<string, mixed> $payload */
    private function reply(string $type, array $payload = []): void
    {
        $this->last = $this->service->reply($this->osId, $type, $payload);
    }

    /**
     * @Given que a atendente abriu uma OS para a cliente :name com o relato :description
     */
    public function abriuOs(string $name, string $description): void
    {
        $this->osId = $this->service->create($description)->getId();
        Assert::assertSame($name, $this->service->order($this->osId)->getCustomer()->getName());
    }

    /**
     * @Then a Execução recebe o pedido de diagnóstico com o relato :description
     */
    public function pedidoDeDiagnostico(string $description): void
    {
        Assert::assertSame($description, $this->service->lastCommand('execution.request-diagnosis')['description']);
    }

    /**
     * @When a Execução avisa que o diagnóstico começou
     */
    public function diagnosticoComecou(): void
    {
        $this->reply('diagnosis.started', ['task_id' => $this->osId]);
    }

    /**
     * @When a Execução conclui o diagnóstico com :count itens
     */
    public function diagnosticoConcluido(int $count): void
    {
        Assert::assertCount($count, $this->items);
        $this->reply('diagnosis.completed', ['task_id' => $this->osId, 'items' => $this->items]);
    }

    /**
     * @Then o Faturamento recebe o pedido de orçamento com :count itens e o e-mail da cliente
     */
    public function pedidoDeOrcamento(int $count): void
    {
        $payload = $this->service->lastCommand('billing.generate-budget');
        Assert::assertCount($count, $payload['items']);
        Assert::assertSame('maria@example.com', $payload['customer']['email']);
    }

    /**
     * @When o Faturamento gera o orçamento :budget de :total
     */
    public function orcamentoGerado(string $budget, float $total): void
    {
        $this->reply('budget.generated', ['budget_id' => $budget, 'total_amount' => $total]);
    }

    /**
     * @When o Faturamento avisa que o orçamento foi aprovado
     */
    public function aprovado(): void
    {
        $this->reply('budget.approved', ['budget_id' => $this->service->order($this->osId)->getBudgetId()]);
    }

    /**
     * @When o Faturamento avisa que o orçamento foi recusado
     */
    public function recusado(): void
    {
        $this->reply('budget.rejected', ['budget_id' => $this->service->order($this->osId)->getBudgetId()]);
    }

    /**
     * @Then o Faturamento recebe o pedido de cobrança do orçamento :budget
     */
    public function pedidoDeCobranca(string $budget): void
    {
        Assert::assertSame($budget, $this->service->lastCommand('billing.charge')['budget_id']);
    }

    /**
     * @When o Faturamento avisa o link de pagamento :url
     */
    public function link(string $url): void
    {
        $this->reply('payment.created', ['payment_id' => 'pag-1', 'payment_url' => $url]);
    }

    /**
     * @Then a OS mostra o link :url
     */
    public function mostraLink(string $url): void
    {
        Assert::assertSame($url, $this->service->order($this->osId)->getPaymentUrl());
    }

    /**
     * @When o Faturamento confirma o pagamento
     */
    public function pagamentoConfirmado(): void
    {
        $this->reply('payment.confirmed', ['payment_id' => 'pag-1', 'amount' => 558]);
    }

    /**
     * @Then a Execução recebe a ordem de reparo com :count itens
     */
    public function ordemDeReparo(int $count): void
    {
        Assert::assertCount($count, $this->service->lastCommand('execution.start-repair')['items']);
    }

    /**
     * @When a Execução avisa que o reparo terminou
     */
    public function reparoTerminou(): void
    {
        $this->reply('repair.completed', ['task_id' => $this->osId]);
    }

    /**
     * @When a Execução avisa que o reparo falhou por :reason
     */
    public function reparoFalhou(string $reason): void
    {
        $this->reply('repair.failed', ['task_id' => $this->osId, 'reason' => $reason]);
    }

    /**
     * @When o Faturamento confirma o estorno
     */
    public function estornoConfirmado(): void
    {
        $this->reply('payment.refunded', ['payment_id' => 'pag-1']);
    }

    /**
     * @When o Faturamento confirma o cancelamento do orçamento
     */
    public function cancelamentoDoOrcamento(): void
    {
        $this->reply('budget.cancelled', ['budget_id' => 'orc-1']);
    }

    /**
     * @When a Execução confirma o cancelamento da tarefa
     */
    public function cancelamentoDaTarefa(): void
    {
        $this->reply('execution.cancelled', ['task_id' => $this->osId]);
    }

    /**
     * @Given que a OS já tem o orçamento :budget esperando aprovação
     */
    public function esperandoAprovacao(string $budget): void
    {
        $this->diagnosticoComecou();
        $this->diagnosticoConcluido(2);
        $this->orcamentoGerado($budget, 558.0);
    }

    /**
     * @Given que a OS já tem o orçamento :budget aprovado esperando pagamento
     */
    public function esperandoPagamento(string $budget): void
    {
        $this->esperandoAprovacao($budget);
        $this->aprovado();
    }

    /**
     * @Given que a OS já está em reparo com o orçamento :budget pago
     */
    public function emReparo(string $budget): void
    {
        $this->esperandoPagamento($budget);
        $this->pagamentoConfirmado();
    }

    /**
     * @When passam :seconds segundos sem o pagamento
     */
    public function passaOTempo(int $seconds): void
    {
        $this->service->clock->advance($seconds);
        $this->service->tick();
    }

    /**
     * @When passam :seconds segundos sem a confirmação, quatro vezes
     */
    public function passaOTempoVariasVezes(int $seconds): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->passaOTempo($seconds);
        }
    }

    /**
     * @When a mesma mensagem chega de novo
     */
    public function mensagemRepetida(): void
    {
        Assert::assertNotNull($this->last);
        $this->service->bus->deliver($this->last);
        $this->service->tick();
    }

    /**
     * @Then a OS fica :status
     */
    public function osFica(string $status): void
    {
        Assert::assertSame($status, $this->service->order($this->osId)->getStatus());
    }

    /**
     * @Then a OS fica :status com total de :total
     */
    public function osFicaComTotal(string $status, float $total): void
    {
        $this->osFica($status);
        Assert::assertEqualsWithDelta($total, $this->service->order($this->osId)->getTotalAmount(), 0.001);
    }

    /**
     * @Then a saga fica :state
     */
    public function sagaFica(string $state): void
    {
        Assert::assertSame($state, $this->service->saga($this->osId)->getState());
    }

    /**
     * @Then a saga fica :state esperando :compensations
     */
    public function sagaEsperando(string $state, string $compensations): void
    {
        $this->sagaFica($state);
        Assert::assertSame(array_map('trim', explode(',', $compensations)), array_keys($this->service->saga($this->osId)->getPending()));
    }

    /**
     * @Then a saga continua no passo :step
     */
    public function sagaNoPasso(string $step): void
    {
        $saga = $this->service->saga($this->osId);
        Assert::assertSame([Saga::RUNNING, $step], [$saga->getState(), $saga->getStep()]);
    }

    /**
     * @Then os comandos publicados foram :types
     */
    public function comandos(string $types): void
    {
        Assert::assertSame(array_map('trim', explode(',', $types)), $this->service->commands());
    }

    /**
     * @Then o alerta :message foi registrado
     */
    public function alerta(string $message): void
    {
        Assert::assertContains($message, $this->service->observers->messages());
    }
}
