#!/usr/bin/env python3
"""Real HTTP/cookie regressions against a disposable copy; no live data touched."""
import base64
import html
import http.cookiejar
import io
import json
import os
from pathlib import Path
import re
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
import zipfile

ROOT = Path(__file__).resolve().parents[1]

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

class BackendHTTP(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='dn-backend-http-')
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name) / 'app'
        shutil.copytree(ROOT, self.root, ignore=shutil.ignore_patterns('.git', 'var', '__pycache__'))
        self.db = Path(self.tmp.name) / 'test.sqlite'
        self.env = dict(os.environ, DN_DB_DRIVER='sqlite', DN_DB_DATABASE=str(self.db), DN_DEBUG='1', DN_THEME='teal', DN_SECURE_COOKIES='0', DN_APP_URL='')
        modules = subprocess.check_output(['php', '-m'], text=True).lower().splitlines()
        self.php = ['php']
        for extension in ['pdo_sqlite', 'sqlite3', 'zip']:
            if extension not in modules:
                self.php += ['-d', 'extension=' + extension]
        self.php += ['-d', 'session.save_path=' + self.tmp.name]
        seed = r"""require 'autoload.php'; DN\App::boot(); DN\Migrator::run();
        foreach (['superadmin'=>'superadmin','admin'=>'admin','writer'=>'writer','writer2'=>'writer'] as $name=>$role) {
            $u=DN\Model\User::create($name, $name.'@example.test','Test-pass-12345',$role);
            DN\Model\Post::create($u->id,$name.' draft','<p>Private '.$name.'</p>','','draft');
            DN\Model\Post::create($u->id,$name.' published','<p>Public '.$name.'</p>','','published');
        }
        DN\Model\Category::create('Hidden category','hidden-category',false);
        DN\Model\Category::create('Visible category','visible-category',true);
        """
        subprocess.run(self.php + ['-r', seed], cwd=self.root, env=self.env, check=True, capture_output=True)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        self.base = 'http://127.0.0.1:' + str(port)
        self.log = open(Path(self.tmp.name) / 'http.log', 'w+')
        self.addCleanup(self.log.close)
        self.server = subprocess.Popen(self.php + ['-S', '127.0.0.1:' + str(port), '-t', 'public', 'public/index.php'], cwd=self.root, env=self.env, stdout=self.log, stderr=self.log)
        self.addCleanup(self.stop_server)
        self.clients = {}
        for attempt in range(100):
            try:
                self.request(None, '/admin/login')
                break
            except urllib.error.URLError:
                if self.server.poll() is not None:
                    self.fail('PHP server exited')
                time.sleep(.03)
        else:
            self.fail('PHP server never became ready')
        self.tokens = {}
        for name in ['superadmin', 'admin', 'writer', 'writer2']:
            status, _, body = self.request(name, '/admin/login')
            self.assertEqual(200, status, body.decode())
            match = re.search(rb'name="csrf" value="([^"]+)"', body)
            self.assertIsNotNone(match, body.decode())
            assert match is not None
            token = match.group(1).decode()
            self.tokens[name] = token
            self.assertEqual(303, self.request(name, '/admin/login', {'csrf': token, 'username': name, 'password': 'Test-pass-12345'})[0])

    def stop_server(self):
        self.server.terminate()
        self.server.wait(timeout=5)

    def rows(self, sql, args=()):
        with sqlite3.connect(self.db) as db:
            return db.execute(sql, args).fetchall()

    def request(self, actor, path, fields=None, upload=None):
        if actor not in self.clients:
            self.clients[actor] = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())
        headers = {}
        data = None
        if fields is not None:
            fields = dict(fields)
            if actor in getattr(self, 'tokens', {}):
                fields.setdefault('csrf', self.tokens[actor])
            if upload:
                boundary = 'dn-http-regression-boundary'
                parts = []
                for key, value in fields.items():
                    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
                field, filename, mime, content = upload
                parts += [f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"; filename="{filename}"\r\nContent-Type: {mime}\r\n\r\n'.encode(), content, f'\r\n--{boundary}--\r\n'.encode()]
                data = b''.join(parts)
                headers['Content-Type'] = 'multipart/form-data; boundary=' + boundary
            else:
                data = urllib.parse.urlencode(fields, doseq=True).encode()
        req = urllib.request.Request(self.base + path, data=data, headers=headers)
        try:
            response = self.clients[actor].open(req, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.code, response.headers, response.read()

    def test_acl_mutations(self):
        for actor in ['admin', 'writer', 'writer2']:
            with self.subTest(escalation=actor):
                uid = self.rows('SELECT id FROM dn_user WHERE username=?', (actor,))[0][0]
                self.assertEqual(403, self.request(actor, f'/admin/users/{uid}/role', {'role': 'superadmin'})[0])
                self.assertEqual(actor if actor == 'admin' else 'writer', self.rows('SELECT role FROM dn_user WHERE id=?', (uid,))[0][0])
        for post_id in [6, 7, 8]:
            for action in ['edit', 'save', 'delete']:
                with self.subTest(post=post_id, action=action):
                    before = self.rows('SELECT * FROM dn_post WHERE id=?', (post_id,))
                    if action == 'save':
                        result = self.request('writer', '/admin/post/save', {'id': post_id, 'title': 'Intrusion', 'content': '<p>Intrusion</p>', 'status': 'draft'})
                    else:
                        result = self.request('writer', f'/admin/post/{post_id}/{action}', {} if action == 'delete' else None)
                    self.assertEqual(403, result[0])
                    self.assertEqual(before, self.rows('SELECT * FROM dn_post WHERE id=?', (post_id,)))
        self.assertEqual(200, self.request('writer', '/admin/post/5/edit')[0])
        self.assertEqual(303, self.request('writer', '/admin/post/save', {'id':5, 'title':'My draft', 'content':'<p>Saved</p>', 'status':'published'})[0])
        self.assertEqual(('My draft','draft'), self.rows('SELECT title,status FROM dn_post WHERE id=5')[0])
        self.assertEqual(303, self.request('writer', '/admin/post/5/delete', {})[0])
        self.assertEqual([], self.rows('SELECT id FROM dn_post WHERE id=5'))


    def test_category_permissions_and_invalid_ids(self):
        general = self.rows("SELECT id FROM dn_category WHERE slug='general'")[0][0]
        for path, fields, expected in [
            ('/admin/categories/save', {'title':'Blocked'}, 403),
            (f'/admin/categories/{general}/delete', {}, 403),
        ]:
            with self.subTest(path=path):
                self.assertEqual(expected, self.request('writer', path, fields)[0])
        for path, fields, expected in [
            ('/admin/categories/save', {'id':'9999','title':'Missing'},404),
            ('/admin/categories/save', {'id':'garbage','title':'Invalid'},400),
            ('/admin/categories/9999/delete', {},404),
            ('/admin/categories/1oops/delete', {},400),
            (f'/admin/categories/{general}/delete', {},403),
        ]:
            with self.subTest(path=path, fields=fields):
                self.assertEqual(expected, self.request('admin', path, fields)[0])
        self.assertTrue(self.rows("SELECT id FROM dn_category WHERE slug='general'"))
        self.assertFalse(self.rows("SELECT id FROM dn_category WHERE title IN ('Blocked','Invalid','Missing')"))


    def test_paginated_lists_and_public_clamping(self):
        # More than the old 500-row cap; query matches the oldest item.
        with sqlite3.connect(self.db) as db:
            db.executemany("INSERT INTO dn_post(user_id,title,slug,excerpt,content,status,published_at,updated_at) VALUES(3,?,?, '', '<p>bulk</p>', 'draft',NULL,'2026-01-01')", [(f'Bulk {i:03}', f'bulk-{i}') for i in range(505)])
        def titles(actor, path):
            status, _, body = self.request(actor, path)
            self.assertEqual(200,status,body.decode())
            return re.findall(r'<a class="post-title"[^>]*>(.*?)</a>', body.decode(), re.S), body.decode()
        for actor, query, expected in [
            ('admin','q=writer%20draft&per_page=1&sort=oldest',['writer draft']),
            ('admin','q=Bulk&per_page=2&page=2&sort=oldest',['Bulk 002','Bulk 003']),
            ('admin','q=Bulk&per_page=2&page=999999&sort=oldest',['Bulk 504']),
            ('writer','author=4&per_page=2',[]),
            ('writer2','q=Bulk&per_page=2',[]),
        ]:
            with self.subTest(actor=actor,query=query):
                found, body = titles(actor,'/admin?'+query)
                self.assertEqual(expected,[html.unescape(re.sub('<[^>]+>','',x)).strip() for x in found])
        for path, present, absent in [
            ('/admin/categories?q=category&visibility=hidden&per_page=1','Hidden category','Visible category'),
            ('/admin/users?q=writer&role=writer&per_page=1&page=2','writer2@example.test','writer@example.test'),
        ]:
            with self.subTest(path=path):
                status,_,body=self.request('superadmin',path)
                self.assertEqual(200,status)
                self.assertIn(present,body.decode())
                self.assertNotIn(absent,body.decode())
        for path in ['/?page=999999','/category/general?page=999999']:
            with self.subTest(public=path):
                status,_,body=self.request(None,path)
                self.assertEqual(200,status)
                self.assertIn('writer published',body.decode())


    def test_preview_access_and_private_headers(self):
        for actor, post, expected in [(None,5,303),('writer',5,200),('writer',6,200),('writer',7,403),('writer2',5,403),('admin',5,200),('superadmin',7,200),('admin',9999,404)]:
            with self.subTest(actor=actor,post=post):
                status, headers, body = self.request(actor,f'/admin/post/{post}/preview')
                self.assertEqual(expected,status)
                if expected == 200:
                    self.assertIn('no-store',headers.get('Cache-Control',''))
                    self.assertIn('noindex',headers.get('X-Robots-Tag',''))
                    self.assertIn('class="site-header"',body.decode())
                    self.assertNotIn('class="admin-shell"',body.decode())
        self.assertEqual(404,self.request(None,'/post/writer-draft')[0])


    def test_editor_save_continue_and_validation_retention(self):
        with self.subTest(continue_save=True):
            status,headers,_ = self.request('admin','/admin/post/save',{'title':'Continue post','content':'<p>Saved body</p>','status':'draft','save_action':'continue'})
            post_id = self.rows("SELECT id FROM dn_post WHERE title='Continue post'")[0][0]
            self.assertEqual(303,status)
            self.assertEqual(self.base+f'/admin/post/{post_id}/edit',headers['Location'])
        for post_id in [0,3]:
            with self.subTest(validation_post=post_id):
                fields={'id':post_id,'title':'','content':'<p>Keep my text &amp; markup</p>','status':'published','categories[]':['2','3']}
                before=self.rows('SELECT * FROM dn_post WHERE id=?',(post_id,))
                status,headers,_=self.request('admin','/admin/post/save',fields)
                self.assertEqual(303,status)
                path='/admin/post/new' if not post_id else f'/admin/post/{post_id}/edit'
                self.assertEqual(self.base+path,headers['Location'])
                status,_,body=self.request('admin',path)
                self.assertEqual(200,status,body.decode())
                text=body.decode()
                self.assertIn('&lt;p&gt;Keep my text &amp;amp; markup&lt;/p&gt;',text)
                self.assertRegex(text,r'name="title"[^>]*value=""')
                self.assertRegex(text,r'value="published"\s+selected')
                for cat_id in [2,3]:
                    self.assertRegex(text,rf'name="categories\[\]" value="{cat_id}"\s+checked')
                self.assertEqual(before,self.rows('SELECT * FROM dn_post WHERE id=?',(post_id,)))
        with self.subTest(missing_title=True):
            self.request('admin','/admin/post/save',{'title':'Retain this title','content':'','status':'draft'})
            self.assertIn('Retain this title', self.request('admin','/admin/post/new')[2].decode())
        # Authorization must run before even invalid input can affect editor state.
        self.assertEqual(403,self.request('writer','/admin/post/save',{'id':6,'title':'','content':''})[0])


    def test_theme_install_is_explicit_and_starter_downloads(self):
        def archive(name):
            stream=io.BytesIO()
            with zipfile.ZipFile(stream,'w') as out:
                out.writestr(name+'/layout.php','<!doctype html><title>Custom</title><main><?= $content ?></main>')
            return ('themezip',name+'.zip','application/zip',stream.getvalue())
        for name,fields,expected in [('installed-only',{},'teal'),('activated',{'activate':'1'},'activated'),('not-activated',{'activate':'yes'},'activated')]:
            with self.subTest(name=name):
                self.assertEqual(303,self.request('superadmin','/admin/theme',fields,archive(name))[0])
                self.assertTrue((self.root/'themes'/name/'layout.php').is_file())
                rows=self.rows("SELECT v FROM dn_setting WHERE k='theme'")
                self.assertEqual(expected,rows[0][0] if rows else 'teal')
        for actor in ['admin','writer','writer2']:
            with self.subTest(denied=actor):
                self.assertEqual(403,self.request(actor,'/admin/theme',{},archive('denied'))[0])
                self.assertEqual(403,self.request(actor,'/admin/theme/starter')[0])
        status,headers,body=self.request('superadmin','/admin/theme/starter')
        self.assertEqual(200,status)
        self.assertIn('application/zip',headers.get('Content-Type',''))
        with zipfile.ZipFile(io.BytesIO(body)) as downloaded:
            self.assertIsNone(downloaded.testzip())
            for file in (self.root/'examples'/'starter-theme').rglob('*'):
                if file.is_file():
                    self.assertEqual(file.read_bytes(),downloaded.read('starter-theme/'+file.relative_to(self.root/'examples'/'starter-theme').as_posix()))
        self.assertIn('class="admin-shell"',self.request('superadmin','/admin/settings')[2].decode())


    def test_settings_reject_invalid_theme_without_any_writes(self):
        initial = {'site_title': 'Kept title', 'site_tagline': 'Kept tagline',
                   'footer_text': 'Kept footer', 'posts_per_page': '7',
                   'rss_enabled': '1', 'theme': 'teal'}
        self.assertEqual(303, self.request('superadmin', '/admin/settings', initial)[0])
        broken = self.root / 'themes' / 'invalid-review'
        broken.mkdir()
        (broken / 'layout.php').write_text('<?php invalid syntax !')
        before = self.rows('SELECT k,v FROM dn_setting ORDER BY k')
        for theme in ['invalid-review', 'missing-theme', '../teal', '']:
            with self.subTest(theme=theme):
                fields = dict(initial, site_title='Changed', site_tagline='Changed',
                              footer_text='Changed', posts_per_page='2', rss_enabled='0', theme=theme)
                status = self.request('superadmin', '/admin/settings', fields)[0]
                after = self.rows('SELECT k,v FROM dn_setting ORDER BY k')
                self.assertEqual((400, before), (status, after))
                self.assertEqual(200, self.request(None, '/')[0])
                self.assertEqual(200, self.request('superadmin', '/admin/settings')[0])


    def test_partial_settings_preserve_absent_fields_and_explicit_rss(self):
        initial = {'site_title': 'Identity', 'site_tagline': 'Tagline', 'footer_text': 'Footer',
                   'posts_per_page': '7', 'rss_enabled': '1', 'theme': 'teal'}
        self.assertEqual(303, self.request('superadmin', '/admin/settings', initial)[0])
        alternate = self.root / 'themes' / 'alternate-review'
        alternate.mkdir()
        (alternate / 'layout.php').write_text('<main><?= $content ?></main>')
        expected = dict(initial)
        for fields in [{'theme': 'alternate-review'}, {'site_title': 'New title'},
                       {'rss_enabled': '0'}, {}, {'rss_enabled': '1'},
                       {'rss_enabled': 'yes'}, {'rss_enabled': ''}]:
            with self.subTest(fields=fields):
                expected.update(fields)
                if 'rss_enabled' in fields:
                    expected['rss_enabled'] = '1' if fields['rss_enabled'] == '1' else '0'
                self.assertEqual(303, self.request('superadmin', '/admin/settings', fields)[0])
                self.assertEqual(expected, dict(self.rows('SELECT k,v FROM dn_setting')))
                self.assertEqual(200 if expected['rss_enabled'] == '1' else 404,
                                 self.request(None, '/feed')[0])


    def test_hidden_category_badges_are_not_public(self):
        self.assertEqual(303, self.request('admin', '/admin/post/save', {
            'id': 4, 'title': 'admin published', 'content': '<p>Still public</p>',
            'status': 'published', 'categories[]': ['2', '3']})[0])
        self.assertEqual(404, self.request(None, '/category/hidden-category')[0])
        status, _, body = self.request(None, '/post/admin-published')
        self.assertEqual(200, status)
        self.assertIn(b'Still public', body)
        self.assertIn(b'/category/visible-category', body)
        self.assertIn(b'Visible category', body)
        self.assertIn(b'/category/general', body)
        self.assertNotIn(b'/category/hidden-category', body)
        self.assertNotIn(b'Hidden category', body)
        self.assertEqual([(2,), (3,)], self.rows(
            'SELECT category_id FROM dn_post_category WHERE post_id=4 ORDER BY category_id'))


    def test_theme_upload_activation_revalidates_installed_theme(self):
        # Wiring guard: the installer already rejects invalid PHP; activation must
        # independently validate the published directory before changing settings.
        controller = (self.root / 'public' / 'index.php').read_text()
        activation = controller.split("if (($_POST['activate'] ?? '') === '1') {", 1)[1]
        before_write = activation.split("Settings::set('theme', $name)", 1)[0]
        self.assertIn('ThemeManager::exists($name)', before_write)
        before = self.rows('SELECT k,v FROM dn_setting ORDER BY k')
        stream = io.BytesIO()
        with zipfile.ZipFile(stream, 'w') as archive:
            archive.writestr('invalid-upload/layout.php', '<?php invalid syntax !')
        self.assertEqual(303, self.request('superadmin', '/admin/theme', {'activate': '1'},
                         ('themezip', 'invalid.zip', 'application/zip', stream.getvalue()))[0])
        self.assertEqual(before, self.rows('SELECT k,v FROM dn_setting ORDER BY k'))
        self.assertEqual(200, self.request(None, '/')[0])
        self.assertIn(b'Theme install failed', self.request('superadmin', '/admin/settings')[2])


    def test_post_and_user_path_ids_reject_malformed_values_without_mutations(self):
        for bad_id in ['abc', '1junk', '2junk', '0', '-1', '999999999999999999999999']:
            for resource, action, fields in [
                ('post', 'edit', None), ('post', 'preview', None), ('post', 'delete', {}),
                ('users', 'delete', {}), ('users', 'role', {'role': 'writer'}),
            ]:
                with self.subTest(id=bad_id, resource=resource, action=action):
                    before = (self.rows('SELECT * FROM dn_post ORDER BY id'),
                              self.rows('SELECT * FROM dn_user ORDER BY id'))
                    status = self.request('superadmin', f'/admin/{resource}/{bad_id}/{action}', fields)[0]
                    after = (self.rows('SELECT * FROM dn_post ORDER BY id'),
                             self.rows('SELECT * FROM dn_user ORDER BY id'))
                    self.assertEqual((400, before), (status, after))


    def test_logo_is_raster_validated_and_replaced_atomically(self):
        png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jWZkAAAAASUVORK5CYII=')
        upload=('logo','untrusted.svg','image/svg+xml',png)
        self.assertEqual(303,self.request('superadmin','/admin/logo',{},upload)[0])
        target=self.root/'var'/'uploads'/'logo.png'
        self.assertEqual(png,target.read_bytes())
        self.assertEqual(png,self.request(None,'/uploads/logo.png')[2])
        for filename,mime,data in [('evil.svg','image/svg+xml',b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),('fake.png','image/png',b'\x89PNG\r\n\x1a\nnot an image'),('huge.png','image/png',png+b'\0'*(512*1024))]:
            with self.subTest(file=filename):
                self.assertEqual(303,self.request('superadmin','/admin/logo',{},('logo',filename,mime,data))[0])
                self.assertTrue(target.is_file(), 'Rejected upload removed the previous logo')
                self.assertEqual(png,target.read_bytes())
                self.assertFalse((target.parent/'logo.svg').exists())
                self.assertIn('Invalid logo',self.request('superadmin','/admin/settings')[2].decode())
        for actor in ['admin','writer','writer2']:
            self.assertEqual(403,self.request(actor,'/admin/logo',{},upload)[0])
        # A failing atomic rename must not delete the previous logo.
        target.unlink()
        target.mkdir()
        old=target.parent/'logo.gif'
        old.write_bytes(b'previous logo sentinel')
        self.assertEqual(303,self.request('superadmin','/admin/logo',{},upload)[0])
        self.assertEqual(b'previous logo sentinel',old.read_bytes())
        self.assertIn('Could not save logo',self.request('superadmin','/admin/settings')[2].decode())


    def test_first_web_account_uses_atomic_bootstrap(self):
        # Wiring guard complements the concurrent model tests in accounts.py.
        self.assertIn('User::createFirst($username, $email, $password)',(self.root/'public'/'index.php').read_text())
        with sqlite3.connect(self.db) as db:
            db.execute('DELETE FROM dn_post_category')
            db.execute('DELETE FROM dn_post')
            db.execute('DELETE FROM dn_user')
        _,_,body=self.request(None,'/admin/login')
        match=re.search(rb'name="csrf" value="([^"]+)"',body)
        assert match is not None
        status,_,_=self.request(None,'/admin/login',{'csrf':match.group(1).decode(),'username':'first-owner','email':'owner@example.test','password':'New-owner-pass-12345','role':'writer'})
        self.assertEqual(303,status)
        self.assertEqual([('first-owner','superadmin')],self.rows('SELECT username,role FROM dn_user'))
        self.assertEqual(200,self.request(None,'/admin/users')[0])

if __name__ == '__main__':
    unittest.main(verbosity=2)
