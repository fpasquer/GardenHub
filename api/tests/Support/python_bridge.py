import contextlib
import copy
import importlib.util
import io
import json
import os
from pathlib import Path
import sys

sys.dont_write_bytecode = True
ROOT = Path(os.environ['GARDENHUB_ROOT'])


def load_subject(filename):
    specification = importlib.util.spec_from_file_location(
        'subject', ROOT / 'scripts' / filename)
    subject = importlib.util.module_from_spec(specification)
    specification.loader.exec_module(subject)
    return subject


def release(request):
    subject = load_subject('check-release-ci.py')
    responses = iter(request['responses'])
    return subject.check_release(
        request['repository'], request['sha'], request['branch'],
        lambda path: next(responses))


def unavailable_release(request):
    subject = load_subject('check-release-ci.py')
    subject.check_release = raise_transport_error
    sys.argv = ['check-release-ci.py', '--repository', 'owner/repo',
                '--sha', 'a' * 40]
    return subject.main()


def raise_transport_error(*arguments):
    raise OSError('secret-value')


def select_values(subject, request):
    results = []
    for value in request['values']:
        model = copy.deepcopy(request['model'])
        for service in model['services'].values():
            environment = service.setdefault('environment', {})
            if value is None:
                environment.pop('WATERING_ENABLED', None)
            else:
                environment['WATERING_ENABLED'] = value
        results.append(subject.select_services(model, request['mode']))
    return results


def dispatch(request):
    operation = request['operation']
    if operation == 'release':
        return release(request)
    if operation == 'release-unavailable':
        return unavailable_release(request)
    if operation == 'audit':
        return load_subject('check-image-audit.py').blocking_findings(
            request['report'])
    subject = load_subject('compose-services.py')
    if operation == 'enabled':
        return [subject.enabled(environment)
                for environment in request['environments']]
    if operation == 'selection':
        return subject.select_services(request['model'], request['mode'])
    if operation == 'selection-values':
        return select_values(subject, request)
    raise ValueError('Unsupported bridge operation.')


def main():
    request = json.load(sys.stdin)
    stdout, stderr = io.StringIO(), io.StringIO()
    with contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
        try:
            result = {'value': dispatch(request), 'exception': None}
        except Exception as error:
            result = {'value': None, 'exception': type(error).__name__,
                      'message': str(error)}
    result.update(stdout=stdout.getvalue(), stderr=stderr.getvalue())
    print(json.dumps(result))


if __name__ == '__main__':
    main()