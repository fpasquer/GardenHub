#!/bin/sh
set -eu

# Grafana's own provisioning loader performs $VAR substitution on
# `settings` values and unconditionally coerces the result into a JSON
# number if it looks numeric (regardless of YAML quoting) - this crashes
# Telegram's `chatid`, which must stay a string. Render the alerting
# provisioning files ourselves first, so Grafana only ever sees an
# already-resolved value with no `$VAR` token left to expand.

SRC=/etc/grafana/provisioning-templates/alerting
DEST=/etc/grafana/provisioning/alerting

mkdir -p "$DEST"
for file in "$SRC"/*.yaml; do
    sed \
        -e "s#\$TELEGRAM_CHAT_ID#${TELEGRAM_CHAT_ID:-}#g" \
        -e "s#\$TELEGRAM_BOT_TOKEN#${TELEGRAM_BOT_TOKEN:-}#g" \
        "$file" > "$DEST/$(basename "$file")"
done

exec /run.sh
