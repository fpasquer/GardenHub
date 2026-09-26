#!/bin/sh
set -eu

# Grafana's own provisioning loader performs $VAR substitution on
# `settings` values and unconditionally coerces the result into a JSON
# number if it looks numeric (regardless of YAML quoting) - this crashes
# Telegram's `chatid`, which must stay a string. Render the alerting
# provisioning files ourselves first, so Grafana only ever sees an
# already-resolved value with no `$VAR` token left to expand.
#
# Grafana's contact-point file-provisioning schema has no secure-setting
# mechanism (verified against 12.1 source: ReceiverV1 in
# pkg/services/provisioning/alerting/contact_point_types.go only has a
# plain `Settings` field) - the bot token unavoidably lands in this
# rendered file in plaintext, so restrict it with filesystem permissions.

SRC=/etc/grafana/provisioning-templates/alerting
DEST=/etc/grafana/provisioning/alerting

: "${TELEGRAM_BOT_TOKEN:?TELEGRAM_BOT_TOKEN must be set}"
: "${TELEGRAM_CHAT_ID:?TELEGRAM_CHAT_ID must be set}"

mkdir -p "$DEST"
chmod 700 "$DEST"

for file in "$SRC"/*.yaml; do
    dest_file="$DEST/$(basename "$file")"
    sed \
        -e "s#\$TELEGRAM_CHAT_ID#${TELEGRAM_CHAT_ID}#g" \
        -e "s#\$TELEGRAM_BOT_TOKEN#${TELEGRAM_BOT_TOKEN}#g" \
        "$file" > "$dest_file"
    chmod 600 "$dest_file"
done

exec /run.sh
