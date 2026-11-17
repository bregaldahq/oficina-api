#!/usr/bin/env python3
"""
Importa dashboards, política de alertas e monitor Synthetic no New Relic.

Os arquivos em docs/fase-3/newrelic/ são versionados com o ambiente `prod` fixo nas
consultas NRQL e com `accountIds: [0]` como placeholder. Importar assim num ambiente
que não é `prod` produz painéis estruturalmente corretos e **completamente vazios**,
sem nenhum erro — o modo de falha mais caro que existe em observabilidade.

Este script resolve as duas coisas: injeta o account ID real e reescreve o ambiente
alvo em todas as consultas antes de enviar.

Uso:
    export NEW_RELIC_API_KEY=NRAK-...        # User key, não a license key
    export NEW_RELIC_ACCOUNT_ID=1234567

    scripts/newrelic-import.py --env hml --endpoint https://xxxx.execute-api.us-east-1.amazonaws.com
    scripts/newrelic-import.py --env hml --endpoint https://... --dry-run

Pode rodar quantas vezes precisar: o que já existe com o mesmo nome é atualizado no
lugar — dashboards mantêm o GUID e a URL, condições de alerta são atualizadas pelo
nome, e o monitor Synthetic existente é mantido. Nada é criado em dobro.

Ao final imprime os entity GUIDs criados — são eles que alimentam os secrets
NEW_RELIC_INFRA_ENTITY_GUID e NEW_RELIC_LAMBDA_ENTITY_GUID.
"""

from __future__ import annotations

import argparse
import json
import os
import pathlib
import re
import sys
import urllib.error
import urllib.request

# Contas criadas na regiao da UE usam outro host. Uma chave valida de conta EU
# responde 401 "authentication required" no host US, sem dizer o motivo.
NERDGRAPH = {
    "us": "https://api.newrelic.com/graphql",
    "eu": "https://api.eu.newrelic.com/graphql",
}
DOCS = pathlib.Path(__file__).resolve().parent.parent / "docs"
BASE = DOCS / "fase-3" / "newrelic"
# Fase 4: o dashboard e os alertas da saga (docs/fase-4/CONTRATOS.md §8).
BASE_F4 = DOCS / "fase-4" / "newrelic"


# --------------------------------------------------------------------------- HTTP


def nerdgraph(query: str, variables: dict, api_key: str, region: str = "us") -> dict:
    payload = json.dumps({"query": query, "variables": variables}).encode()
    req = urllib.request.Request(
        NERDGRAPH[region],
        data=payload,
        headers={"Content-Type": "application/json", "API-Key": api_key},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            body = json.loads(resp.read())
    except urllib.error.HTTPError as e:
        detalhe = e.read().decode()[:300]
        if e.code == 401:
            raise SystemExit(
                f"HTTP 401 do NerdGraph ({NERDGRAPH[region]}): {detalhe}\n\n"
                "Causas, em ordem de frequencia:\n"
                "  1. A chave nao e uma User key. License key e Ingest key NAO servem para\n"
                "     mutations. Em Administration -> API keys, o tipo tem que ser USER.\n"
                "  2. A conta e da regiao da UE e este host e o dos EUA. Tente --region eu.\n"
                "  3. A variavel veio truncada ou com espaco. Confira o tamanho: 41 caracteres."
            )
        raise SystemExit(f"HTTP {e.code} do NerdGraph: {detalhe}")

    if body.get("errors"):
        raise SystemExit("NerdGraph recusou:\n" + json.dumps(body["errors"], indent=2, ensure_ascii=False))
    return body["data"]


Q_ACCOUNTS = """
{ actor { accounts { id name } } }
"""


def descobrir_contas(api_key: str, region: str) -> list[dict]:
    """Pergunta ao New Relic quais contas a chave enxerga."""
    return nerdgraph(Q_ACCOUNTS, {}, api_key, region)["actor"]["accounts"]


Q_ENTIDADES = """
query($busca: String!) {
  actor { entitySearch(query: $busca) { results { entities { guid name domain entityType } } } }
}
"""


def buscar_entidades(env: str, api_key: str, region: str) -> list[dict]:
    """Entidades que o agente já reportou — cluster EKS e funções Lambda."""
    busca = f"name LIKE 'oficina-{env}%' OR name = 'oficina-api-{env}'"
    data = nerdgraph(Q_ENTIDADES, {"busca": busca}, api_key, region)
    return data["actor"]["entitySearch"]["results"]["entities"]


# ------------------------------------------------------------------- substituição


# O projeto carrega DUAS nomenclaturas de ambiente, e elas nao coincidem:
#   - infraestrutura (Terraform, SSM, nomes de recurso): hml / prod
#   - runtime da aplicacao (APP_ENV, definido pelo overlay):  homologacao / production
#
# O atributo `env` dos custom events vem do APP_ENV. Filtrar por 'hml' nas NRQL
# devolve zero, mesmo com os eventos chegando — o painel fica vazio sem erro. O valor
# de prod e' "production" (deploy/overlays/prod e o bin/migrate.php, que pula o seed
# de demonstracao com ele); ate a Fase 4 este mapa dizia "producao", e os paineis de
# prod ficavam vazios.
APP_ENV = {"hml": "homologacao", "prod": "production"}


def retarget(obj, env: str, account_id: int, endpoint: str):
    """Reescreve ambiente e account id recursivamente."""
    if isinstance(obj, dict):
        return {k: retarget(v, env, account_id, endpoint) for k, v in obj.items()}
    if isinstance(obj, list):
        # accountIds: [0] -> [<real>]
        if obj == [0]:
            return [account_id]
        return [retarget(v, env, account_id, endpoint) for v in obj]
    if isinstance(obj, str):
        s = obj
        # appName = 'oficina-api-prod'  ->  '...-<env>'
        # (os tres servicos da Fase 4: oficina-api, oficina-faturamento, oficina-execucao)
        s = re.sub(r"(oficina-(?:api|faturamento|execucao))-prod", rf"\g<1>-{env}", s)
        # recursos AWS: oficina-prod-api, oficina-prod-auth-cpf, ...
        s = re.sub(r"oficina-prod-", f"oficina-{env}-", s)
        # atributo dos custom events e dos logs: usa o valor de APP_ENV, nao o da infra
        s = re.sub(r"env\s*=\s*'prod'", f"env = '{APP_ENV.get(env, env)}'", s)
        # clusterName / nomes soltos
        s = s.replace("oficina-prod", f"oficina-{env}")
        # nome da política e títulos: "Oficina Mecânica · prod"
        s = s.replace("· prod", f"· {env}")
        s = s.replace("<apigw-endpoint>", endpoint.replace("https://", "").rstrip("/"))
        s = s.replace("ambiente de produção", f"ambiente {env}")
        return s
    return obj


def load(name: str | pathlib.Path, env: str, account_id: int, endpoint: str):
    raw = json.loads((BASE / name).read_text(encoding="utf-8"))  # caminho absoluto ignora o BASE
    return retarget(raw, env, account_id, endpoint)


# ---------------------------------------------------------------------- mutations

Q_DASHBOARD = """
mutation($accountId: Int!, $dashboard: DashboardInput!) {
  dashboardCreate(accountId: $accountId, dashboard: $dashboard) {
    entityResult { guid name }
    errors { description type }
  }
}
"""

Q_POLICY = """
mutation($accountId: Int!, $policy: AlertsPolicyInput!) {
  alertsPolicyCreate(accountId: $accountId, policy: $policy) { id name }
}
"""

Q_CONDITION = """
mutation($accountId: Int!, $policyId: ID!, $condition: AlertsNrqlConditionStaticInput!) {
  alertsNrqlConditionStaticCreate(accountId: $accountId, policyId: $policyId, condition: $condition) {
    id name
  }
}
"""

Q_DASHBOARD_UPDATE = """
mutation($guid: EntityGuid!, $dashboard: DashboardInput!) {
  dashboardUpdate(guid: $guid, dashboard: $dashboard) {
    entityResult { guid name }
    errors { description type }
  }
}
"""

Q_BUSCA_ENTIDADE = """
query($busca: String!) {
  actor { entitySearch(query: $busca) { results { entities { guid name } } } }
}
"""

Q_POLICIES = """
query($accountId: Int!, $name: String!) {
  actor { account(id: $accountId) { alerts {
    policiesSearch(searchCriteria: {name: $name}) { policies { id name } }
  } } }
}
"""

Q_CONDITIONS = """
query($accountId: Int!, $policyId: ID!) {
  actor { account(id: $accountId) { alerts {
    nrqlConditionsSearch(searchCriteria: {policyId: $policyId}) { nrqlConditions { id name } }
  } } }
}
"""

Q_CONDITION_UPDATE = """
mutation($accountId: Int!, $id: ID!, $condition: AlertsNrqlConditionUpdateStaticInput!) {
  alertsNrqlConditionStaticUpdate(accountId: $accountId, id: $id, condition: $condition) {
    id name
  }
}
"""

Q_SYNTHETIC = """
mutation($accountId: Int!, $monitor: SyntheticsCreateSimpleMonitorInput!) {
  syntheticsCreateSimpleMonitor(accountId: $accountId, monitor: $monitor) {
    monitor { guid name }
    errors { description type }
  }
}
"""


def buscar_por_nome(tipo: str, nome: str, acct: int, key: str, region: str) -> list[dict]:
    """Entidades do tipo dado com exatamente este nome, nesta conta.

    Rodar o import duas vezes criava tudo em dobro: dois dashboards com o mesmo
    nome, dois conjuntos de alertas disparando para o mesmo problema. A busca por
    nome exato é o que torna o script seguro para rodar de novo.
    """
    busca = f"type = '{tipo}' AND accountId = {acct} AND name = '{nome}'"
    data = nerdgraph(Q_BUSCA_ENTIDADE, {"busca": busca}, key, region)
    achadas = data["actor"]["entitySearch"]["results"]["entities"]
    return [e for e in achadas if e["name"] == nome]


def create_dashboard(path: str | pathlib.Path, env, acct, endpoint, key, dry, region="us") -> str | None:
    dash = load(path, env, acct, endpoint)
    dash["name"] = f"{dash['name']} · {env}"
    dash.pop("permissions", None) or dash.setdefault("permissions", "PUBLIC_READ_WRITE")
    dash["permissions"] = "PUBLIC_READ_WRITE"

    if dry:
        pages = ", ".join(f"{p['name']} ({len(p.get('widgets', []))} painéis)" for p in dash["pages"])
        print(f"  [dry-run] criar ou atualizar  {dash['name']}  ->  {pages}")
        return None

    # Atualizar no lugar preserva o GUID — e com ele a URL que já foi compartilhada.
    existentes = buscar_por_nome("DASHBOARD", dash["name"], acct, key, region)
    if len(existentes) > 1:
        print(f"  aviso: {len(existentes)} dashboards chamados '{dash['name']}'; atualizando o primeiro.")
        for e in existentes[1:]:
            print(f"         duplicado  guid={e['guid']}  (apague pela interface)")

    if existentes:
        guid = existentes[0]["guid"]
        data = nerdgraph(Q_DASHBOARD_UPDATE, {"guid": guid, "dashboard": dash}, key, region)
        res, acao = data["dashboardUpdate"], "atualizado"
    else:
        data = nerdgraph(Q_DASHBOARD, {"accountId": acct, "dashboard": dash}, key, region)
        res, acao = data["dashboardCreate"], "criado"

    if res.get("errors"):
        raise SystemExit(f"Falha ao gravar o dashboard {dash['name']}:\n{json.dumps(res['errors'], indent=2)}")
    guid = res["entityResult"]["guid"]
    print(f"  {acao}  {res['entityResult']['name']}  guid={guid}")
    return guid


def create_alerts(env, acct, endpoint, key, dry, region="us", path: str | pathlib.Path = "alertas.json") -> None:
    doc = load(path, env, acct, endpoint)

    policy_in = {
        "name": doc["policy"]["name"],
        "incidentPreference": doc["policy"]["incidentPreference"],
    }

    if dry:
        print(f"  [dry-run] alertsPolicyCreate  {policy_in['name']}")
        for c in doc["conditions"]:
            print(f"  [dry-run]   condição  {c['name']}")
        return

    policies = nerdgraph(Q_POLICIES, {"accountId": acct, "name": policy_in["name"]}, key, region)
    policies = [x for x in policies["actor"]["account"]["alerts"]["policiesSearch"]["policies"]
                if x["name"] == policy_in["name"]]

    if policies:
        policy = policies[0]
        print(f"  política existente  {policy['name']}  id={policy['id']}")
        conds = nerdgraph(Q_CONDITIONS, {"accountId": acct, "policyId": policy["id"]}, key, region)
        por_nome = {x["name"]: x["id"] for x in
                    conds["actor"]["account"]["alerts"]["nrqlConditionsSearch"]["nrqlConditions"]}
    else:
        policy = nerdgraph(Q_POLICY, {"accountId": acct, "policy": policy_in}, key, region)["alertsPolicyCreate"]
        print(f"  política criada  {policy['name']}  id={policy['id']}")
        por_nome = {}

    for c in doc["conditions"]:
        cond = {
            "name": c["name"],
            "enabled": c.get("enabled", True),
            "nrql": c["nrql"],
            "signal": c["signal"],
            "terms": c["terms"],
            "violationTimeLimitSeconds": c.get("violationTimeLimitSeconds", 86400),
        }
        if c.get("description"):
            cond["description"] = c["description"]

        if c["name"] in por_nome:
            nerdgraph(Q_CONDITION_UPDATE,
                      {"accountId": acct, "id": por_nome[c["name"]], "condition": cond}, key, region)
            print(f"    atualizada  {c['name']}")
        else:
            nerdgraph(Q_CONDITION, {"accountId": acct, "policyId": policy["id"], "condition": cond}, key, region)
            print(f"    criada      {c['name']}")


def create_synthetic(env, acct, endpoint, key, dry, region="us") -> None:
    doc = load("alertas.json", env, acct, endpoint)
    m = doc.get("syntheticMonitor")
    if not m:
        return

    monitor = {
        "name": m["name"],
        "period": m["period"],
        "status": m["status"],
        "uri": m["uri"] if m["uri"].startswith("http") else f"https://{m['uri']}",
        "locations": {"public": m["locations"]},
    }

    if dry:
        print(f"  [dry-run] syntheticsCreateSimpleMonitor  {monitor['name']}  {monitor['uri']}")
        return

    if buscar_por_nome("MONITOR", monitor["name"], acct, key, region):
        print(f"  monitor existente  {monitor['name']}  (mantido)")
        return

    data = nerdgraph(Q_SYNTHETIC, {"accountId": acct, "monitor": monitor}, key, region)
    res = data["syntheticsCreateSimpleMonitor"]
    if res.get("errors"):
        raise SystemExit(f"Falha ao criar o monitor:\n{json.dumps(res['errors'], indent=2)}")
    print(f"  monitor criado  {res['monitor']['name']}  guid={res['monitor']['guid']}")


# -------------------------------------------------------------------------- main


def main() -> None:
    ap = argparse.ArgumentParser(description="Importa a observabilidade no New Relic.")
    ap.add_argument("--env", required=True, choices=["hml", "prod"])
    ap.add_argument("--endpoint", required=True, help="URL do API Gateway do ambiente")
    ap.add_argument("--dry-run", action="store_true", help="mostra o que faria, sem criar nada")
    ap.add_argument("--region", default="us", choices=["us", "eu"],
                    help="regiao da conta (contas EU usam outro endpoint)")
    ap.add_argument("--skip-synthetic", action="store_true")
    args = ap.parse_args()

    key = os.environ.get("NEW_RELIC_API_KEY", "")
    acct = os.environ.get("NEW_RELIC_ACCOUNT_ID", "")

    if not args.dry_run and not key.startswith("NRAK-"):
        sys.exit("NEW_RELIC_API_KEY ausente ou não é uma User key (deve começar com NRAK-).")
    if acct.isdigit() and acct != "1234567":
        acct_i = int(acct)
    elif args.dry_run:
        acct_i = 0
        print("(dry-run sem account id real — os paineis usariam 0)")
    else:
        if acct == "1234567":
            print("NEW_RELIC_ACCOUNT_ID esta com o numero de exemplo da documentacao; ignorando.")
        print("Descobrindo a conta pela API...")
        contas = descobrir_contas(key, args.region)
        if not contas:
            sys.exit("A chave nao enxerga nenhuma conta. Confira o tipo (precisa ser USER).")
        if len(contas) > 1:
            print("  Mais de uma conta visivel:")
            for c in contas:
                print(f"    {c['id']}  {c['name']}")
            sys.exit("Defina NEW_RELIC_ACCOUNT_ID com a conta desejada e rode de novo.")
        acct_i = int(contas[0]["id"])
        print(f"  conta {acct_i} ({contas[0]['name']})")
    print(f"==> conta {acct_i} · ambiente {args.env} · {args.endpoint}")
    if args.dry_run:
        print("==> DRY-RUN: nada será criado.\n")

    print("Dashboards:")
    create_dashboard("dashboard-negocio.json", args.env, acct_i, args.endpoint, key, args.dry_run, args.region)
    create_dashboard("dashboard-plataforma.json", args.env, acct_i, args.endpoint, key, args.dry_run, args.region)
    create_dashboard(BASE_F4 / "dashboard-saga.json", args.env, acct_i, args.endpoint, key, args.dry_run, args.region)

    print("\nAlertas:")
    create_alerts(args.env, acct_i, args.endpoint, key, args.dry_run, args.region)
    create_alerts(args.env, acct_i, args.endpoint, key, args.dry_run, args.region, BASE_F4 / "alertas-saga.json")

    if not args.skip_synthetic:
        print("\nSynthetic:")
        create_synthetic(args.env, acct_i, args.endpoint, key, args.dry_run, args.region)

    print("\n==> Concluído.")

    # Os GUIDs impressos acima são dos artefatos que ESTE script cria (dashboards e
    # monitor). Os secrets do marcador de deploy querem outra coisa: as entidades
    # MONITORADAS — o cluster e as funções — que nascem quando o agente reporta.
    if not args.dry_run:
        print("\nEntidades monitoradas (para os secrets do marcador de deploy):")
        try:
            achadas = buscar_entidades(args.env, key, args.region)
        except SystemExit:
            achadas = []
        if achadas:
            for e in achadas:
                print(f"  {e['domain']:6} {e['name']:44} {e['guid']}")
        else:
            print("  nenhuma ainda — o agente precisa reportar primeiro. Rode de novo mais tarde.")


if __name__ == "__main__":
    main()
