#!/usr/bin/env python3
"""Dependency-free admin UI contracts. Run: python3 tests/admin_ui.py.

Rendered markup checks supplement browser E2E. Optional isolated browser checks:
DN_UI_BROWSER=1 uv run --with playwright python tests/admin_ui.py
No live database, uploaded assets, or credentials are used.
"""
from pathlib import Path
import re
import unittest
import json
import os
import subprocess
import tempfile
from html.parser import HTMLParser


class Markup(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.tags = []
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        self.tags.append((tag, dict(attrs)))

    def attrs(self, tag):
        return [attrs for name, attrs in self.tags if name == tag]


def render(template, role="superadmin", scenario="default"):
    """Exercise real PHP templates and models against an isolated SQLite database."""
    php = r'''
require 'autoload.php';
DN\App::boot();
DN\Migrator::run();
DN\App::startSession();
$actor = DN\Model\User::create('ui-tester', 'tester@example.test', bin2hex(random_bytes(24)), getenv('UI_ROLE'));
$_SESSION['user_id'] = $actor->id;
$draft = DN\Model\Post::create($actor->id, 'Draft title', '<p>Two words</p>', 'Two words', 'draft');
$published = DN\Model\Post::create($actor->id, 'Published title', '<p>Live words</p>', 'Live words', 'published');
$posts = DN\Model\Post::all();
$template = getenv('UI_TEMPLATE');
$uri = match($template) {
    'dashboard' => '/admin', 'post-edit' => '/admin/post/' . $draft->id . '/edit',
    default => '/admin/' . $template,
};
$_SERVER['REQUEST_URI'] = $uri;
$data = [
    'posts' => $posts, 'post' => DN\Model\Post::findById($draft->id),
    'cats' => DN\Model\Category::all(), 'categories' => DN\Model\Category::all(),
    'authors' => [$actor], 'users' => [$actor], 'superadminCount' => 1,
    'myCats' => [], 'selectedCatIds' => [], 'status' => '', 'current' => 'teal',
    'page' => 2, 'pages' => 8, 'total' => 142, 'perPage' => 20,
    'filters' => ['q' => 'tea & cake', 'status' => '', 'category' => '', 'author' => '', 'sort' => 'newest', 'role' => '', 'visibility' => '', 'per_page' => 20],
    'paginationUrl' => $uri . '?q=tea%20%26%20cake&page={page}',
    'error' => '', 'noUsers' => false,
];
if (getenv('UI_SCENARIO') === 'repopulate') {
    $category = DN\Model\Category::create('Tea & "cake"');
    $data['cats'] = DN\Model\Category::all();
    $data['selectedCatIds'] = [$category->id];
    $data['post']->title = 'Submitted "title"';
    $data['post']->content = '<p>Submitted & safe</p>';
}
if (getenv('UI_SCENARIO') === 'published') $data['post'] = $published;
if (getenv('UI_SCENARIO') === 'foreign') {
    $other = DN\Model\User::create('other', 'other@example.test', bin2hex(random_bytes(24)), 'writer');
    $data['post'] = DN\Model\Post::create($other->id, 'Other draft', '<p>Private</p>', 'Private', 'draft');
}
if (getenv('UI_SCENARIO') === 'new') $data['post'] = null;
if (getenv('UI_SCENARIO') === 'new-validation') {
    $data['post']->id = 0;
    $data['post']->status = 'published';
}
if (getenv('UI_SCENARIO') === 'empty') {
    $data['cats'] = []; $data['users'] = [];
    $data['total'] = 0; $data['page'] = 1; $data['pages'] = 1;
}
DN\View::render('admin/' . $template, $data);
session_destroy();
'''
    with tempfile.TemporaryDirectory(prefix="dn-admin-ui-") as tmp:
        env = dict(os.environ, DN_DB_DRIVER="sqlite", DN_DB_DATABASE=tmp + "/test.sqlite",
                   DN_APP_URL="http://example.test", DN_APP_DEBUG="1",
                   UI_TEMPLATE=template, UI_ROLE=role, UI_SCENARIO=scenario)
        result = subprocess.run(["php", "-d", "extension=pdo_sqlite", "-d", "extension=sqlite3",
                                 "-d", "session.save_path=" + tmp, "-r", php],
                                cwd=ROOT, env=env, text=True, capture_output=True)
        if result.returncode or result.stderr or "Server error 500" in result.stdout:
            raise AssertionError(result.stderr + result.stdout)
        return result.stdout

ROOT = Path(__file__).resolve().parents[1]
THEME = ROOT / "themes" / "teal"


def source(path):
    return (THEME / path).read_text()


class AdminUI(unittest.TestCase):
    def test_post_listing_filters_pagination_and_writer_view_only(self):
        html = render("dashboard", "writer")
        tags = Markup(html)
        fields = {a.get("name") for t, a in tags.tags if t in ("input", "select")}
        self.assertTrue({"q", "status", "category", "author", "sort", "per_page"} <= fields)
        self.assertIn('value="tea &amp; cake"', html)
        self.assertIn('142', html)
        links = tags.attrs("a")
        self.assertTrue(any("page=3" in a.get("href", "") and "tea%20%26%20cake" in a["href"] for a in links))
        self.assertFalse(any("/admin/post/2/edit" in a.get("href", "") for a in links))
        self.assertFalse(any("/admin/post/2/delete" in a.get("action", "") for a in tags.attrs("form")))
        self.assertTrue(any("/admin/post/1/edit" in a.get("href", "") for a in links))
        self.assertTrue(any("/admin/post/1/preview" in a.get("href", "") for a in links))
        for a in links:
            if "/post/published-title" in a.get("href", "") or "/preview" in a.get("href", ""):
                self.assertEqual("_blank", a.get("target"))
                self.assertIn("noopener", a.get("rel", ""))
        self.assertNotRegex(html, r'\son(?:submit|click)=')

    def test_editor_supports_safe_save_preview_and_writing_help(self):
        html = render("post-edit")
        tags = Markup(html)
        save_buttons = [a for a in tags.attrs("button") if a.get("name") == "save_action"]
        self.assertEqual({"continue", "list"}, {a.get("value") for a in save_buttons})
        self.assertTrue(any("data-editor" in a for a in tags.attrs("form")))
        self.assertTrue(any(a.get("href", "").endswith("/admin/post/1/preview") and a.get("target") == "_blank" for a in tags.attrs("a")))
        self.assertIn('id="word-count"', html)
        self.assertIn('id="html-help"', html)
        self.assertIn('name="categories[]"', html)
        writer = render("post-edit", "writer")
        self.assertNotIn('name="categories[]"', writer)
        self.assertIn('name="status" value="draft"', writer)
        js = source("js/admin.js")
        for hook in ("beforeunload", "requestSubmit", "metaKey", "ctrlKey", "data-confirm", "defaultPrevented"):
            self.assertIn(hook, js)
        self.assertNotRegex(html, r'\son(?:submit|click)=')
        self.assertNotRegex(html, r'<script(?![^>]*\bsrc=)[^>]*>\s*\S')

    def test_editor_repopulates_submitted_categories(self):
        html = render("post-edit", scenario="repopulate")
        selected = [a for a in Markup(html).attrs("input") if a.get("name") == "categories[]" and "checked" in a]
        self.assertEqual(["2"], [a["value"] for a in selected])
        self.assertIn('value="Submitted &quot;title&quot;"', html)
        self.assertIn('&lt;p&gt;Submitted &amp; safe&lt;/p&gt;', html)

    def test_writer_cannot_edit_published_or_foreign_posts(self):
        for scenario in ("published", "foreign"):
            with self.subTest(scenario=scenario):
                html = render("post-edit", "writer", scenario)
                self.assertFalse(any("data-editor" in a for a in Markup(html).attrs("form")))
                self.assertIn("This post cannot be edited", html)

    def test_settings_installs_trusted_themes_without_implicit_activation(self):
        html = render("settings")
        tags = Markup(html)
        inputs = {a.get("name"): a for a in tags.attrs("input")}
        self.assertIn("activate", inputs)
        self.assertEqual("checkbox", inputs["activate"].get("type"))
        self.assertEqual("1", inputs["activate"].get("value"))
        self.assertNotIn("checked", inputs["activate"])
        self.assertEqual("image/png,image/jpeg,image/gif,image/webp", inputs["logo"]["accept"])
        self.assertTrue({"site_title", "site_tagline", "footer_text", "rss_enabled", "posts_per_page"} <= inputs.keys())
        self.assertTrue(any(a.get("href", "").endswith("/admin/theme/starter") for a in tags.attrs("a")))
        self.assertIn("Download starter ZIP</a>", html)
        self.assertIn("trusted", html.lower())
        self.assertIn("PHP", html)
        self.assertNotRegex(html, r'\son\w+=')
        self.assertTrue(any("data-confirm" in a for a in tags.attrs("button")))
        self.assertTrue(any(a.get("id") == "identity" for a in tags.attrs("section")))
        self.assertTrue(any(a.get("id") == "themes" for a in tags.attrs("section")))

    def test_categories_filters_and_paginated_responsive_management(self):
        html = render("categories")
        tags = Markup(html)
        self.assertTrue(any(a.get("method") == "get" and a.get("class") == "filter-bar" for a in tags.attrs("form")))
        fields = {a.get("name") for t, a in tags.tags if t in ("input", "select")}
        self.assertTrue({"q", "visibility", "per_page"} <= fields)
        self.assertIn('value="tea &amp; cake"', html)
        self.assertIn("142 results", html)
        self.assertIn("Page 2 of 8", html)
        self.assertTrue(any("page=3" in a.get("href", "") and "tea%20%26%20cake" in a["href"] for a in tags.attrs("a")))
        self.assertTrue(all("data-label" in a for a in tags.attrs("td")))
        self.assertNotRegex(html, r'\son\w+=')
        self.assertFalse(any(a.get("action", "").endswith("/categories/1/delete") for a in tags.attrs("form")))
        self.assertIn("General is the fallback category", html)

    def test_users_filters_and_paginated_responsive_management(self):
        html = render("users")
        tags = Markup(html)
        self.assertTrue(any(a.get("method") == "get" and a.get("class") == "filter-bar" for a in tags.attrs("form")))
        fields = {a.get("name") for t, a in tags.tags if t in ("input", "select")}
        self.assertTrue({"q", "role", "per_page"} <= fields)
        self.assertIn('value="tea &amp; cake"', html)
        self.assertIn("142 results", html)
        self.assertIn("Page 2 of 8", html)
        self.assertTrue(any("page=3" in a.get("href", "") and "tea%20%26%20cake" in a["href"] for a in tags.attrs("a")))
        self.assertTrue(all("data-label" in a for a in tags.attrs("td")))
        self.assertNotRegex(html, r'\son\w+=')
        self.assertFalse(any(re.search(r"/users/1/(role|delete)$", a.get("action", "")) for a in tags.attrs("form")))
        self.assertIn("own drafts", html)
        self.assertIn('class="role-guide"', html)
        self.assertTrue(any(a.get("action", "").endswith("/users/create") for a in tags.attrs("form")))

    def test_empty_lists_offer_filter_recovery(self):
        for template in ("categories", "users"):
            with self.subTest(template=template):
                html = render(template, scenario="empty")
                self.assertIn('class="empty-state"', html)
                self.assertIn("No " + template + " found", html)
                self.assertIn("0 results", html)
                self.assertIn("Reset", html)
                self.assertNotIn('class="pagination"', html)

    def test_new_validation_retains_status_without_fake_preview_or_id(self):
        html = render("post-edit", scenario="new-validation")
        tags = Markup(html)
        self.assertIn("<h1>New post</h1>", html)
        self.assertFalse(any(a.get("name") == "id" for a in tags.attrs("input")))
        self.assertFalse(any("/preview" in a.get("href", "") for a in tags.attrs("a")))
        self.assertTrue(any(a.get("value") == "published" and "selected" in a for a in tags.attrs("option")))
        self.assertFalse(any(a.get("value") == "draft" and "selected" in a for a in tags.attrs("option")))

    def test_admin_shell_is_separate_and_accessible(self):
        layout = source("layout.php")
        self.assertIn('class="admin-shell"', layout)
        self.assertIn('id="main-content"', layout)
        self.assertIn('class="skip-link"', layout)
        self.assertIn('/css/admin.css', layout)
        self.assertIn('/js/admin.js', layout)
        nav = source("admin/nav.php")
        self.assertIn('aria-label="Administration"', nav)
        self.assertIn('aria-current="page"', nav)
        self.assertRegex(nav, r'target="_blank" rel="noopener(?: noreferrer)?"')
        css = source("css/admin.css")
        self.assertIn('min-width: 0', css)
        self.assertIn('@media', css)
        self.assertIn('var(--font-ui)', css)
        self.assertNotRegex(nav, r'\s(?:style|on\w+)=')


@unittest.skipUnless(os.environ.get("DN_UI_BROWSER") == "1", "Set DN_UI_BROWSER=1 and install Playwright for browser checks")
class AdminBrowser(unittest.TestCase):
    def test_editor_javascript_in_real_browser(self):
        from playwright.sync_api import sync_playwright
        with sync_playwright() as p:
            browser = p.chromium.launch(executable_path=os.environ.get("CHROME_BIN", "/usr/bin/google-chrome-stable"), headless=True)
            page = browser.new_page()
            page.route("**/*", lambda route: route.abort())
            page.set_content(render("post-edit"))
            page.add_script_tag(path=str(THEME / "js/admin.js"))
            self.assertEqual("2 words", page.locator("#word-count").inner_text())
            page.locator('[name="content"]').fill('<p>One</p><p>Two</p><script>not counted</script>')
            self.assertEqual("2 words", page.locator("#word-count").inner_text())
            page.locator('[name="content"]').fill('<p title="a > b">Two words</p>')
            self.assertEqual("2 words", page.locator("#word-count").inner_text())
            page.locator('[name="content"]').fill('')
            self.assertEqual("0 words", page.locator("#word-count").inner_text())
            page.locator('[name="content"]').fill('<p>One</p>')
            self.assertEqual("1 word", page.locator("#word-count").inner_text())
            dirty = """() => { const e = new Event('beforeunload', {cancelable: true}); window.dispatchEvent(e); return e.defaultPrevented; }"""
            self.assertTrue(page.evaluate(dirty))
            page.locator('[name="content"]').fill('<p>Two words</p>')
            self.assertFalse(page.evaluate(dirty))
            page.locator('[name="title"]').fill('Changed title')
            page.evaluate("""() => {
                window.saves = [];
                window.cancelSave = event => {
                    if (event.target.matches('[data-editor]')) {
                        window.saves.push(event.submitter.value);
                        event.preventDefault();
                    }
                };
                document.addEventListener('submit', window.cancelSave, true);
            }""")
            page.keyboard.press('Control+s')
            page.keyboard.press('Meta+s')
            self.assertEqual(['continue', 'continue'], page.evaluate('window.saves'))
            self.assertTrue(page.evaluate(dirty), "Cancelled submissions keep the unsaved warning")
            page.locator('[name="title"]').fill('')
            page.keyboard.press('Control+s')
            self.assertEqual(2, page.evaluate('window.saves.length'), "Ctrl+S respects required fields")
            page.locator('[name="title"]').fill('Changed title')
            page.evaluate("document.removeEventListener('submit', window.cancelSave, true)")
            page.locator('[data-editor]').evaluate("e => e.setAttribute('data-confirm', 'Save changes?')")
            page.once('dialog', lambda dialog: dialog.dismiss())
            accepted = page.locator('[data-editor]').evaluate("e => e.dispatchEvent(new SubmitEvent('submit', {bubbles: true, cancelable: true}))")
            self.assertFalse(accepted)
            self.assertTrue(page.evaluate(dirty))
            page.once('dialog', lambda dialog: dialog.accept())
            accepted = page.locator('[data-editor]').evaluate("e => e.dispatchEvent(new SubmitEvent('submit', {bubbles: true, cancelable: true}))")
            self.assertTrue(accepted)
            self.assertFalse(page.evaluate(dirty), "Accepted saves suppress the unload warning")
            browser.close()

    def test_templates_fit_mobile_and_desktop(self):
        from playwright.sync_api import sync_playwright
        with sync_playwright() as p:
            browser = p.chromium.launch(executable_path=os.environ.get("CHROME_BIN", "/usr/bin/google-chrome-stable"), headless=True)
            page = browser.new_page()
            page.route("**/*", lambda route: route.abort())
            for template in ('post-edit', 'settings', 'categories', 'users'):
                page.set_content(render(template))
                page.add_style_tag(path=str(THEME / 'css/style.css'))
                page.add_style_tag(path=str(THEME / 'css/admin.css'))
                for width in (375, 768, 1440):
                    with self.subTest(template=template, width=width):
                        page.set_viewport_size({'width': width, 'height': 900})
                        self.assertTrue(page.evaluate('document.documentElement.scrollWidth <= window.innerWidth'))
            browser.close()


if __name__ == "__main__":
    unittest.main(verbosity=2)
