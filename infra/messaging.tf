# Fila do serviço, DLQ e assinatura do tópico (docs/fase-4/CONTRATOS.md §5, no oficina-api).
# O serviço publica só no tópico e lê só desta fila.

resource "aws_sqs_queue" "dlq" {
  name                      = "${local.name_prefix}-${local.service}-dlq"
  message_retention_seconds = 1209600 # 14 dias: tempo para alguém olhar

  tags = { Name = "${local.name_prefix}-${local.service}-dlq" }
}

resource "aws_sqs_queue" "main" {
  name                       = "${local.name_prefix}-${local.service}"
  receive_wait_time_seconds  = 20 # long polling
  visibility_timeout_seconds = 60
  message_retention_seconds  = 345600 # 4 dias

  redrive_policy = jsonencode({
    deadLetterTargetArn = aws_sqs_queue.dlq.arn
    maxReceiveCount     = var.queue_max_receive_count
  })

  tags = { Name = "${local.name_prefix}-${local.service}" }
}

# Só o tópico do projeto pode entregar nesta fila.
data "aws_iam_policy_document" "queue" {
  statement {
    sid       = "EntregaDoTopico"
    actions   = ["sqs:SendMessage"]
    resources = [aws_sqs_queue.main.arn]

    principals {
      type        = "Service"
      identifiers = ["sns.amazonaws.com"]
    }

    condition {
      test     = "ArnEquals"
      variable = "aws:SourceArn"
      values   = [data.aws_ssm_parameter.topic_arn.value]
    }
  }
}

resource "aws_sqs_queue_policy" "main" {
  queue_url = aws_sqs_queue.main.id
  policy    = data.aws_iam_policy_document.queue.json
}

resource "aws_sns_topic_subscription" "main" {
  topic_arn            = data.aws_ssm_parameter.topic_arn.value
  protocol             = "sqs"
  endpoint             = aws_sqs_queue.main.arn
  raw_message_delivery = true

  # Filtro pelo atributo de mensagem `type`.
  filter_policy = jsonencode({ type = local.consumed_message_types })
}
