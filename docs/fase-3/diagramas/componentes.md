# Diagrama de componentes — visão de nuvem (Fase 3)

Visão dos componentes provisionados na AWS (`us-east-1`), das fronteiras de rede e de quem é dono
de cada recurso. A propriedade por repositório segue a seção 1 dos Contratos e a ADR-009.

## Visão geral

```mermaid
flowchart TB
    subgraph internet["Internet"]
        cliente(["Cliente da oficina<br/>(CPF)"])
        admin(["Admin da oficina<br/>(usuário e senha)"])
        webhook(["Sistema externo<br/>(aprovação de orçamento)"])
        nr(["New Relic<br/>APM · Logs · Eventos · Alertas"])
    end

    subgraph aws["AWS · us-east-1"]
        apigw["API Gateway HTTP API<br/>oficina-env-api"]
        authz["Lambda jwt-authorizer<br/>REQUEST · cache 300s"]
        authcpf["Lambda auth-cpf<br/>POST /auth/cpf"]
        sm[("Secrets Manager<br/>oficina/env/db<br/>oficina/env/auth")]
        ssm[("SSM Parameter Store<br/>/oficina/env/**")]
        ecr[("ECR<br/>oficina-api")]

        subgraph vpc["VPC 10.20.0.0/16"]
            subgraph pub["Subnets publicas (2 AZs) · rota 0.0.0.0/0 para IGW"]
                nodes["EKS managed node group<br/>2 a 4 x t3.small"]
            end
            subgraph priv["Subnets privadas (2 AZs) · sem NAT"]
                nlb["NLB interno :80<br/>target_type = ip"]
                rds[("RDS MySQL 8.0<br/>db.t4g.micro")]
            end
        end

        subgraph eks["EKS 1.30 · namespace oficina-env"]
            pods["Deployment oficina-api<br/>2 a 10 Pods<br/>Nginx :80 + PHP-FPM :9000"]
            hpa{{"HPA<br/>CPU 70% · memoria 80%"}}
            eso["External Secrets Operator<br/>ClusterSecretStore oficina-secretsmanager"]
            albc["AWS Load Balancer Controller<br/>TargetGroupBinding"]
            nragent["nri-bundle<br/>kube-state-metrics + Fluent Bit"]
            job[["Job de migration<br/>bin/migrate.php"]]
        end
    end

    cliente -->|"POST /auth/cpf"| apigw
    admin -->|"POST /api/auth/login"| apigw
    webhook -->|"POST /api/service-orders/id/approval<br/>X-Webhook-Token"| apigw
    cliente -->|"GET /api/service-orders/me<br/>Bearer JWT"| apigw

    apigw -->|"invoca"| authcpf
    apigw -.->|"autoriza ANY /api/proxy+"| authz
    apigw ==>|"VPC Link<br/>subnets privadas"| nlb
    nlb -->|"targets = IPs dos Pods"| pods
    albc -.->|"registra targets"| nlb

    authcpf --> rds
    authcpf -.->|"JWT_SECRET (cache estatico)"| sm
    authz -.->|"JWT_SECRET (cache estatico)"| sm

    pods --> rds
    job --> rds
    eso -.->|"materializa Secret oficina-secret"| pods
    eso --> sm
    hpa -.->|"escala"| pods
    nodes -.->|"hospeda"| pods
    pods -.->|"pull da imagem"| ecr

    pods -->|"APM + custom events"| nr
    nragent -->|"metricas + logs"| nr
    authcpf -.->|"layer New Relic"| nr
    authz -.->|"layer New Relic"| nr
    apigw -.->|"access log JSON"| nr

    ssm -.->|"contrato entre stacks"| apigw
    ssm -.->|"contrato entre stacks"| eks
```

## Propriedade dos recursos

```mermaid
flowchart LR
    subgraph db["oficina-infra-fundacao — fundação (durável)"]
        d1["VPC · subnets · IGW · route tables"]
        d2["SG de banco e SG cliente de banco"]
        d3["RDS MySQL 8.0"]
        d4["Secrets Manager: oficina/env/db e oficina/env/auth"]
        d5["migrations/NNN_slug.sql"]
    end
    subgraph k8s["oficina-infra-cluster — descartável"]
        k1["EKS + node group + IRSA"]
        k2["Add-ons Helm: LB Controller · metrics-server<br/>external-secrets · nri-bundle"]
        k3["ECR"]
        k4["NLB interno + target group + listener :80"]
        k5["Namespaces + ClusterSecretStore"]
    end
    subgraph lb["oficina-gateway-auth — descartável"]
        l1["Lambdas auth-cpf e jwt-authorizer"]
        l2["HTTP API · rotas · VPC Link · authorizer"]
    end
    subgraph app["oficina-api — descartável"]
        a1["deploy/base + overlays hml e prod"]
        a2["Deployment · Service · HPA · TargetGroupBinding"]
        a3["ExternalSecret oficina-secret"]
        a4["Job de migration"]
    end

    db -->|"SSM: network/* · db/* · auth/secret_arn"| k8s
    db -->|"SSM: network/private_subnet_ids · db/client_sg_id · db/* · auth/secret_arn"| lb
    k8s -->|"SSM: nlb/listener_arn · nlb/arn"| lb
    k8s -->|"SSM: ecr/repository_url · eks/cluster_name<br/>eks/namespace · nlb/target_group_arn"| app
    db -->|"SSM: db/* (Job de migration)"| app
```

Ordem de `apply`: **fundação → cluster → gateway → api**. Ordem de `destroy`: inversa.

## Notas de leitura

- **Nenhum repositório usa `terraform_remote_state`.** Toda seta pontilhada de acoplamento acima é
  um parâmetro do SSM Parameter Store com nome fixado na seção 2 dos Contratos (ADR-008).
- **O NLB, o target group e o listener nascem do Terraform** do `oficina-infra-cluster`, não do
  `Service` da aplicação (Adendo 1 dos Contratos). O motivo é a ordem de apply: o repositório da
  Lambda precisa do `listener_arn` para montar a integração do gateway, e aplica **antes** do
  repositório da aplicação. Se o NLB nascesse de um `Service` do tipo `LoadBalancer`, o ARN só
  existiria depois — invertendo a ordem contratada.
- **O AWS Load Balancer Controller apenas registra os Pods como targets**, através de um recurso
  `TargetGroupBinding` declarado no `deploy/` da aplicação e apontando para
  `/oficina/<env>/nlb/target_group_arn`. Como o target group é `target_type = ip`, os targets são
  os **IPs dos Pods**, e não os nodes — o tráfego não passa por `NodePort`.
- **O NLB é interno.** Não tem endereço público; só o VPC Link do API Gateway o alcança, e o VPC
  Link usa **as mesmas subnets privadas** do NLB (Adendo 4).
- **Nodes em subnet pública, sem NAT Gateway** (ADR-010). As subnets públicas têm rota
  `0.0.0.0/0` para o IGW — sem ela os nodes não entram no cluster (Adendo 3). O SG dos nodes não
  tem nenhuma regra de ingress vinda de `0.0.0.0/0`.
- **O RDS fica nas subnets privadas**, `publicly_accessible = false`, e aceita `3306` apenas do SG
  `oficina-<env>-db-client`, que é anexado aos nodes do EKS e às Lambdas.
- **O `ClusterSecretStore` chama-se `oficina-secretsmanager`** (Adendo 2); o `ExternalSecret` da
  aplicação materializa o `Secret` `oficina-secret` no namespace.
