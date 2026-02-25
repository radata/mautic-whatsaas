#!/bin/bash
#
# Test the Mautic WhatSaaS webhook receiver
#
# Usage:
#   ./test-webhook.sh                  # internal Docker (from whatsapp compose stack)
#   ./test-webhook.sh public           # external HTTPS
#   ./test-webhook.sh internal         # internal Docker (default)
#

MODE="${1:-internal}"
TIMESTAMP=$(date +%s)
MESSAGE_ID="TEST_$(date +%Y%m%d%H%M%S)"

PAYLOAD=$(cat <<'ENDJSON'
{
  "event": "messages.upsert",
  "instance": "HW-9908",
  "data": {
    "key": {
      "remoteJid": "31684908391@s.whatsapp.net",
      "remoteJidAlt": "31684908391@s.whatsapp.net",
      "fromMe": false,
      "id": "__MESSAGE_ID__",
      "participant": "",
      "addressingMode": "lid"
    },
    "pushName": "Robert Golebiewski",
    "status": "DELIVERY_ACK",
    "message": {
      "conversation": "Test webhook __TIMESTAMP__"
    },
    "messageType": "conversation",
    "messageTimestamp": __TIMESTAMP__,
    "instanceId": "e0b2d605-1b51-4f12-beb9-8c05241af8a6",
    "source": "ios"
  },
  "destination": "https://wa.hollandworx.nl/api/webhook/evolution",
  "date_time": "__ISO_DATE__",
  "sender": "31622939908@s.whatsapp.net",
  "server_url": "http://localhost:8080",
  "apikey": "test"
}
ENDJSON
)

# Replace placeholders
ISO_DATE=$(date -u +"%Y-%m-%dT%H:%M:%S.000Z")
PAYLOAD=$(echo "$PAYLOAD" | sed "s/__MESSAGE_ID__/$MESSAGE_ID/g" | sed "s/__TIMESTAMP__/$TIMESTAMP/g" | sed "s/__ISO_DATE__/$ISO_DATE/g")

echo "=== WhatSaaS Webhook Test ==="
echo "Mode:       $MODE"
echo "Message ID: $MESSAGE_ID"
echo "Timestamp:  $TIMESTAMP"
echo ""

if [ "$MODE" = "public" ]; then
    URL="https://go.hollandworx.nl/whatsaas/webhook"
    echo "URL: $URL"
    echo ""
    curl -s -w "\nHTTP Status: %{http_code}\n" \
        -X POST "$URL" \
        -H "Content-Type: application/json" \
        -d "$PAYLOAD"

elif [ "$MODE" = "internal" ]; then
    URL="http://mautic_nginx/whatsaas/webhook"
    echo "URL: $URL (via docker exec)"
    echo ""
    # Use wget from Alpine container (no curl available)
    docker compose exec -T app wget -qO- -S \
        --header="Content-Type: application/json" \
        --post-data="$PAYLOAD" \
        "$URL" 2>&1

else
    echo "Unknown mode: $MODE"
    echo "Usage: $0 [internal|public]"
    exit 1
fi

echo ""
echo ""
echo "=== Check Mautic logs ==="
echo "docker exec mautic_web grep -i whatsaas /var/www/html/var/logs/mautic_prod-\$(date +%Y-%m-%d).php | tail -5"
