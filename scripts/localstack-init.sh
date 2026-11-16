#!/bin/sh
# Cria no LocalStack o que o infra/ cria na AWS para este serviço: a fila com DLQ e a assinatura do
# tópico. O banco é o MySQL da fundação e não passa pelo LocalStack. Roda DENTRO do container do LocalStack (usa awslocal):
#
#   docker exec -i <localstack> sh < scripts/localstack-init.sh
#
# Usado no desenvolvimento local e no e2e do repositório principal. O filtro da assinatura tem
# de acompanhar infra/locals.tf.
set -eu

ENVIRONMENT="${ENVIRONMENT:-local}"
PREFIX="oficina-${ENVIRONMENT}"
SERVICE="os"

TOPIC_ARN="$(awslocal sns create-topic --name "${PREFIX}-mensagens" --query TopicArn --output text)"

DLQ_URL="$(awslocal sqs create-queue --queue-name "${PREFIX}-${SERVICE}-dlq" --query QueueUrl --output text)"
DLQ_ARN="$(awslocal sqs get-queue-attributes --queue-url "$DLQ_URL" --attribute-names QueueArn --query Attributes.QueueArn --output text)"
QUEUE_URL="$(awslocal sqs create-queue --queue-name "${PREFIX}-${SERVICE}" \
  --attributes "{\"RedrivePolicy\":\"{\\\"deadLetterTargetArn\\\":\\\"${DLQ_ARN}\\\",\\\"maxReceiveCount\\\":\\\"5\\\"}\"}" \
  --query QueueUrl --output text)"
QUEUE_ARN="$(awslocal sqs get-queue-attributes --queue-url "$QUEUE_URL" --attribute-names QueueArn --query Attributes.QueueArn --output text)"

# Os eventos que a saga consome: a mesma lista de local.consumed_message_types (infra/locals.tf).
awslocal sns subscribe --topic-arn "$TOPIC_ARN" --protocol sqs --notification-endpoint "$QUEUE_ARN" \
  --attributes '{"RawMessageDelivery":"true","FilterPolicy":"{\"type\":[\"diagnosis.started\",\"diagnosis.completed\",\"diagnosis.failed\",\"repair.started\",\"repair.completed\",\"repair.failed\",\"execution.cancelled\",\"budget.generated\",\"budget.failed\",\"budget.approved\",\"budget.rejected\",\"budget.cancelled\",\"payment.created\",\"payment.confirmed\",\"payment.failed\",\"payment.refunded\"]}"}' \
  >/dev/null

echo "MESSAGING_TOPIC_ARN=${TOPIC_ARN}"
echo "MESSAGING_QUEUE_URL=${QUEUE_URL}"
