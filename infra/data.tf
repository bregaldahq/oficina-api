# Tudo o que este serviço precisa dos repos de plataforma chega por SSM (ADR-008 e ADR-016).
# Nenhum terraform_remote_state, e nenhum parâmetro de outro serviço.

data "aws_ssm_parameter" "topic_arn" {
  name = "/oficina/${var.environment}/messaging/topic_arn"
}

data "aws_ssm_parameter" "oidc_provider_arn" {
  name = "/oficina/${var.environment}/eks/oidc_provider_arn"
}

data "aws_ssm_parameter" "namespace" {
  name = "/oficina/${var.environment}/eks/namespace"
}
