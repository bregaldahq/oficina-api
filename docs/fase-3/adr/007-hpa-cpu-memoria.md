# ADR-007 · Autoescalonamento por HPA em CPU e memória

## Status

Aceita (Fase 2) · Revisada na Fase 3 (limite superior de réplicas ampliado de 6 para 10).

## Contexto

O requisito de escalabilidade automática é explícito no Tech Challenge e precisa ser
**demonstrável no vídeo**: aplicar carga, ver as réplicas subirem, ver a latência se manter.

A carga da aplicação é dominada por PHP-FPM processando requisições HTTP — trabalho
majoritariamente ligado a CPU (parsing, serialização JSON, `hash_hmac` do JWT, mapeamento de
resultados PDO), com uso de memória que cresce com o número de workers FPM ativos. Não há filas,
não há consumo de eventos, não há métrica externa de backlog que sirva de sinal de demanda. O
sinal de saturação é, literalmente, CPU e memória do Pod.

Na Fase 2 o HPA operava com 2→6 réplicas em um cluster kind local. Na Fase 3 o cluster é EKS com
node group de 2 a 4 `t3.small`, o que muda a conta: o teto de réplicas precisa ser compatível com
o que o Cluster Autoscaler/node group consegue comportar, e precisa haver folga para a demo de
carga não bater no teto cedo demais.

## Decisão

Manter o `HorizontalPodAutoscaler` (`autoscaling/v2`) com **duas métricas de recurso**:

| Parâmetro | Valor |
|---|---|
| `minReplicas` | 2 |
| `maxReplicas` | 10 (era 6 na Fase 2) |
| CPU | `Utilization: 70%` |
| Memória | `Utilization: 80%` |

O HPA calcula a réplica desejada para cada métrica independentemente e adota **o maior valor** —
ou seja, basta um dos dois sinais saturar para escalar.

Racional dos limiares:

- **70% de CPU** deixa 30% de margem para absorver a rajada durante os ~15–30 s que o novo Pod
  leva para ficar `Ready` (pull da imagem, boot do FPM, `readinessProbe` em `/api/ready`).
  Limiares mais altos (85–90%) escalam tarde e a latência degrada antes do reforço chegar.
- **80% de memória**, mais folgado, porque memória em PHP-FPM cresce em degraus (workers) e não
  linearmente com o tráfego; um limiar baixo causaria escalonamento por ruído. Serve principalmente
  como rede de segurança contra `OOMKilled`, não como sinal primário.
- **`minReplicas: 2`** não é sobre carga: é disponibilidade. Garante que um `rollout`, um drain de
  node ou a perda de uma AZ nunca deixem zero Pod servindo.
- **`maxReplicas: 10`** é o teto que o node group (4 × `t3.small`) comporta com os `requests`
  definidos no overlay, mantendo espaço para os DaemonSets do New Relic e do
  `aws-load-balancer-controller`.

Pré-requisito operacional: o **metrics-server** é instalado pelo `oficina-infra-cluster` (WS-B4). Sem
ele o HPA fica em `<unknown>` e nunca escala — é a falha silenciosa mais comum desse recurso.

`requests` e `limits` de CPU e memória são **obrigatórios** no container: `Utilization` é
percentual sobre o `request`, e sem `request` a métrica não existe.

## Consequências

**Positivas**

- Escala com o sinal que realmente reflete a saturação desta aplicação, sem componente extra.
- `autoscaling/v2` é recurso nativo do Kubernetes: nenhum controller adicional para instalar,
  atualizar ou depurar.
- Duas métricas em conjunto cobrem os dois modos de degradação (CPU saturada e memória alta) e
  reduzem o risco de `OOMKilled` sob carga.
- Demonstrável em vídeo com uma ferramenta simples de carga e `kubectl get hpa -w`.
- Piso de 2 réplicas dá resiliência real, não só teórica.

**Negativas**

- CPU e memória são **indicadores tardios**: já houve degradação de latência quando a métrica
  sobe. Um sinal preditivo (fila, RPS) reagiria antes.
- Latência de reação em cadeia: janela de coleta do metrics-server (~15 s) + período de
  sincronização do HPA (15 s) + agendamento + pull + `readinessProbe`. Na prática, dezenas de
  segundos até o novo Pod servir. Rajadas curtas não são atendidas pelo autoescalonamento.
- Se todos os nodes estiverem cheios, Pods novos ficam `Pending`. O HPA não cria node; isso é
  responsabilidade do node group/autoscaler, o que introduz uma segunda latência (minutos).
- Métrica de recurso não distingue rotas: uma consulta pesada de relatório e um `/api/health`
  contam igual.
- Configuração incorreta de `requests` distorce todo o cálculo — um `request` subdimensionado faz
  o HPA escalar cedo demais e desperdiçar node.

## Alternativas consideradas

| Alternativa | Avaliação | Veredito |
|---|---|---|
| **KEDA** | Escalonamento por métrica externa (SQS, Prometheus, cron) e `scale-to-zero`. Nada disso se aplica: não há fila para medir, e escalar a zero quebraria a disponibilidade que o `minReplicas: 2` existe para garantir. Traria um operator, CRDs e um `ScaledObject` a mais para manter e explicar, sem resolver problema existente. | **Rejeitada** |
| **HPA só por CPU** | Mais simples e o sinal dominante. Rejeitada porque deixa o Pod exposto a `OOMKilled` em cenários de memória crescente sem CPU alta — e memória é barata de observar. |Rejeitada |
| **Custom metrics (RPS ou latência p95 via Prometheus Adapter)** | Tecnicamente o melhor sinal, porque é o SLI do usuário. Exige Prometheus, adapter e uma cadeia extra de disponibilidade dentro do caminho de escalonamento. Desproporcional para a fase. | Adiada |
| **VPA (Vertical Pod Autoscaler)** | Ajusta `requests` em vez de contar réplicas; exige reinício do Pod e conflita com o HPA nas mesmas métricas. Não atende o requisito, que é escalar horizontalmente. | Rejeitada |
| **Réplicas fixas dimensionadas para o pico** | Custo permanente de pico e nenhuma demonstração de elasticidade — contraria o requisito do desafio. | Rejeitada |
