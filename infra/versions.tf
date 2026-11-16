terraform {
  required_version = ">= 1.6.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.60"
    }
  }

  # Backend parametrizado via -backend-config no `terraform init` (ver deploy.yml).
  # key: oficina/oficina-mecanica-tech-challenge/<env>/terraform.tfstate (nome antigo do repo, de
  # propósito: README do repositório principal, "Nomes antigos")
  backend "s3" {}
}
