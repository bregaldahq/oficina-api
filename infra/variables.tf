variable "environment" {
  description = "Ambiente: hml ou prod."
  type        = string

  validation {
    condition     = contains(["hml", "prod"], var.environment)
    error_message = "environment deve ser hml ou prod."
  }
}

variable "aws_region" {
  description = "Região AWS."
  type        = string
  default     = "us-east-1"
}

variable "queue_max_receive_count" {
  description = "Tentativas antes de a mensagem ir para a DLQ (docs/fase-4/CONTRATOS.md §5)."
  type        = number
  default     = 5
}
