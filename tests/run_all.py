#!/usr/bin/env python3
"""Run the dependency-free regression suites; --browser enables Playwright checks."""
import argparse
import os
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--browser', action='store_true', help='Require Playwright and Chromium; run browser checks too')
args = parser.parse_args()
php = ['php']
modules = subprocess.check_output(php + ['-m'], text=True)
if 'pdo_sqlite' not in modules:
    php += ['-d', 'extension=pdo_sqlite']
env = os.environ.copy()
env.pop('BASE', None)
env['DN_DB_DRIVER'] = 'sqlite'
env['DN_UI_BROWSER'] = '1' if args.browser else '0'
commands = [
    php + ['tests/run.php'],
    php + ['tests/security_regression.php'],
    php + ['tests/theme_services.php'],
    ['bash', 'tests/smoke_http.sh'],
    [sys.executable, 'tests/accounts.py'],
    [sys.executable, 'tests/backend_http.py'],
    [sys.executable, 'tests/admin_ui.py'],
    [sys.executable, 'tests/starter_theme.py'],
]
if args.browser:
    commands.append([sys.executable, 'tests/browser_e2e.py'])
for command in commands:
    print('\n>>> ' + ' '.join(command), flush=True)
    result = subprocess.run(command, cwd=ROOT, env=env)
    if result.returncode:
        sys.exit(result.returncode)
print(f'\nAll {len(commands)} suites passed.', flush=True)
