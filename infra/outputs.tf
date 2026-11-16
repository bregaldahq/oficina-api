output "queue_url" {
  description = "URL da fila do serviço."
  value       = aws_sqs_queue.main.url
}

output "irsa_role_arn" {
  description = "Role assumido pelos pods."
  value       = aws_iam_role.service.arn
}
