# Parâmetros lidos pelo deploy.yml deste repo (docs/fase-4/CONTRATOS.md §2). Nenhum outro
# serviço os lê. O ECR do serviço de OS continua no oficina-infra-cluster (Fase 3).

resource "aws_ssm_parameter" "queue_url" {
  name  = "/oficina/${var.environment}/${local.service}/queue_url"
  type  = "String"
  value = aws_sqs_queue.main.url
}

resource "aws_ssm_parameter" "queue_arn" {
  name  = "/oficina/${var.environment}/${local.service}/queue_arn"
  type  = "String"
  value = aws_sqs_queue.main.arn
}

resource "aws_ssm_parameter" "irsa_role_arn" {
  name  = "/oficina/${var.environment}/${local.service}/irsa_role_arn"
  type  = "String"
  value = aws_iam_role.service.arn
}
