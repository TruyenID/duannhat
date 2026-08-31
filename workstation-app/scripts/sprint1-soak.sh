#!/usr/bin/env bash
# Sprint 1 soak test: verify ops gates under sustained load.
#
# Usage:
#   ./scripts/sprint1-soak.sh                        # full 4-hour run (production verification)
#   SOAK_DURATION=3600 ./scripts/sprint1-soak.sh     # 1h smoke (WAL bound check enabled)
#   SOAK_DURATION=900 ./scripts/sprint1-soak.sh      # 15-min fast iteration (stability only)
#   SOAK_DURATION=60 ./scripts/sprint1-soak.sh       # 60-second smoke run (CI / local)
#
# Acceptance criteria:
#   - WAL file stays under 50 MB (checked when DURATION >= 3600s)
#   - At least one snapshot file created (checked when DURATION >= 21600s;
#       full backup verification requires a 6+ hour run)
#   - No panic / fatal in log
#   - audit_log + sync_queue row counts grow (writes actually happened)
#
# Traffic pattern:
#   - Seeds menu via POST /api/menu/seed at startup
#   - 70% of requests: POST /api/orders (write order + enqueue sync + write audit_log)
#   - 30% of requests: POST /api/orders/{id}/payment (record payment + write audit_log)
#   This drives real DB writes so WAL, audit_log, and sync_queue maintenance code is exercised.
set -euo pipefail

# Defaults — overridable via env
DURATION_SEC="${SOAK_DURATION:-14400}"   # 4h default
REQ_PER_SEC="${SOAK_RPS:-16}"            # ≈ 1000/min
DB="${WS_APP_DB:-${WS_APP_CONFIG_DIR:-$HOME/.ws-app}/ws-app.db}"
BACKUP_DIR="$(dirname "$DB")/backups"
LOG=/tmp/ws-app-soak.log
PORT="${WS_APP_PORT:-8080}"
# ws-server is a headless HTTP-only build suitable for CI and soak testing.
# Falls back to ws-app (Wails desktop) if ws-server is absent.
if [ -z "${WS_APP_BIN:-}" ]; then
  if [ -x "./build/bin/ws-server" ]; then
    BIN="./build/bin/ws-server"
  else
    BIN="./build/bin/ws-app"
  fi
else
  BIN="$WS_APP_BIN"
fi

if [ ! -x "$BIN" ]; then
  echo "FAIL: binary not found at $BIN — run 'make build' first."
  exit 2
fi

echo "==> Starting ws-app from $BIN (log: $LOG)"
"$BIN" > "$LOG" 2>&1 &
WS_PID=$!
trap "kill $WS_PID 2>/dev/null || true" EXIT
sleep 3

if ! kill -0 "$WS_PID" 2>/dev/null; then
  echo "FAIL: ws-app exited within 3 seconds. Tail of $LOG:"
  tail -20 "$LOG"
  exit 1
fi

echo "==> Seeding menu..."
curl -fsS -X POST "http://localhost:${PORT}/api/menu/seed" -d '{}' > /dev/null || {
  echo "FAIL: menu seed failed — server may not be ready"
  exit 1
}

# Capture one menu item id to use in orders
MENU_ITEM_ID=$(curl -fsS "http://localhost:${PORT}/api/menu" | grep -oE '"id":"[^"]+"' | head -1 | sed 's/"id":"//;s/"//' || true)
if [ -z "$MENU_ITEM_ID" ]; then
  echo "FAIL: could not extract menu item id from /api/menu"
  exit 1
fi
echo "  using menu_item_id=$MENU_ITEM_ID"

echo "==> Driving ${REQ_PER_SEC} req/sec of order traffic for ${DURATION_SEC}s..."
END=$(($(date +%s) + DURATION_SEC))
ORDERS_CREATED=0
ORDERS_PAID=0
while [ "$(date +%s)" -lt "$END" ]; do
  CURL_PIDS=()
  for i in $(seq 1 "$REQ_PER_SEC"); do
    # Mix: 70% create order, 30% mark first active order paid
    if [ $((RANDOM % 10)) -lt 7 ]; then
      curl -fsS -X POST "http://localhost:${PORT}/api/orders" \
        -H 'Content-Type: application/json' \
        -d "{\"table_number\":\"T$i\",\"items\":[{\"menu_item_id\":\"$MENU_ITEM_ID\",\"name\":\"Soak Item\",\"quantity\":1,\"unit_price\":10000,\"printer_group\":\"kitchen\"}]}" \
        > /dev/null 2>&1 &
      CURL_PIDS+=($!)
      ORDERS_CREATED=$((ORDERS_CREATED + 1))
    else
      # Use the first unpaid order in the list (naturally rotates as orders get paid)
      ORDER_ID=$(curl -fsS "http://localhost:${PORT}/api/orders" 2>/dev/null | grep -oE '"id":"[^"]+"' | head -1 | sed 's/"id":"//;s/"//' || true)
      if [ -n "$ORDER_ID" ]; then
        curl -fsS -X POST "http://localhost:${PORT}/api/orders/${ORDER_ID}/payment" \
          -H 'Content-Type: application/json' -d '{"method":"cash"}' \
          > /dev/null 2>&1 &
        CURL_PIDS+=($!)
        ORDERS_PAID=$((ORDERS_PAID + 1))
      fi
    fi
  done
  wait "${CURL_PIDS[@]}" 2>/dev/null || true
  sleep 1
done

echo "  orders created: $ORDERS_CREATED"
echo "  orders paid:    $ORDERS_PAID"

echo "==> Checking metrics..."

# WAL size — should stay bounded thanks to wal_autocheckpoint + hourly TRUNCATE
WAL_SIZE=$(stat -f%z "${DB}-wal" 2>/dev/null || stat -c%s "${DB}-wal" 2>/dev/null || echo 0)
echo "  WAL size: ${WAL_SIZE} bytes"

# Bound only enforced on runs >= 1h; shorter runs are too short for autocheckpoint to engage
if [ "$DURATION_SEC" -ge 3600 ]; then
  if [ "$WAL_SIZE" -ge 52428800 ]; then
    echo "FAIL: WAL > 50 MB"
    exit 1
  fi
fi

AUDIT_COUNT=$(sqlite3 "$DB" "SELECT COUNT(*) FROM audit_log;" 2>/dev/null || echo "?")
echo "  audit_log rows: $AUDIT_COUNT"

SYNC_COUNT=$(sqlite3 "$DB" "SELECT COUNT(*) FROM sync_queue;" 2>/dev/null || echo "?")
echo "  sync_queue rows: $SYNC_COUNT"

CACHE_COUNT=$(sqlite3 "$DB" "SELECT COUNT(*) FROM auth_token_cache;" 2>/dev/null || echo "?")
echo "  auth_token_cache rows: $CACHE_COUNT"

if [ -d "$BACKUP_DIR" ]; then
  BACKUPS=$(ls -1 "$BACKUP_DIR" 2>/dev/null | wc -l | tr -d ' ')
  echo "  backups: $BACKUPS"
else
  BACKUPS=0
  echo "  backups: 0 (dir not yet created)"
fi

# Backup requirement only on full runs (interval is 6h)
# Note: full backup verification requires DURATION >= 21600s.
# The 1h smoke verifies WAL bound + maintenance loops fire at least once.
if [ "$DURATION_SEC" -ge 21600 ]; then
  if [ "$BACKUPS" -lt 1 ]; then
    echo "FAIL: no backups created after 6h"
    exit 1
  fi
fi

# Verify writes actually happened (soak exercises real maintenance code)
if [ "$AUDIT_COUNT" = "0" ] || [ "$AUDIT_COUNT" = "?" ]; then
  echo "FAIL: audit_log is empty — no writes reached the DB"
  exit 1
fi

if grep -qE "panic|fatal" "$LOG"; then
  echo "FAIL: panic/fatal in log"
  grep -E "panic|fatal" "$LOG" | head -5
  exit 1
fi

echo "==> PASS"
