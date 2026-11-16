locals {
  name_prefix = "oficina-${var.environment}"
  # Chave curta do serviço de OS nos nomes de fila e parâmetro (docs/fase-4/CONTRATOS.md §2).
  service      = "os"
  service_name = "oficina-api"

  common_tags = {
    Project     = "oficina-mecanica"
    Environment = var.environment
    ManagedBy   = "terraform"
    Repo        = "oficina-mecanica-tech-challenge" # nome antigo de oficina-api; mantido de propósito
  }

  # Os eventos que a Execução e o Faturamento publicam para a saga: o filtro da assinatura.
  # A lista é a do catálogo (docs/fase-4/CONTRATOS.md §5.3). Tipo novo entra aqui, no
  # orquestrador e no catálogo, no mesmo conjunto de PRs.
  consumed_message_types = [
    "diagnosis.started",
    "diagnosis.completed",
    "diagnosis.failed",
    "repair.started",
    "repair.completed",
    "repair.failed",
    "execution.cancelled",
    "budget.generated",
    "budget.failed",
    "budget.approved",
    "budget.rejected",
    "budget.cancelled",
    "payment.created",
    "payment.confirmed",
    "payment.failed",
    "payment.refunded",
  ]

  # ServiceAccount do deploy/ (deploy/base/serviceaccount.yaml).
  service_account = "oficina-api"
  namespace       = data.aws_ssm_parameter.namespace.value

  oidc_provider_arn = data.aws_ssm_parameter.oidc_provider_arn.value
  oidc_issuer       = replace(local.oidc_provider_arn, "/^.*oidc-provider\\//", "")
}
