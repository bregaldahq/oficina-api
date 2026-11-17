# language: pt
Funcionalidade: Saga da ordem de serviço
  Para que a OS atravesse os três serviços sem ficar pela metade
  Como orquestrador da saga, no serviço de OS
  Quero mandar cada comando na hora certa, reagir ao que a Execução e o Faturamento respondem
  e desfazer o que já foi feito quando um passo falha

  Contexto:
    Dado que a atendente abriu uma OS para a cliente "Maria Silva" com o relato "Barulho no freio"

  Cenário: Fluxo completo, da abertura ao reparo concluído
    Então a Execução recebe o pedido de diagnóstico com o relato "Barulho no freio"
    Quando a Execução avisa que o diagnóstico começou
    Então a OS fica "DIAGNOSIS"
    Quando a Execução conclui o diagnóstico com 2 itens
    Então o Faturamento recebe o pedido de orçamento com 2 itens e o e-mail da cliente
    Quando o Faturamento gera o orçamento "orc-1" de 558.00
    Então a OS fica "AWAITING_APPROVAL" com total de 558.00
    Quando o Faturamento avisa que o orçamento foi aprovado
    Então a OS fica "AWAITING_PAYMENT"
    E o Faturamento recebe o pedido de cobrança do orçamento "orc-1"
    Quando o Faturamento avisa o link de pagamento "https://pagar.local/orc-1"
    Então a OS mostra o link "https://pagar.local/orc-1"
    Quando o Faturamento confirma o pagamento
    Então a OS fica "EXECUTING"
    E a Execução recebe a ordem de reparo com 2 itens
    Quando a Execução avisa que o reparo terminou
    Então a OS fica "FINISHED"
    E a saga fica "COMPLETED"
    E os comandos publicados foram "execution.request-diagnosis, billing.generate-budget, billing.charge, execution.start-repair"

  Cenário: O reparo falha depois do pagamento e a saga estorna
    Dado que a OS já está em reparo com o orçamento "orc-1" pago
    Quando a Execução avisa que o reparo falhou por "peça com defeito"
    Então a OS fica "CANCELLED"
    E a saga fica "COMPENSATING" esperando "billing.refund-payment, billing.cancel-budget"
    Quando o Faturamento confirma o estorno
    E o Faturamento confirma o cancelamento do orçamento
    Então a saga fica "COMPENSATED"

  Cenário: A cliente recusa o orçamento
    Dado que a OS já tem o orçamento "orc-1" esperando aprovação
    Quando o Faturamento avisa que o orçamento foi recusado
    Então a OS fica "REJECTED"
    E a saga fica "COMPENSATING" esperando "execution.cancel"
    Quando a Execução confirma o cancelamento da tarefa
    Então a saga fica "COMPENSATED"

  Cenário: O prazo de pagamento vence
    Dado que a OS já tem o orçamento "orc-1" aprovado esperando pagamento
    Quando passam 601 segundos sem o pagamento
    Então a OS fica "CANCELLED"
    E a saga fica "COMPENSATING" esperando "billing.cancel-budget, execution.cancel"

  Cenário: Uma compensação nunca é confirmada
    Dado que a OS já tem o orçamento "orc-1" esperando aprovação
    Quando o Faturamento avisa que o orçamento foi recusado
    E passam 1000 segundos sem a confirmação, quatro vezes
    Então a saga fica "FAILED"
    E o alerta "saga.failed" foi registrado

  Cenário: Evento repetido ou fora de hora não muda nada
    Quando a Execução avisa que o reparo terminou
    E a Execução avisa que o diagnóstico começou
    E a mesma mensagem chega de novo
    Então a OS fica "DIAGNOSIS"
    E a saga continua no passo "DIAGNOSIS"
