# Arquitetura da Fase 4

Os três microsserviços, os bancos e os dois estilos de comunicação: REST do cliente para cada
serviço (linhas cheias) e mensagens entre os serviços (linhas tracejadas). Os nomes e as portas
são os do [`CONTRATOS.md`](../CONTRATOS.md).

```mermaid
flowchart TB
    cliente(["Cliente / atendente / mecânico"])
    mp(["Mercado Pago"])

    subgraph borda["oficina-gateway-auth"]
        apigw["API Gateway HTTP API"]
        authcpf["Lambda auth-cpf"]
        authorizer["Lambda jwt-authorizer"]
    end

    subgraph cluster["EKS (oficina-infra-cluster)"]
        nlb["NLB interno<br/>:80 · :81 · :82"]
        subgraph os["OS — oficina-api"]
            osapi["api"]
            osworker["worker<br/>orquestrador da saga"]
        end
        subgraph fat["Faturamento — oficina-faturamento"]
            fatapi["api"]
            fatworker["worker"]
        end
        subgraph exe["Execução — oficina-execucao"]
            exeapi["api"]
            exeworker["worker"]
        end
    end

    subgraph dados["Bancos (um por serviço)"]
        osdb[("MySQL<br/>RDS da fundação")]
        fatdb[("MySQL<br/>RDS do Faturamento")]
        exedb[("DynamoDB")]
    end

    subgraph msg["Mensageria"]
        sns{{"SNS oficina-env-mensagens"}}
        qos[/"SQS os + DLQ"/]
        qfat[/"SQS faturamento + DLQ"/]
        qexe[/"SQS execucao + DLQ"/]
    end

    cliente -->|"POST /auth/cpf"| apigw
    cliente -->|"/api/** + Bearer"| apigw
    apigw --> authcpf
    apigw -.-> authorizer
    authcpf -->|"GET /internal/customers/by-document"| nlb
    apigw -->|"VPC Link"| nlb
    nlb -->|":80 /api/**"| osapi
    nlb -->|":81 /api/billing/**"| fatapi
    nlb -->|":82 /api/execution/**"| exeapi
    mp -->|"webhook de pagamento"| apigw
    fatapi -->|"cobrança, consulta, estorno"| mp

    osapi --> osdb
    osworker --> osdb
    fatapi --> fatdb
    fatworker --> fatdb
    exeapi --> exedb
    exeworker --> exedb

    osworker -.->|"publica"| sns
    fatworker -.->|"publica"| sns
    exeworker -.->|"publica"| sns
    sns -.->|"filtro por type"| qos
    sns -.-> qfat
    sns -.-> qexe
    qos -.-> osworker
    qfat -.-> fatworker
    qexe -.-> exeworker
```

O que o diagrama garante:

- cada serviço fala só com o próprio banco;
- os serviços não se chamam por HTTP: a única chamada interna síncrona é a da `auth-cpf` ao OS;
- todo serviço publica no mesmo tópico e lê só da própria fila;
- a API de cada serviço grava a mensagem na outbox do próprio banco; quem publica é o worker.
