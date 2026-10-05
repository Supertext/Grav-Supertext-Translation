"""Takes the documentation screenshots. Run through scripts/docs-screenshots.sh."""
import asyncio
import json
import os
import urllib.request

from playwright.async_api import async_playwright

BASE = os.environ["BASE"]
OUT = os.environ["OUT"]
WIDTH, HEIGHT = 1280, 820
PANEL_TAG = "grav-supertext-translation--panel"


async def login(page, username, password):
    await page.goto(f"{BASE}/admin")
    await page.locator("#username").wait_for()
    await page.locator("#username").fill(username)
    await page.locator("#password").fill(password)
    await page.keyboard.press("Enter")
    await page.wait_for_url(lambda url: "login" not in url)
    await page.wait_for_timeout(1500)


async def shot(page, name, clip=None):
    path = os.path.join(OUT, name)
    await page.screenshot(path=path, clip=clip)
    print("  " + name)


async def panel_clip(page):
    """The panel, cropped to its content."""
    height = await page.evaluate(
        """tag => { const el = document.querySelector(tag);
            const body = el.querySelector('.st-body'), head = el.querySelector('.st-head');
            return head.offsetHeight + body.scrollHeight + 8; }""",
        PANEL_TAG,
    )
    box = await page.locator(PANEL_TAG).bounding_box()
    return {"x": box["x"], "y": 0, "width": box["width"], "height": min(HEIGHT, height)}


async def open_page(page, route):
    await page.goto(f"{BASE}/admin/pages/edit/{route}")
    await page.get_by_role("button", name="Supertext translation").wait_for()
    await page.wait_for_timeout(1500)


async def open_panel(page):
    await page.get_by_role("button", name="Supertext translation").click()
    await page.locator(f"{PANEL_TAG} input[data-code]").first.wait_for()
    await page.wait_for_timeout(600)


async def switch_language(page, name):
    await page.locator(f'button:has-text("{name}")').filter(has_not_text="Create").first.click()
    await page.wait_for_timeout(2000)


def api(method, path, token, body=None):
    request = urllib.request.Request(
        f"{BASE}/api/v1{path}",
        data=json.dumps(body).encode() if body is not None else None,
        method=method,
        headers={"X-API-Token": token, "Content-Type": "application/json"},
    )
    return json.load(urllib.request.urlopen(request))


def token(username, password):
    request = urllib.request.Request(
        f"{BASE}/api/v1/auth/token",
        data=json.dumps({"username": username, "password": password}).encode(),
        headers={"Content-Type": "application/json"},
    )
    return json.load(urllib.request.urlopen(request))["data"]["access_token"]


async def main():
    os.makedirs(OUT, exist_ok=True)
    async with async_playwright() as p:
        browser = await p.chromium.launch()

        # ── Administrator: language setup and plugin settings ──────────────
        admin = await browser.new_page(viewport={"width": WIDTH, "height": HEIGHT}, device_scale_factor=1)
        await login(admin, "admin", os.environ["ADMIN_PASSWORD"])

        await admin.goto(f"{BASE}/admin/config/system")
        await admin.get_by_role("button", name="Languages", exact=True).click()
        await admin.wait_for_timeout(1500)
        await shot(admin, "grav-languages.png", {"x": 224, "y": 48, "width": WIDTH - 224, "height": 640})

        await admin.goto(f"{BASE}/admin/plugins/supertext-translation")
        await admin.get_by_text("Supertext account").wait_for()
        await admin.wait_for_timeout(1000)
        await admin.evaluate("window.scrollTo(0, 340)")
        await admin.wait_for_timeout(500)
        await shot(admin, "plugin-settings.png", {"x": 224, "y": 0, "width": WIDTH - 224, "height": HEIGHT})

        # ── Editor: translate a page ───────────────────────────────────────
        editor = await browser.new_page(viewport={"width": WIDTH, "height": HEIGHT}, device_scale_factor=1)
        await login(editor, "editor", os.environ["EDITOR_PASSWORD"])
        await open_page(editor, "home")
        # Mark the Supertext button: it is an icon without text.
        await editor.get_by_role("button", name="Supertext translation").evaluate(
            "b => { b.style.outline = '3px solid #e4007d'; b.style.outlineOffset = '3px'; }")
        await shot(editor, "editor-toolbar.png", {"x": 224, "y": 48, "width": WIDTH - 224, "height": 150})
        await editor.get_by_role("button", name="Supertext translation").evaluate("b => { b.style.outline = ''; }")

        await open_panel(editor)
        await shot(editor, "panel-before.png", await panel_clip(editor))

        await editor.locator(f"{PANEL_TAG} button[data-action=translate]").click()
        await editor.locator(f"{PANEL_TAG} .st-spinner").wait_for()
        await shot(editor, "panel-translating.png", await panel_clip(editor))
        await editor.locator(f"{PANEL_TAG} .st-results").wait_for(timeout=60000)
        await editor.wait_for_timeout(800)
        await shot(editor, "panel-after.png", await panel_clip(editor))

        # The German translation in the editor.
        await editor.locator(f"{PANEL_TAG} button[data-action=close]").click()
        await editor.wait_for_timeout(800)
        await open_page(editor, "home")
        await switch_language(editor, "Deutsch")
        await shot(editor, "translated-editor.png", {"x": 224, "y": 48, "width": WIDTH - 224, "height": HEIGHT - 48})

        # Edit the German title, save, then ask for a new translation → warning.
        title = editor.locator("input:visible").first
        await title.fill("Willkommen bei Supertext – für Grav")
        await editor.get_by_role("button", name="Save", exact=True).click()
        await editor.wait_for_timeout(2000)
        await open_panel(editor)
        await editor.locator(f'{PANEL_TAG} input[data-code="de"]').check()
        await editor.locator(f"{PANEL_TAG} button[data-action=translate]").click()
        await editor.locator(f"{PANEL_TAG} .st-confirm").wait_for()
        await editor.wait_for_timeout(400)
        await shot(editor, "panel-overwrite-warning.png", await panel_clip(editor))
        await editor.locator(f"{PANEL_TAG} button[data-action=overwrite]").click()
        await editor.locator(f"{PANEL_TAG} .st-results").wait_for(timeout=60000)

        # ── Visitor: the published German page ─────────────────────────────
        t = token("editor", os.environ["EDITOR_PASSWORD"])
        page = api("GET", "/pages/home?lang=de", t)["data"]
        header = page["header"]
        header["published"] = True
        api("PATCH", "/pages/home?lang=de", t, {"header": header, "content": page["content"], "title": page["title"]})
        visitor = await browser.new_page(viewport={"width": WIDTH, "height": HEIGHT}, device_scale_factor=1)
        await visitor.goto(f"{BASE}/de")
        await visitor.wait_for_timeout(1500)
        await shot(visitor, "translated-site.png", {"x": 0, "y": 0, "width": WIDTH, "height": 700})

        await browser.close()


asyncio.run(main())
