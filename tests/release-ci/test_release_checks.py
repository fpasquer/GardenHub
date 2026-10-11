import copy
import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch
import sys
import os
import subprocess
import tempfile

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]


def load_module(name, filename):
    spec = importlib.util.spec_from_file_location(name, ROOT / 'scripts' / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


RELEASE = load_module('release', 'check-release-ci.py')
AUDIT = load_module('audit', 'check-image-audit.py')
SHA = 'a' * 40
RUN = {'id': 42, 'run_number': 7, 'run_attempt': 1,
       'head_sha': SHA, 'head_branch': 'master', 'event': 'push',
       'path': '.github/workflows/ci.yml', 'status': 'completed', 'conclusion': 'success'}
JOBS = [{'name': name, 'status': 'completed', 'conclusion': 'success'}
        for name in ['Build and integration tests', 'CI Ready']]


class ReleaseTests(unittest.TestCase):
    def check(self, runs=None, current=None, jobs=None, count=None):
        runs = [copy.deepcopy(RUN)] if runs is None else runs
        current = copy.deepcopy(RUN) if current is None else current
        jobs = copy.deepcopy(JOBS) if jobs is None else jobs
        responses = iter([
            {'workflow_runs': runs, 'total_count': len(runs) if count is None else count},
            current, {'jobs': jobs, 'total_count': len(jobs)},
        ])
        return RELEASE.check_release('owner/repo', SHA, 'master', lambda path: next(responses))

    def test_exact_commit_success(self):
        self.assertEqual(self.check(), 42)

    def test_wrong_identity_and_unsuccessful_states(self):
        changes = [('head_sha', 'b' * 40), ('head_branch', 'dev'), ('event', 'pull_request'),
                   ('path', '.github/workflows/other.yml'), ('status', 'queued'),
                   ('status', 'in_progress'), ('conclusion', 'failure'),
                   ('conclusion', 'cancelled'), ('conclusion', 'timed_out'), ('conclusion', None)]
        for key, value in changes:
            with self.subTest(key=key, value=value), self.assertRaises(ValueError):
                self.check(runs=[dict(RUN, **{key: value})])

    def test_missing_and_paginated_results(self):
        for runs, count in [([], 0), ([RUN], 101)]:
            with self.assertRaises(ValueError):
                self.check(runs=runs, count=count)

    def test_newer_failed_run_wins(self):
        newer = dict(RUN, id=43, run_number=8, conclusion='failure')
        with self.assertRaises(ValueError):
            self.check(runs=[RUN, newer])

    def test_failed_rerun_wins(self):
        with self.assertRaises(ValueError):
            self.check(current=dict(RUN, run_attempt=2, conclusion='failure'))

    def test_missing_duplicate_and_skipped_gate(self):
        for jobs in [[], [JOBS[0]], JOBS + [JOBS[1]],
                     [JOBS[0], dict(JOBS[1], conclusion='skipped')],
                     [dict(JOBS[0], conclusion='failure'), JOBS[1]]]:
            with self.assertRaises(ValueError):
                self.check(jobs=jobs)

    def test_invalid_repository_and_sha(self):
        for repo, sha in [('https://example.org/repo', SHA), ('owner/repo', 'short')]:
            with self.assertRaises(ValueError):
                RELEASE.check_release(repo, sha, 'master', lambda path: {})

    def test_transport_errors_fail_closed_without_secrets(self):
        with patch.object(sys, 'argv', ['check-release-ci.py', '--repository', 'owner/repo', '--sha', SHA]), \
                patch.object(RELEASE, 'check_release', side_effect=OSError('secret-value')), \
                patch.object(sys, 'stderr') as stderr:
            self.assertEqual(RELEASE.main(), 1)
            self.assertNotIn('secret-value', str(stderr.write.call_args_list))


class ImageAuditTests(unittest.TestCase):
    def test_policy(self):
        vulnerabilities = [
            {'Severity': severity, 'FixedVersion': fix,
             'VulnerabilityID': 'CVE-test', 'PkgName': 'package'}
            for severity, fix in [('HIGH', '2'), ('CRITICAL', '3'), ('HIGH', ''), ('LOW', '2')]
        ]
        report = {'SchemaVersion': 2, 'Results': [{'Vulnerabilities': vulnerabilities}]}
        self.assertEqual(len(AUDIT.blocking_findings(report)), 2)

    def test_invalid_empty_reports_fail(self):
        for report in [{}, {'SchemaVersion': 2, 'Results': []}, None]:
            with self.assertRaises(ValueError):
                AUDIT.blocking_findings(report)


class DeploymentOrderTests(unittest.TestCase):
    def test_ci_refusal_precedes_build_and_stop(self):
        with tempfile.TemporaryDirectory(prefix='gardenhub-release-test-') as directory:
            root = Path(directory)
            (root / '.git').mkdir()
            (root / '.env').touch()
            (root / 'Makefile').write_text((ROOT / 'Makefile').read_text())
            (root / 'scripts').mkdir()
            (root / 'scripts' / 'check-release-ci.py').write_text('raise SystemExit(1)\n')
            tools = root / 'tools'
            tools.mkdir()
            git = tools / 'git'
            git.write_text('#!/bin/sh\ncase "$*" in\n'
                           '*--git-path*) echo .git/gardenhub-deploy.lock;;\n'
                           '*--abbrev-ref*) echo master;;\n'
                           '*rev-parse*) printf "%040d\\n" 0;;\nesac\n')
            git.chmod(0o755)
            docker = tools / 'docker'
            docker.write_text('#!/bin/sh\nprintf "%s\\n" "$*" >> "$COMMAND_LOG"\n')
            docker.chmod(0o755)
            log = root / 'commands'
            environment = dict(os.environ, PATH=str(tools) + ':' + os.environ['PATH'],
                               COMMAND_LOG=str(log))
            result = subprocess.run(['make', '--no-print-directory', 'deploy'], cwd=root,
                                    env=environment, capture_output=True, text=True)
            self.assertNotEqual(result.returncode, 0)
            commands = log.read_text().splitlines()
            self.assertTrue(commands)
            for command in commands:
                self.assertNotIn(' build ', ' ' + command + ' ')
                self.assertNotIn(' stop ', ' ' + command + ' ')
                self.assertNotIn('doctrine:migrations:migrate', command)


if __name__ == '__main__':
    unittest.main()