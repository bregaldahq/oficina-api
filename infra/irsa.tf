# Role que os pods do serviço assumem pelo ServiceAccount (IRSA). Nenhuma access key.

data "aws_iam_policy_document" "irsa_trust" {
  statement {
    actions = ["sts:AssumeRoleWithWebIdentity"]

    principals {
      type        = "Federated"
      identifiers = [local.oidc_provider_arn]
    }

    condition {
      test     = "StringEquals"
      variable = "${local.oidc_issuer}:sub"
      values   = ["system:serviceaccount:${local.namespace}:${local.service_account}"]
    }

    condition {
      test     = "StringEquals"
      variable = "${local.oidc_issuer}:aud"
      values   = ["sts.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "service" {
  name               = "${local.name_prefix}-${local.service}"
  description        = "Pods do ${local.service_name} (IRSA): API e worker da saga"
  assume_role_policy = data.aws_iam_policy_document.irsa_trust.json
}

data "aws_iam_policy_document" "service" {
  statement {
    sid       = "PublicarNoTopico"
    actions   = ["sns:Publish"]
    resources = [data.aws_ssm_parameter.topic_arn.value]
  }

  statement {
    sid = "ConsumirAPropriaFila"
    actions = [
      "sqs:ReceiveMessage",
      "sqs:DeleteMessage",
      "sqs:ChangeMessageVisibility",
      "sqs:GetQueueAttributes",
      "sqs:GetQueueUrl",
    ]
    resources = [aws_sqs_queue.main.arn]
  }
}

resource "aws_iam_role_policy" "service" {
  name   = "acesso-do-servico"
  role   = aws_iam_role.service.id
  policy = data.aws_iam_policy_document.service.json
}
