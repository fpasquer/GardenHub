#!/usr/bin/env python3

import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

sys.dont_write_bytecode = True

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('selection', ROOT / 'scripts/compose-services.py')
SELECTION = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(SELECTION)
APPLICATION = [name for name in SELECTION.APPLICATION if name != 'gardenhub-nginx'] + [
    'gardenhub-watering-monitor', 'gardenhub-watering-proposals',
]
SYNTHETIC = {
    'PATH': os.environ['PATH'], 'HOME': os.environ.get('HOME', '/tmp'),
    'BIND_ADDRESS': '127.0.0.1', 'MYSQL_DATABASE': 'synthetic',
    'MYSQL_USER': 'synthetic', 'MYSQL_PASSWORD': 'synthetic',
    'MYSQL_ROOT_PASSWORD': 'synthetic', 'MQTT_HOST': 'synthetic-broker',
    'MQTT_CLIENT_ID': 'synthetic', 'GRAFANA_MYSQL_USER': 'synthetic',
    'GRAFANA_MYSQL_PASSWORD': 'synthetic',
}
BOOLEAN_VALUES = [
    ('true', True), ('yes', True), ('on', True), ('false', False),
    ('no', False), ('off', False), ('', False), ('0', False), ('-0', False),
    ('+0', False), ('0.0', False), ('-0.00e10', False), ('0e999999', False),
    ('1', True), ('2', True), ('-2', True), ('+2', True), ('01', True),
    ('0.5', True), ('-.5', True), ('1.', True), ('1.e2', True), ('1e3', True),
    ('-1e-3', True), ('1e+0002', True), ('1e-323', True),
    ('2e-324', False), ('3e-324', True), ('1e-400', False), ('1e309', False),
    ('1.7976931348623158e308', True), ('1.7976931348623159e308', False),
    ('unknown', False), ('enabled', False), ('nan', False), ('inf', False),
    ('0x10', False), ('1_000', False), ('1,000', False), ('1e 2', False),
    ('1 2', False), ('--2', False), ('.', False), ('\x001\x00', False),
    ('\fyes\f', False), ('\f2\f', False), ('\u00a0yes\u00a0', False),
    ('\u00a02\u00a0', False), ('\x1c2\x1c', False),
]
BOOLEAN_FIXTURES = [(None, False)] + [
    (whitespace + spelling + whitespace, expected)
    for value, expected in BOOLEAN_VALUES
    for spelling in dict.fromkeys((value, value.upper(), value.title()))
    for whitespace in ('', ' ', '\t', '\n', '\r', '\v')
]


def set_enabled(model, value):
    for service in model['services'].values():
        environment = service.get('environment', {})
        if value is None:
            environment.pop('WATERING_ENABLED', None)
        else:
            environment['WATERING_ENABLED'] = value


def render(development=False, profiles=(), overrides=None):
    command = ['docker', 'compose', '--env-file', '/dev/null', '-f', 'compose.yaml']
    if development:
        command += ['-f', 'compose-dev.yaml']
    for profile in profiles:
        command += ['--profile', profile]
    command += ['config', '--format', 'json']
    environment = dict(SYNTHETIC, **(overrides or {}))
    result = subprocess.run(command, cwd=ROOT, env=environment, capture_output=True, text=True, check=True)
    return json.loads(result.stdout)


def enabled_settings():
    return {
        'WATERING_ENABLED': 'true', 'WATERING_MQTT_HOST': 'synthetic',
        'WATERING_MQTT_TOPIC': 'isolated/actuator',
        'WATERING_MQTT_USERNAME': 'synthetic', 'WATERING_MQTT_PASSWORD': 'synthetic',
        'TELEGRAM_BOT_TOKEN': 'synthetic', 'WATERING_TELEGRAM_USER_ID': '123',
        'WATERING_TELEGRAM_CHAT_ID': '456',
    }


def fake_docker(arguments):
    with open(os.environ['FAKE_LOG'], 'a') as log:
        log.write(json.dumps(arguments) + '\n')
    if arguments[0] == 'inspect':
        service = arguments[-1]
        targeted = service == os.environ.get('FAKE_STATE_SERVICE')
        if '.State.Restarting' in arguments[2]:
            print('true' if targeted and os.environ.get('FAKE_RESTARTING') else 'false')
        elif '.State.Status' in arguments[2]:
            print(os.environ.get('FAKE_STATE', 'running') if targeted else 'running')
        else:
            print('healthy')
        return 0
    if 'config' in arguments:
        print(Path(os.environ['FAKE_MODEL']).read_text())
    elif 'ps' in arguments:
        if arguments[-1] != os.environ.get('FAKE_MISSING'):
            print(arguments[-1])
    elif 'gardenhub:watering:check' in arguments:
        counter = Path(os.environ['FAKE_COUNTER'])
        count = int(counter.read_text()) + 1 if counter.exists() else 1
        counter.write_text(str(count))
        if str(count) == os.environ.get('FAKE_FAIL_GUARD'):
            return 1
    elif 'doctrine:migrations:migrate' in arguments and os.environ.get('FAKE_FAIL_MIGRATION'):
        return 1
    return 0


class ComposeTests(unittest.TestCase):
    def test_boolean_fixtures_match_native_symfony(self):
        code = r'''
require 'vendor/autoload.php';
$processor = new Symfony\Component\DependencyInjection\EnvVarProcessor(
    new Symfony\Component\DependencyInjection\Container()
);
$values = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
echo json_encode(array_map(
    fn ($value) => $processor->getEnv(
        'bool', 'WATERING_ENABLED', fn ($name) => $value ?? 'false'
    ),
    $values
), JSON_THROW_ON_ERROR);
'''
        result = subprocess.run([
            'docker', 'run', '--rm', '-i', '--network', 'none',
            '-v', str(ROOT / 'api') + ':/app:ro', '-w', '/app',
            '--entrypoint', 'php', 'gardenhub-api', '-r', code,
        ], input=json.dumps([value for value, expected in BOOLEAN_FIXTURES]),
            capture_output=True, text=True, check=True)
        reference = json.loads(result.stdout)
        self.assertEqual(len(reference), len(BOOLEAN_FIXTURES))
        for (value, expected), native in zip(BOOLEAN_FIXTURES, reference):
            with self.subTest(value=value):
                environment = {} if value is None else {'WATERING_ENABLED': value}
                self.assertEqual(native, expected)
                self.assertEqual(SELECTION.enabled(environment), native)

    def test_boolean_service_selection(self):
        model = render(profiles=('watering-hardware', 'watering-proposals'), overrides=enabled_settings())
        for value, expected in BOOLEAN_FIXTURES:
            set_enabled(model, value)
            for mode in ('hardware', 'deploy'):
                with self.subTest(value=value, mode=mode):
                    selected = set(SELECTION.select_services(model, mode))
                    self.assertEqual('gardenhub-watering-monitor' in selected, expected)
                    self.assertEqual('gardenhub-watering-proposals' in selected, expected)
                    if mode == 'deploy':
                        self.assertTrue(set(SELECTION.APPLICATION) <= selected)

    def test_image_backed_base_and_shared_configuration(self):
        model = render(profiles=('*',), overrides={'WATERING_DEV_USERNAME': 'legacy', 'WATERING_DEV_PASSWORD': 'legacy'})
        self.assertEqual(model['services']['gardenhub-api']['build']['target'], 'production')
        self.assertNotIn('gardenhub-watering-sim', model['services'])
        self.assertNotIn('gardenhub-adminer', model['services'])
        for name in APPLICATION:
            service = model['services'][name]
            self.assertEqual(service['environment']['APP_ENV'], 'prod')
            self.assertEqual(service['environment']['APP_DEBUG'], '0')
            self.assertFalse(service.get('volumes'))
            self.assertFalse(service.get('extra_hosts'))
            for key in ('WATERING_MQTT_USERNAME', 'WATERING_MQTT_PASSWORD', 'WATERING_MQTT_TOPIC'):
                self.assertFalse(service['environment'][key], 'Production fallback detected.')
            self.assertIn('WATERING_PROPOSAL_DURATION_SECONDS', service['environment'])
        self.assertEqual(model['services']['gardenhub-watering-monitor']['profiles'], ['watering-hardware'])
        self.assertEqual(model['services']['gardenhub-watering-proposals']['profiles'], ['watering-proposals'])

    def test_development_overrides_and_neutral_priority(self):
        legacy = {'WATERING_DEV_USERNAME': 'legacy', 'WATERING_DEV_PASSWORD': 'legacy'}
        model = render(True, ('*',), legacy)
        self.assertEqual(model['services']['gardenhub-api']['build']['target'], 'development')
        for name in APPLICATION:
            service = model['services'][name]
            self.assertEqual(service['environment']['APP_ENV'], 'dev')
            self.assertEqual(service['environment']['APP_DEBUG'], '1')
            self.assertTrue(service['volumes'])
            self.assertTrue(service['extra_hosts'])
            self.assertTrue(service['environment']['WATERING_MQTT_USERNAME'] == 'legacy')
            self.assertEqual(service['environment']['WATERING_MQTT_TOPIC'], SELECTION.SIM_TOPIC)
        self.assertEqual(model['services']['gardenhub-watering-monitor']['profiles'], ['watering-sim', 'watering-hardware'])
        model = render(True, ('*',), dict(legacy, WATERING_MQTT_USERNAME='neutral', WATERING_MQTT_PASSWORD='neutral'))
        self.assertTrue(all(model['services'][name]['environment']['WATERING_MQTT_USERNAME'] == 'neutral' for name in APPLICATION))
        self.assertTrue(all(model['services'][name]['environment']['WATERING_MQTT_PASSWORD'] == 'neutral' for name in APPLICATION))

    def test_profiles_enablement_and_defaults(self):
        self.assertNotIn('gardenhub-watering-monitor', render()['services'])
        self.assertNotIn('gardenhub-watering-sim', render(True, ('watering-hardware',))['services'])
        self.assertIn('gardenhub-watering-monitor', render(True, ('watering-sim',))['services'])
        model = render(profiles=('watering-hardware', 'watering-proposals'))
        self.assertFalse(set(SELECTION.select_services(model, 'deploy')) & SELECTION.WATERING)
        model = render(profiles=('watering-hardware', 'watering-proposals'), overrides=enabled_settings())
        self.assertTrue(set(APPLICATION) <= set(SELECTION.select_services(model, 'deploy')))
        environment = model['services']['gardenhub-api']['environment']
        for key, value in [('MAX_SECONDS', '30'), ('DAILY_SECONDS', '120'), ('COOLDOWN_SECONDS', '60'), ('PROPOSAL_DURATION_SECONDS', '3')]:
            self.assertEqual(environment['WATERING_' + key], value)

    def test_invalid_modes_and_configuration(self):
        with self.assertRaises(ValueError):
            SELECTION.select_services(render(True, ('watering-sim',), enabled_settings()), 'start')
        with self.assertRaises(ValueError):
            SELECTION.select_services(render(profiles=('watering-proposals',), overrides=enabled_settings()), 'deploy')
        for name in ('WATERING_MQTT_USERNAME', 'WATERING_MQTT_PASSWORD', 'WATERING_MQTT_TOPIC'):
            settings = dict(enabled_settings(), **{name: ''})
            with self.assertRaises(ValueError):
                SELECTION.select_services(render(profiles=('watering-hardware',), overrides=settings), 'deploy')


class DeploymentTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='gardenhub-deploy-tests-')
        self.directory = Path(self.temporary.name)
        self.model = render(profiles=('watering-hardware', 'watering-proposals'), overrides=enabled_settings())
        launcher = '#!/bin/sh\nexec python3 ' + str(Path(__file__).resolve()) + ' --fake "$@"\n'
        for command in ('docker', 'curl'):
            executable = self.directory / command
            executable.write_text(launcher)
            executable.chmod(0o755)
        self.environment = dict(SYNTHETIC, PATH=str(self.directory) + ':' + os.environ['PATH'],
            FAKE_MODEL=str(self.directory / 'model.json'), FAKE_LOG=str(self.directory / 'commands.jsonl'),
            FAKE_COUNTER=str(self.directory / 'counter'))

    def tearDown(self):
        self.temporary.cleanup()

    def execute(self, target, **overrides):
        Path(self.environment['FAKE_MODEL']).write_text(json.dumps(self.model))
        result = subprocess.run(['make', '--no-print-directory', target,
            'COMPOSE=docker compose --env-file /dev/null', 'DEPLOY_HEALTH_TIMEOUT=0'],
            cwd=ROOT, env=dict(self.environment, **overrides), capture_output=True, text=True)
        logfile = Path(self.environment['FAKE_LOG'])
        commands = [json.loads(line) for line in logfile.read_text().splitlines()] if logfile.exists() else []
        return result.returncode, commands

    def test_enabled_services_recreated_and_disabled_skipped(self):
        set_enabled(self.model, 'yes')
        status, commands = self.execute('deploy-up')
        self.assertEqual(status, 0)
        startup = next(command for command in commands if 'up' in command)
        self.assertIn('--force-recreate', startup)
        self.assertIn('gardenhub-watering-monitor', startup)
        self.assertIn('gardenhub-watering-proposals', startup)
        self.model['services']['gardenhub-api']['environment']['WATERING_ENABLED'] = 'false'
        for name in SELECTION.WATERING & self.model['services'].keys():
            self.model['services'][name]['environment']['WATERING_ENABLED'] = 'false'
        Path(self.environment['FAKE_LOG']).unlink()
        status, commands = self.execute('deploy-up')
        self.assertEqual(status, 0)
        startup = next(command for command in commands if 'up' in command)
        self.assertFalse(set(startup) & SELECTION.WATERING)
        self.assertTrue(any('stop' in command and 'gardenhub-watering-monitor' in command for command in commands))

    def test_both_guards_preserve_monitor_on_refusal(self):
        status, commands = self.execute('deploy-stop', FAKE_FAIL_GUARD='1')
        self.assertNotEqual(status, 0)
        self.assertFalse(any('stop' in command for command in commands))
        Path(self.environment['FAKE_LOG']).unlink()
        Path(self.environment['FAKE_COUNTER']).unlink()
        status, commands = self.execute('deploy-stop', FAKE_FAIL_GUARD='2')
        self.assertNotEqual(status, 0)
        stops = [command for command in commands if 'stop' in command]
        self.assertEqual(len(stops), 1)
        self.assertIn('gardenhub-api', stops[0])
        self.assertNotIn('gardenhub-watering-monitor', stops[0])

    def test_health_requires_running_nonrestarting_services(self):
        for state, restarting in [('exited', ''), ('restarting', ''), ('running', '1')]:
            status, commands = self.execute('deploy-health', FAKE_STATE_SERVICE='gardenhub-watering-monitor',
                FAKE_STATE=state, FAKE_RESTARTING=restarting)
            self.assertNotEqual(status, 0)
            self.assertFalse(any(command[0] == 'curl' for command in commands))
        status, commands = self.execute('deploy-health', FAKE_MISSING='gardenhub-watering-proposals')
        self.assertNotEqual(status, 0)

    def test_boolean_deployment_health_selection(self):
        fixtures = [(None, False)] + BOOLEAN_VALUES + [(' YES\t', True), (' Off\n', False)]
        for value, expected in fixtures:
            with self.subTest(value=value):
                set_enabled(self.model, value)
                logfile = Path(self.environment['FAKE_LOG'])
                if logfile.exists():
                    logfile.unlink()
                status, commands = self.execute('deploy-health')
                self.assertEqual(status, 0)
                inspected = {command[-1] for command in commands if command[0] == 'inspect'}
                self.assertEqual('gardenhub-watering-monitor' in inspected, expected)
                self.assertEqual('gardenhub-watering-proposals' in inspected, expected)

    def test_disabled_and_inactive_health_services(self):
        for name in SELECTION.WATERING & self.model['services'].keys():
            self.model['services'][name]['environment']['WATERING_ENABLED'] = 'false'
        self.model['services']['gardenhub-api']['environment']['WATERING_ENABLED'] = 'false'
        status, commands = self.execute('deploy-health', FAKE_MISSING='gardenhub-watering-monitor')
        self.assertEqual(status, 0)
        self.assertFalse(any('inspect' in command and command[-1] in SELECTION.WATERING for command in commands))
        self.model = render(overrides=enabled_settings())
        status, commands = self.execute('deploy-health', FAKE_MISSING='gardenhub-watering-proposals')
        self.assertEqual(status, 0)
        self.model = render(overrides={'WATERING_ENABLED': 'true'})
        status, commands = self.execute('deploy-health', FAKE_MISSING='gardenhub-watering-monitor')
        self.assertEqual(status, 0)

    def test_start_migrates_before_application_start(self):
        status, commands = self.execute('start')
        self.assertEqual(status, 0)
        migration = next(index for index, command in enumerate(commands) if 'doctrine:migrations:migrate' in command)
        startup = next(index for index, command in enumerate(commands) if 'up' in command and 'gardenhub-api' in command)
        self.assertLess(migration, startup)
        Path(self.environment['FAKE_LOG']).unlink()
        status, commands = self.execute('start', FAKE_FAIL_MIGRATION='1')
        self.assertNotEqual(status, 0)
        self.assertFalse(any('up' in command and 'gardenhub-api' in command for command in commands))

    def test_simulator_hardware_topic_refused_before_build(self):
        self.model = render(True, ('watering-sim',), enabled_settings())
        status, commands = self.execute('dev-sim')
        self.assertNotEqual(status, 0)
        self.assertFalse(any('build' in command or 'up' in command for command in commands))


if __name__ == '__main__':
    if len(sys.argv) > 1 and sys.argv[1] == '--fake':
        arguments = sys.argv[2:]
        if arguments and arguments[0] == 'compose':
            sys.exit(fake_docker(arguments))
        if arguments and arguments[0] == 'inspect':
            sys.exit(fake_docker(arguments))
        with open(os.environ['FAKE_LOG'], 'a') as log:
            log.write(json.dumps(['curl'] + arguments) + '\n')
        print('{"status":"ok"}')
        sys.exit(0)
    unittest.main()