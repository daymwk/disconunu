#!/usr/bin/env python3
"""Optional real-browser checks; requires Playwright and a Chromium executable.

Runs a copied application and temporary database, never the operator's site.
Usage: CHROME_BIN=/usr/bin/google-chrome-stable python3 tests/browser_e2e.py
"""
import base64
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.request

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
RESULTS = []


def check(name, condition):
    RESULTS.append({'check': name, 'passed': bool(condition)})
    print(('PASS ' if condition else 'FAIL ') + name, flush=True)
    if not condition:
        raise AssertionError(name)


def main():
    with tempfile.TemporaryDirectory(prefix='dn-browser-') as tmp:
        tmp = Path(tmp)
        app = tmp / 'app'
        shutil.copytree(ROOT, app, ignore=shutil.ignore_patterns('.git', 'var', '__pycache__'))
        env = os.environ | {'DN_DB_DATABASE': str(tmp / 'site.sqlite'), 'DN_DB_DRIVER': 'sqlite'}
        php = ['php']
        modules = subprocess.check_output(['php', '-m'], text=True)
        if 'pdo_sqlite' not in modules:
            php += ['-d', 'extension=pdo_sqlite']
        php += ['-d', 'opcache.enable_cli=0']
        subprocess.run(php + ['bin/disconunu', 'migrate'], cwd=app, env=env, check=True, capture_output=True)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        base = f'http://127.0.0.1:{port}'
        log = (tmp / 'server.log').open('w+')
        server = subprocess.Popen(php + ['-S', f'127.0.0.1:{port}', '-t', 'public', 'public/router.php'], cwd=app, env=env, stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    urllib.request.urlopen(base, timeout=.2).close()
                    break
                except OSError:
                    if server.poll() is not None:
                        raise RuntimeError('PHP server failed to start')
                    time.sleep(.05)
            else:
                raise RuntimeError('PHP server readiness timeout')
            with sync_playwright() as p:
                browser = p.chromium.launch(executable_path=os.environ.get('CHROME_BIN', '/usr/bin/google-chrome-stable'), headless=True)
                context = browser.new_context(viewport={'width': 1440, 'height': 1000})
                page = context.new_page()
                errors = []
                page.on('pageerror', lambda error: errors.append(str(error)))
                page.goto(base + '/admin/login')
                password = secrets.token_urlsafe(24)
                page.locator('[name=username]').fill('browser_owner')
                page.locator('[name=email]').fill('browser-owner@example.test')
                page.locator('[name=password]').fill(password)
                page.locator('form button[type=submit]').first.click()
                page.wait_for_url(base + '/admin')
                db = sqlite3.connect(tmp / 'site.sqlite')
                check('first browser-created account is superadmin', db.execute('select role from dn_user where username=?', ('browser_owner',)).fetchone() == ('superadmin',))
                db.close()
                subprocess.run(php + ['bin/seed-demo.php', '70'], cwd=app, env=env, check=True, capture_output=True)
                page.reload()
                check('admin list is paginated', 0 < page.locator('tbody tr').count() <= 20 and page.locator('.pagination a').count() > 0)
                view = page.get_by_role('link', name='View', exact=True).first
                with context.expect_page() as opened:
                    view.click()
                popup = opened.value
                popup.wait_for_load_state()
                check('View opens a separate published article tab', '/post/' in popup.url and page.url == base + '/admin')
                popup.close()
                # Create role-specific accounts through the real management form.
                for username, role in [('browser_admin', 'admin'), ('browser_writer', 'writer'), ('browser_writer2', 'writer')]:
                    page.goto(base + '/admin/users')
                    form = page.locator('form[action$="/admin/users/create"]')
                    # Open a collapsed create panel if the UI uses progressive disclosure.
                    details = form.locator('xpath=ancestor::details')
                    if details.count():
                        details.evaluate('(element) => element.open = true')
                    form.locator('[name=username]').fill(username)
                    form.locator('[name=email]').fill(username + '@example.test')
                    form.locator('[name=password]').fill(password)
                    form.locator('[name=role]').select_option(role)
                    form.locator('button[type=submit]').click()
                    page.wait_for_load_state()
                    check('account created through UI: ' + role, page.get_by_text(username, exact=True).count() > 0)
                # Layout assertions at narrow widths and a desktop width, including a large page count.
                for width in [320, 375, 768, 1440]:
                    page.set_viewport_size({'width': width, 'height': 900})
                    for route in ['/admin?per_page=1&page=35', '/admin/categories', '/admin/users', '/admin/settings', '/admin/post/new', '/?page=7']:
                        page.goto(base + route)
                        check(f'no viewport overflow: {width}px {route}', page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'))
                page.goto(base + '/search?q=unmatched_' + secrets.token_hex(8))
                check('short-page footer reaches viewport bottom', page.evaluate('Math.abs(document.querySelector(".site-footer").getBoundingClientRect().bottom - innerHeight) <= 2'))
                page.set_viewport_size({'width': 1440, 'height': 1000})
                page.goto(base + '/admin/post/new')
                page.locator('[name=title]').fill('Browser draft preview')
                page.locator('[name=content]').fill('<p>Draft preview content.</p>')
                page.locator('[name=status]').select_option('draft')
                page.locator('button[name=save_action][value=continue]').click()
                page.wait_for_url('**/edit')
                check('save and continue retains editor', page.locator('[name=title]').input_value() == 'Browser draft preview')
                preview_link = page.get_by_role('link', name='Preview', exact=True)
                with context.expect_page() as opened:
                    preview_link.click()
                popup = opened.value
                popup.wait_for_load_state()
                check('draft preview is readable without publishing', 'Draft preview content.' in popup.inner_text('body'))
                popup.close()
                page.goto(base + '/admin/settings')
                with page.expect_download() as download:
                    page.get_by_role('link', name='Download starter ZIP', exact=True).click()
                check('starter theme ZIP downloads', download.value.suggested_filename.endswith('.zip'))
                check('no JavaScript runtime errors', errors == [])
                screenshot_dir = os.environ.get('DN_SCREENSHOTS')
                if screenshot_dir:
                    output = Path(screenshot_dir)
                    output.mkdir(parents=True, exist_ok=True)
                    for label, route in [('posts', '/admin'), ('settings', '/admin/settings'), ('editor', '/admin/post/new')]:
                        page.goto(base + route)
                        page.screenshot(path=str(output / f'{label}.png'), full_page=True)
                browser.close()
            log.flush()
            log.seek(0)
            text = log.read()
            check('PHP server emitted no warnings or fatal errors', not any(word in text for word in ['PHP Warning', 'PHP Fatal', 'Uncaught', 'PHP Deprecated']))
        finally:
            server.terminate()
            server.wait(timeout=10)
            log.close()
    print(json.dumps({'checks': len(RESULTS), 'passed': sum(x['passed'] for x in RESULTS)}, indent=2))


if __name__ == '__main__':
    main()
