#!/bin/sh
# Entrypoint da imagem da aplicação.
#
# 1) Materializa o arquivo .env que a aplicação espera (EnvLoader::loadFile) a
#    partir do ambiente do container (ConfigMap + Secret no Kubernetes). Em
#    desenvolvimento local o .env vem por bind mount, então isso vira no-op.
# 2) Deixa o agente do New Relic silenciosamente inerte quando não há license
#    key (ambiente local e CI): a extensão continua carregada — `php -m` mostra
#    `newrelic` —, mas nada é coletado e o daemon não sobe.
set -e

# ── New Relic ────────────────────────────────────────────────────────────────
NEW_RELIC_APP_NAME="${NEW_RELIC_APP_NAME:-oficina-api-local}"
NEW_RELIC_DISTRIBUTED_TRACING_ENABLED="${NEW_RELIC_DISTRIBUTED_TRACING_ENABLED:-true}"
export NEW_RELIC_APP_NAME NEW_RELIC_DISTRIBUTED_TRACING_ENABLED

if [ -z "${NEW_RELIC_LICENSE_KEY:-}" ]; then
    NEW_RELIC_ENABLED=false
    NEW_RELIC_MONITOR_MODE=false
    # 3 = nunca tentar iniciar o daemon (sem processo extra, sem log de erro).
    NEW_RELIC_DAEMON_DONT_LAUNCH=3
    export NEW_RELIC_LICENSE_KEY=""
else
    NEW_RELIC_ENABLED=true
    NEW_RELIC_MONITOR_MODE=true
    NEW_RELIC_DAEMON_DONT_LAUNCH=0
fi
export NEW_RELIC_ENABLED NEW_RELIC_MONITOR_MODE NEW_RELIC_DAEMON_DONT_LAUNCH

# ── .env ─────────────────────────────────────────────────────────────────────
ENV_FILE="/var/www/html/.env"

if [ ! -f "$ENV_FILE" ]; then
    for var in APP_ENV APP_DEBUG \
               DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD \
               JWT_SECRET JWT_EXPIRATION \
               ADMIN_USERNAME ADMIN_PASSWORD \
               INTERNAL_TOKEN \
               AWS_REGION AWS_ENDPOINT_URL MESSAGING_TOPIC_ARN MESSAGING_QUEUE_URL WORKER_WAIT_SECONDS \
               SAGA_BUDGET_TIMEOUT_SECONDS SAGA_APPROVAL_TIMEOUT_SECONDS SAGA_PAYMENT_TIMEOUT_SECONDS SAGA_WORK_ALERT_SECONDS \
               NEW_RELIC_APP_NAME NEW_RELIC_LICENSE_KEY \
               SMTP_HOST SMTP_PORT SMTP_USERNAME SMTP_PASSWORD MAIL_FROM MAIL_TO; do
        eval "value=\${$var:-}"
        if [ -n "$value" ]; then
            printf '%s=%s\n' "$var" "$value" >> "$ENV_FILE"
        fi
    done
fi

exec "$@"
