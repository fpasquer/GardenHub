#!/usr/bin/env python3

import json
import sys


APPLICATION = [
    'gardenhub-nginx', 'gardenhub-api', 'gardenhub-worker',
    'gardenhub-consumer', 'gardenhub-scheduler',
]
WATERING = {
    'gardenhub-watering-monitor', 'gardenhub-watering-proposals',
    'gardenhub-watering-sim',
}
SIM_TOPIC = 'gardenhub/dev/watering/avocado'


def enabled(environment):
    return str(environment.get('WATERING_ENABLED', 'false')).lower() in ('true', '1')


def validate_mqtt(environment):
    if environment.get('APP_ENV') not in ('dev', 'prod'):
        raise ValueError('Watering requires APP_ENV=dev or prod.')
    for name in ('HOST', 'USERNAME', 'PASSWORD', 'TOPIC'):
        if not str(environment.get('WATERING_MQTT_' + name, '')).strip():
            raise ValueError('Watering requires explicit MQTT host, credentials and topic.')
    topic = environment['WATERING_MQTT_TOPIC']
    if topic != topic.strip() or any(char in topic for char in ('+', '#', '\0')) or topic.endswith('/set'):
        raise ValueError('Watering requires a concrete MQTT base topic.')
    if not 1 <= int(environment['WATERING_MQTT_PORT']) <= 65535:
        raise ValueError('Invalid watering MQTT port.')
    maximum = int(environment['WATERING_MAX_SECONDS'])
    if not 1 <= maximum <= 1800:
        raise ValueError('Invalid watering maximum duration.')
    if not maximum <= int(environment['WATERING_DAILY_SECONDS']) <= 86400:
        raise ValueError('Invalid watering daily budget.')
    if not 0 <= int(environment['WATERING_COOLDOWN_SECONDS']) <= 86400:
        raise ValueError('Invalid watering cooldown.')
    if not 1 <= int(environment['WATERING_PROPOSAL_DURATION_SECONDS']) <= maximum:
        raise ValueError('Invalid watering proposal duration.')


def validate_modes(services):
    environment = services['gardenhub-api']['environment']
    if not enabled(environment) or not (WATERING & services.keys()):
        return
    validate_mqtt(environment)
    simulator = 'gardenhub-watering-sim' in services
    topic = environment['WATERING_MQTT_TOPIC']
    if simulator and topic != SIM_TOPIC:
        raise ValueError('Simulator startup refused: the configured topic is not the simulator topic.')
    if simulator:
        for name in ('WATERING_SIM_MQTT_HOST', 'WATERING_SIM_USERNAME', 'WATERING_SIM_PASSWORD'):
            if not str(services['gardenhub-watering-sim']['environment'].get(name, '')).strip():
                raise ValueError('Simulator startup requires its dedicated credentials and host.')
    if 'gardenhub-watering-proposals' in services:
        if 'gardenhub-watering-monitor' not in services:
            raise ValueError('Select watering-hardware or watering-sim together with watering-proposals.')
        if not str(environment.get('TELEGRAM_BOT_TOKEN', '')).strip():
            raise ValueError('Watering proposals require a bot token.')
        user = str(environment.get('WATERING_TELEGRAM_USER_ID', ''))
        chat = str(environment.get('WATERING_TELEGRAM_CHAT_ID', ''))
        if not user.isascii() or not user.isdigit() or not chat.lstrip('-').isascii() or not chat.lstrip('-').isdigit():
            raise ValueError('Watering proposals require explicit Telegram user and chat ids.')


def select_services(model, mode):
    services = model['services']
    validate_modes(services)
    environment = services['gardenhub-api']['environment']
    if mode == 'hardware' and enabled(environment):
        if 'gardenhub-watering-sim' in services or environment['WATERING_MQTT_TOPIC'] == SIM_TOPIC:
            raise ValueError('Hardware startup requires an explicit hardware topic and no simulator profile.')
    selected = [name for name, service in services.items()
                if name not in WATERING or enabled(service.get('environment', {}))]
    if enabled(environment) and 'gardenhub-watering-sim' in services:
        selected.append('gardenhub-watering-sim')
    if mode == 'deploy':
        selected = APPLICATION + [name for name in selected if name in WATERING]
    return list(dict.fromkeys(selected))


def main():
    try:
        selected = select_services(json.load(sys.stdin), sys.argv[1])
    except (ValueError, KeyError, TypeError):
        print('Refusing startup/deployment: invalid watering configuration or profile combination.', file=sys.stderr)
        return 1
    print(' '.join(selected))
    return 0


if __name__ == '__main__':
    sys.exit(main())