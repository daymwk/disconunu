#!/usr/bin/env python3
"""Account bootstrap and CLI invariants, using throwaway SQLite databases."""
import os
from pathlib import Path
import secrets
import sqlite3
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = ['php']
if 'pdo_sqlite' not in subprocess.check_output(PHP + ['-m'], text=True):
    PHP += ['-d', 'extension=pdo_sqlite']


class Accounts(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.db = Path(self.tmp.name) / 'test.sqlite'
        self.env = os.environ | {'DN_DB_DRIVER': 'sqlite', 'DN_DB_DATABASE': str(self.db), 'DN_USER_PASSWORD': secrets.token_urlsafe(24)}
        self.run_cli('migrate')

    def run_cli(self, *args):
        return subprocess.run(PHP + ['bin/disconunu', *args], cwd=ROOT, env=self.env, capture_output=True, text=True)

    def query(self, query):
        with sqlite3.connect(self.db) as db:
            return db.execute(query).fetchall()

    def test_first_cli_account_is_superadmin_and_second_writer(self):
        self.assertEqual(0, self.run_cli('user', 'first_user', 'first@example.test').returncode)
        self.assertEqual([('superadmin',)], self.query('select role from dn_user'))
        self.assertEqual(0, self.run_cli('user', 'second_user', 'second@example.test').returncode)
        self.assertEqual([('superadmin',), ('writer',)], self.query('select role from dn_user order by id'))

    def test_last_superadmin_cannot_be_demoted_via_cli(self):
        self.run_cli('user', 'first_user', 'first@example.test', '--role=superadmin')
        self.assertNotEqual(0, self.run_cli('role', 'first_user', 'writer').returncode)
        self.assertEqual([('superadmin',)], self.query('select role from dn_user'))

    def test_cli_username_validation_matches_web(self):
        self.assertNotEqual(0, self.run_cli('user', 'bad user', 'bad@example.test').returncode)
        self.assertEqual([], self.query('select id from dn_user'))

    def test_empty_site_seeder_does_not_claim_first_account(self):
        result = subprocess.run(PHP + ['bin/seed-demo.php', '3'], cwd=ROOT, env=self.env, capture_output=True, text=True)
        self.assertNotEqual(0, result.returncode)
        self.assertEqual([], self.query('select id from dn_user'))

    def test_bootstrap_is_single_use(self):
        script = "require 'autoload.php'; DN\\Model\\User::createFirst('owner','owner@example.test',getenv('DN_USER_PASSWORD')); try {DN\\Model\\User::createFirst('intruder','intruder@example.test',getenv('DN_USER_PASSWORD')); exit(2);} catch(RuntimeException $e) {echo 'blocked';}"
        result = subprocess.run(PHP + ['-r', script], cwd=ROOT, env=self.env, capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual('blocked', result.stdout)
        self.assertEqual([('owner', 'superadmin')], self.query('select username,role from dn_user'))


if __name__ == '__main__':
    unittest.main()
