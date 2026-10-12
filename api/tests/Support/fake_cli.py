import json
import os
from pathlib import Path
import sys


def docker(arguments):
    if arguments[0] == 'inspect':
        targeted = arguments[-1] == os.environ.get('FAKE_STATE_SERVICE')
        if '.State.Restarting' in arguments[2]:
            print('true' if targeted and os.environ.get('FAKE_RESTARTING')
                  else 'false')
        elif '.State.Status' in arguments[2]:
            print(os.environ.get('FAKE_STATE', 'running') if targeted
                  else 'running')
        else:
            print('healthy')
    elif 'config' in arguments:
        print(Path(os.environ['FAKE_MODEL']).read_text())
    elif 'ps' in arguments:
        if arguments[-1] != os.environ.get('FAKE_MISSING'):
            print(arguments[-1])
    elif 'gardenhub:watering:check' in arguments:
        counter = Path(os.environ['FAKE_COUNTER'])
        count = int(counter.read_text()) + 1
        counter.write_text(str(count))
        if str(count) == os.environ.get('FAKE_FAIL_GUARD'):
            return 1
    elif ('doctrine:migrations:migrate' in arguments
          and os.environ.get('FAKE_FAIL_MIGRATION')):
        return 1
    return 0


def main():
    name, arguments = sys.argv[1], sys.argv[2:]
    recorded = ['curl'] + arguments if name == 'curl' else arguments
    with open(os.environ['FAKE_LOG'], 'a') as logfile:
        logfile.write(json.dumps(recorded) + '\n')
    if name == 'curl':
        print('{"status":"ok"}')
        return 0
    return docker(arguments)


if __name__ == '__main__':
    sys.exit(main())