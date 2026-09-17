#!/usr/bin/env python3
"""Validate the distributable starter theme without modifying installed themes."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[1]


class StarterThemeTests(unittest.TestCase):
    def test_starter_can_be_packaged_and_rendered(self):
        source = ROOT / 'examples' / 'starter-theme'
        self.assertTrue((source / 'layout.php').is_file(), 'Starter layout is missing')
        self.assertTrue((source / 'css' / 'style.css').is_file())
        self.assertTrue((source / 'README.md').is_file())
        with tempfile.TemporaryDirectory() as tmp:
            archive = Path(tmp) / 'starter-theme.zip'
            with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as output:
                for file in sorted(source.rglob('*')):
                    if file.is_file():
                        output.write(file, 'starter-theme/' + file.relative_to(source).as_posix())
            with zipfile.ZipFile(archive) as uploaded:
                self.assertIsNone(uploaded.testzip())
                self.assertIn('starter-theme/layout.php', uploaded.namelist())
            env = os.environ | {'DN_DB_DATABASE': str(Path(tmp) / 'site.sqlite')}
            php = ['php', '-d', 'extension=pdo_sqlite', '-d', 'extension=sqlite3']
            subprocess.run(php + ['bin/disconunu', 'migrate'], cwd=ROOT, env=env, check=True, capture_output=True)
            script = '''require 'autoload.php'; DN\\App::boot();
            $appName='<Example>'; $subtitle='Small site'; $baseUrl='http://example.test';
            $title='Example'; $themeUrl='/themes/starter-theme'; $fallbackThemeUrl='/themes/teal';
            $logoUrl='/themes/teal/images/logo.jpg'; $rssEnabled=true; $user=null;
            $cats=[]; $flashes=[]; $content='<article>Test content</article>';
            require 'examples/starter-theme/layout.php';'''
            rendered = subprocess.run(php + ['-r', script], cwd=ROOT, env=env, check=True, capture_output=True, text=True)
            self.assertEqual('', rendered.stderr)
            self.assertIn('&lt;Example&gt;', rendered.stdout)
            self.assertIn('<article>Test content</article>', rendered.stdout)
            self.assertIn('lang="en"', rendered.stdout)
            self.assertIn('/feed', rendered.stdout)
            self.assertIn('site-footer', rendered.stdout)


if __name__ == '__main__':
    unittest.main()
