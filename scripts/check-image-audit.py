#!/usr/bin/env python3

import json
from pathlib import Path
import sys


def blocking_findings(report):
    if not isinstance(report, dict) or report.get('SchemaVersion') != 2:
        raise ValueError('Invalid image audit report.')
    results = report.get('Results')
    if not isinstance(results, list) or not results:
        raise ValueError('Image audit has no scan results.')
    findings = []
    for result in results:
        for vulnerability in result.get('Vulnerabilities') or []:
            if vulnerability.get('Severity') in ('HIGH', 'CRITICAL') and vulnerability.get('FixedVersion'):
                findings.append((vulnerability['VulnerabilityID'], vulnerability['PkgName']))
    return findings


def main(paths):
    if not paths:
        print('Image audit reports are required.', file=sys.stderr)
        return 1
    try:
        findings = [finding for path in paths
                    for finding in blocking_findings(json.loads(Path(path).read_text()))]
    except (OSError, ValueError, KeyError, TypeError, AttributeError):
        print('Image audit report missing or invalid; refusing release.', file=sys.stderr)
        return 1
    for advisory, package in findings:
        print(f'Blocking fixable image vulnerability: {advisory} ({package})', file=sys.stderr)
    return int(bool(findings))


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))