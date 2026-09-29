#!/usr/bin/env bash
#
# Integration test script for staff invite / accept-invite / login flow.
# Usage: ./scripts/test-staff-invite.sh [BASE_URL] [COACH_EMAIL] [COACH_PASSWORD]
#
set -euo pipefail

BASE_URL="${1:-http://localhost:8000/v1}"
COACH_EMAIL="${2:-}"
COACH_PASSWORD="${3:-}"

if [[ -z "$COACH_EMAIL" || -z "$COACH_PASSWORD" ]]; then
    echo "Usage: $0 BASE_URL COACH_EMAIL COACH_PASSWORD"
    echo "Example: $0 http://localhost:8000/v1 coach@example.com secret123"
    exit 1
fi

STAFF_EMAIL="staff-$(date +%s)@example.com"
STAFF_NAME="Test Staff"
STAFF_PASSWORD="SecurePass123!"

echo "============================================"
echo "Staff Invite Flow Integration Test"
echo "============================================"
echo ""

# ── Step 1: Login as coach ──
echo "[1/6] Logging in as coach..."
LOGIN_RES=$(curl -s -X POST "${BASE_URL}/auth/coach/login" \
    -H "Content-Type: application/json" \
    -c /tmp/cookies.txt \
    -d "{\"email\":\"${COACH_EMAIL}\",\"password\":\"${COACH_PASSWORD}\"}")
ACCESS_TOKEN=$(echo "$LOGIN_RES" | python3 -c "import sys,json; print(json.load(sys.stdin).get('data',{}).get('access_token',''))" 2>/dev/null || true)

if [[ -z "$ACCESS_TOKEN" ]]; then
    echo "ERROR: Coach login failed. Response:"
    echo "$LOGIN_RES" | python3 -m json.tool 2>/dev/null || echo "$LOGIN_RES"
    exit 1
fi
echo "      Coach logged in successfully."

# ── Step 2: Invite staff ──
echo "[2/6] Inviting staff member (${STAFF_EMAIL})..."
INVITE_RES=$(curl -s -X POST "${BASE_URL}/org/staff/invite" \
    -H "Content-Type: application/json" \
    -H "Authorization: Bearer ${ACCESS_TOKEN}" \
    -d "{\"email\":\"${STAFF_EMAIL}\",\"name\":\"${STAFF_NAME}\",\"role\":\"instructor_coach\"}")

STAFF_ID=$(echo "$INVITE_RES" | python3 -c "import sys,json; print(json.load(sys.stdin).get('data',{}).get('id',''))" 2>/dev/null || true)
if [[ -z "$STAFF_ID" ]]; then
    echo "ERROR: Invite failed. Response:"
    echo "$INVITE_RES" | python3 -m json.tool 2>/dev/null || echo "$INVITE_RES"
    exit 1
fi
echo "      Staff invited. ID: ${STAFF_ID}"

# ── Step 3: Fetch staff list (verify invite exists) ──
echo "[3/6] Fetching staff list..."
LIST_RES=$(curl -s -X GET "${BASE_URL}/org/staff" \
    -H "Authorization: Bearer ${ACCESS_TOKEN}")

STAFF_COUNT=$(echo "$LIST_RES" | python3 -c "import sys,json; print(len(json.load(sys.stdin).get('data',[])))" 2>/dev/null || echo "0")
echo "      Staff count: ${STAFF_COUNT}"

# ── Step 4: Extract invite token from DB (fallback: generate one manually for test) ──
echo "[4/6] Generating invite token..."
# For this test we need the actual invite token. In a real scenario it comes from email.
# We'll use a PHP script to generate one with the same secret.
INVITE_TOKEN=$(php -r "
require 'vendor/autoload.php';
use App\Services\JwtService;
echo JwtService::generateStaffInviteToken('${STAFF_ID}');
" 2>/dev/null || true)

if [[ -z "$INVITE_TOKEN" ]]; then
    echo "WARNING: Could not generate invite token via PHP. Skipping accept-invite test."
    echo "        (Make sure you're running from the backend directory)"
    INVITE_TOKEN=""
fi

if [[ -n "$INVITE_TOKEN" ]]; then
    # ── Step 5: Accept invite ──
    echo "[5/6] Accepting invite..."
    ACCEPT_RES=$(curl -s -X POST "${BASE_URL}/org/staff/accept-invite" \
        -H "Content-Type: application/json" \
        -d "{\"token\":\"${INVITE_TOKEN}\",\"name\":\"${STAFF_NAME}\",\"password\":\"${STAFF_PASSWORD}\"}")

    ACCEPT_OK=$(echo "$ACCEPT_RES" | python3 -c "import sys,json; d=json.load(sys.stdin); print('1' if d.get('success') else '0')" 2>/dev/null || echo "0")
    if [[ "$ACCEPT_OK" != "1" ]]; then
        echo "ERROR: Accept invite failed. Response:"
        echo "$ACCEPT_RES" | python3 -m json.tool 2>/dev/null || echo "$ACCEPT_RES"
        exit 1
    fi
    echo "      Invite accepted successfully."

    # ── Step 6: Login as staff ──
    echo "[6/6] Logging in as staff..."
    STAFF_LOGIN_RES=$(curl -s -X POST "${BASE_URL}/auth/staff/login" \
        -H "Content-Type: application/json" \
        -c /tmp/staff_cookies.txt \
        -d "{\"email\":\"${STAFF_EMAIL}\",\"password\":\"${STAFF_PASSWORD}\"}")

    STAFF_TOKEN=$(echo "$STAFF_LOGIN_RES" | python3 -c "import sys,json; print(json.load(sys.stdin).get('data',{}).get('access_token',''))" 2>/dev/null || true)
    if [[ -z "$STAFF_TOKEN" ]]; then
        echo "ERROR: Staff login failed. Response:"
        echo "$STAFF_LOGIN_RES" | python3 -m json.tool 2>/dev/null || echo "$STAFF_LOGIN_RES"
        exit 1
    fi
    echo "      Staff logged in successfully."
    echo ""
    echo "============================================"
    echo "ALL TESTS PASSED"
    echo "============================================"
    echo "Staff email: ${STAFF_EMAIL}"
    echo "Staff password: ${STAFF_PASSWORD}"
else
    echo ""
    echo "============================================"
    echo "PARTIAL TESTS PASSED (invite/list only)"
    echo "============================================"
fi
