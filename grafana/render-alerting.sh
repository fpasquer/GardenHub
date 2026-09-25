#!/bin/sh
set -eu

# Grafana re-parses substituted contact point settings as numbers. Render only
# the chat ID before Grafana starts; let Grafana resolve the bot token itself.
chat_id=${TELEGRAM_CHAT_ID:-}
case "$chat_id" in
    -*) digits=${chat_id#-} ;;
    *) digits=$chat_id ;;
esac
case "$digits" in
    ''|*[!0-9]*) echo 'TELEGRAM_CHAT_ID must be a numeric Telegram chat ID' >&2; exit 1 ;;
esac
if [ -z "${TELEGRAM_BOT_TOKEN:-}" ]; then
    echo 'TELEGRAM_BOT_TOKEN must be set for Grafana alerting' >&2
    exit 1
fi

source_dir=/etc/grafana/alerting-templates
target_dir=/etc/grafana/provisioning/alerting
umask 077
sed "s/__GARDENHUB_CHAT_ID__/$chat_id/g" "$source_dir/contact-points.yaml" > "$target_dir/contact-points.yaml"
cp "$source_dir/gardenhub-rules.yaml" "$target_dir/gardenhub-rules.yaml"
exec /run.sh
