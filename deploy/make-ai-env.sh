#!/usr/bin/env bash
# Prepares the AI service's settings on a host that already runs the stack. Safe to repeat:
# it never overwrites a value that is already there.
#   - creates .env.ai (service token, database password, Redis for shared budgets) if missing;
#   - adds AI_SERVICE_URL / AI_SERVICE_TOKEN and REDIS_QUEUE_RETRY_AFTER to .env.production
#     if they are missing, using the same token, so Laravel and the service agree.
# Provider keys (ANTHROPIC_API_KEY, VOYAGE_API_KEY) are NOT generated: put them in .env.ai by hand.
set -euo pipefail
cd "$(dirname "$0")"
[[ -f .env.production ]] || { echo ".env.production is missing; run make-env.sh first" >&2; exit 1; }

umask 077
if [[ ! -e .env.ai ]]; then
    token="$(openssl rand -hex 32)"
    dbpw="$(openssl rand -hex 24)"
    cat > .env.ai <<ENV
# AI service (lms-ai-service). Keys below are filled in by hand; empty means the offline fake is used.
SERVICE_TOKEN=${token}
POSTGRES_USER=ai
POSTGRES_PASSWORD=${dbpw}
POSTGRES_DB=ai
DATABASE_URL=postgresql://ai:${dbpw}@ai-db:5432/ai
AUTO_MIGRATE=true
USAGE_BACKEND=redis
REDIS_URL=redis://redis:6379/1
LLM_PROVIDER=auto
LLM_MODEL=claude-sonnet-5-5
ANTHROPIC_API_KEY=
# This host has 2 GB of RAM: use a hosted embeddings API, not the local model.
EMBEDDING_PROVIDER=auto
EMBEDDING_MODEL=voyage-3
EMBEDDING_DIM=1024
VOYAGE_API_KEY=
DAILY_REQUEST_CAP=500
DAILY_TOKEN_CAP=500000
ENV
    echo "wrote .env.ai (mode 600): add ANTHROPIC_API_KEY and VOYAGE_API_KEY"
fi

token="$(grep -m1 '^SERVICE_TOKEN=' .env.ai | cut -d= -f2-)"
add() { grep -q "^$1=" .env.production || { printf '%s=%s\n' "$1" "$2" >> .env.production; echo "added $1 to .env.production"; }; }
add AI_SERVICE_URL "http://lms-ai:8001"
add AI_SERVICE_TOKEN "$token"
add REDIS_QUEUE_RETRY_AFTER 200
