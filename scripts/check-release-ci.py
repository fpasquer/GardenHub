#!/usr/bin/env python3

import argparse
import json
import os
import re
import sys
from urllib.parse import quote, urlencode
from urllib.request import HTTPRedirectHandler, Request, build_opener


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, new_url):
        raise ValueError('GitHub API redirects are not permitted.')


def fetch_json(path):
    headers = {'Accept': 'application/vnd.github+json',
               'X-GitHub-Api-Version': '2022-11-28', 'User-Agent': 'GardenHub-release-check'}
    token = os.environ.get('GARDENHUB_CI_TOKEN', '')
    if token:
        headers['Authorization'] = 'Bearer ' + token
    request = Request('https://api.github.com' + path, headers=headers)
    with build_opener(NoRedirect()).open(request, timeout=15) as response:
        return json.load(response)


def require_success(run, sha, branch, workflow):
    if not isinstance(run, dict):
        raise ValueError('Invalid workflow run.')
    expected = {'head_sha': sha, 'head_branch': branch, 'event': 'push', 'path': workflow}
    if any(run.get(key) != value for key, value in expected.items()):
        raise ValueError('Workflow identity does not match the approved commit.')
    if run.get('status') != 'completed' or run.get('conclusion') != 'success':
        raise ValueError('Latest CI run is missing, incomplete or unsuccessful.')


def check_release(repository, sha, branch, fetch=fetch_json):
    if not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', repository):
        raise ValueError('Set GARDENHUB_GITHUB_REPOSITORY to owner/repository.')
    if not re.fullmatch(r'[a-f0-9]{40}', sha) or not branch:
        raise ValueError('A full commit SHA and branch are required.')
    workflow = '.github/workflows/ci.yml'
    base = '/repos/' + repository + '/actions'
    query = urlencode({'head_sha': sha, 'branch': branch, 'event': 'push', 'per_page': 100})
    response = fetch(base + '/workflows/' + quote('ci.yml', safe='') + '/runs?' + query)
    runs = response.get('workflow_runs')
    total = response.get('total_count')
    if not isinstance(runs, list) or not runs or not isinstance(total, int) or total != len(runs):
        raise ValueError('Missing or ambiguous CI runs; refusing release.')
    if any(not isinstance(run.get('run_number'), int) or not isinstance(run.get('id'), int)
           for run in runs):
        raise ValueError('Invalid workflow run metadata.')
    latest = max(runs, key=lambda run: run['run_number'])
    require_success(latest, sha, branch, workflow)
    current = fetch(base + '/runs/' + str(latest['id']))
    require_success(current, sha, branch, workflow)
    if current.get('id') != latest['id'] or current.get('run_number') != latest['run_number']:
        raise ValueError('Workflow changed during verification.')
    attempt = current.get('run_attempt')
    if not isinstance(attempt, int) or attempt < 1:
        raise ValueError('Invalid workflow attempt.')
    jobs = fetch(base + f'/runs/{current["id"]}/attempts/{attempt}/jobs?per_page=100')
    checks = jobs.get('jobs')
    if not isinstance(checks, list) or jobs.get('total_count') != len(checks):
        raise ValueError('Missing or ambiguous workflow jobs.')
    gates = [job for job in checks if job.get('name') == 'CI Ready']
    if len(gates) != 1 or gates[0].get('status') != 'completed' or gates[0].get('conclusion') != 'success':
        raise ValueError('CI Ready did not succeed for the latest attempt.')
    if any(job.get('status') != 'completed' or job.get('conclusion') != 'success' for job in checks):
        raise ValueError('A required workflow job did not succeed.')
    return current['id']


def main():
    parser = argparse.ArgumentParser(description='Require exact-commit master-push CI before deployment.')
    parser.add_argument('--repository', default=os.environ.get('GARDENHUB_GITHUB_REPOSITORY', ''))
    parser.add_argument('--sha', required=True)
    parser.add_argument('--branch', default='master')
    arguments = parser.parse_args()
    try:
        run_id = check_release(arguments.repository, arguments.sha, arguments.branch)
    except ValueError as error:
        print('Release refused: ' + str(error), file=sys.stderr)
        return 1
    except Exception:
        print('Release refused: GitHub API unavailable or response invalid. Check access and retry.', file=sys.stderr)
        return 1
    print(f'CI Ready verified for {arguments.sha} (run {run_id}).')
    return 0


if __name__ == '__main__':
    sys.exit(main())