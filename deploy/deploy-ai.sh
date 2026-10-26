#!/usr/bin/env bash
# Rolls the AI service to one image and checks it; puts the previous image back when it is not healthy.
#   ./deploy-ai.sh ghcr.io/<owner>/lms-ai-service:<tag>
# The AI service is optional and isolated: a failure here never touches the API, web or load balancer.
set -euo pipefail
cd "$(dirname "$0")"
image="${1:?AI image reference}"

./make-ai-env.sh
touch .env
grep -q '^AI_IMAGE=' .env || echo "AI_IMAGE=ghcr.io/muhammadelalfy/lms-ai-service:latest" >> .env
previous="$(grep -m1 '^AI_IMAGE=' .env | cut -d= -f2-)"
set_image() { sed -i "s|^AI_IMAGE=.*|AI_IMAGE=$1|" .env; export AI_IMAGE="$1"; }
log() { printf '==> %s\n' "$*"; }
healthy() {
    local i
    for i in $(seq 1 30); do
        if docker compose --profile ai exec -T lms-ai python -c \
            "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8001/ready', timeout=3)" >/dev/null 2>&1; then
            return 0
        fi
        sleep 4
    done
    return 1
}

set_image "$image"
log "pulling $image"
docker compose --profile ai pull --quiet lms-ai
log "starting the AI database and service"
docker compose --profile ai up -d --no-deps --wait ai-db
if docker compose --profile ai up -d --no-deps --force-recreate --wait --wait-timeout 120 lms-ai && healthy; then
    log "AI service is ready on $image"
    # The worker runs Laravel code: start it only when this host's API image has the AI module
    # (it does once the AI module is deployed); otherwise leave it stopped and say so.
    if docker compose --profile ai up -d --no-deps --wait ai-worker; then
        log "ai-worker running"
    else
        log "ai-worker did not start (the API image may not contain the AI module yet): AI service stays up"
    fi
    docker image prune -f >/dev/null || true
    exit 0
fi

log "AI service failed on $image; putting back $previous"
docker compose --profile ai logs --tail=40 lms-ai || true
set_image "$previous"
docker compose --profile ai up -d --no-deps --force-recreate lms-ai || true
exit 1
