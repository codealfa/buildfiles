#!/bin/bash
# ==============================================================================
# email_report.sh — Email the pending-work HTML report via msmtp
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EMAIL="nikosdion@mailbox.org"
SUBJECT="Pending Work Report — $(date +%Y-%m-%d)"

REPORT="$("${SCRIPT_DIR}/pending_work.sh" --html)"

/usr/bin/msmtp -t <<EOF
From: ${EMAIL}
To: ${EMAIL}
Subject: ${SUBJECT}
MIME-Version: 1.0
Content-Type: text/html; charset=UTF-8

${REPORT}
EOF
