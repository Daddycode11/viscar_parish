"""PDF comparison screenshots on synthetic records; no production access."""
import os, sys, json, subprocess, time
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'.browser-tools'))
from playwright.sync_api import sync_playwright
fixture=json.loads((ROOT/'storage/private/revision-fixture.json').read_text())
assert fixture['database'].startswith('vicar_revision_')
BASE='http://127.0.0.1:8781'
env=os.environ.copy();env.update(DB_NAME=fixture['database'],APP_ENV='local',APP_URL=BASE)
out=ROOT/'audit/pdf-primary-20260928'/('after' if '--after' in sys.argv else 'before');out.mkdir(parents=True,exist_ok=True)
log=(ROOT/'storage/private/pdf-browser.log').open('w')
server=subprocess.Popen(['C:/xampp/php/php.exe','-d','disable_functions=mail,curl_exec,fsockopen,stream_socket_client','-S','127.0.0.1:8781','-t',str(ROOT)],env=env,cwd=ROOT,stdout=log,stderr=log)
try:
 time.sleep(1)
 with sync_playwright() as pw:
  browser=pw.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe',headless=True)
  ctx=browser.new_context(viewport={'width':1440,'height':1000})
  ctx.route('**/*',lambda r:r.continue_() if r.request.url.startswith(BASE) or r.request.url.startswith('data:') else r.abort())
  page=ctx.new_page()
  routes={0:['/loading.php','/public/login.php','/public/forgot_password.php'],1:['/admin/analytics.php','/admin/announcements.php','/admin/settings.php'],2:['/staff/services.php','/staff/security.php','/staff/application_details.php?id=1'],3:['/staff/dashboard.php','/staff/payments.php','/staff/accounting.php?type=check_voucher','/staff/accounting.php?type=journal_voucher'],4:['/parishioner/dashboard.php','/parishioner/settings.php','/parishioner/announcements.php','/parishioner/apply_service.php']}
  for uid,paths in routes.items():
   if uid:
    page.goto(BASE+'/public/logout.php');page.goto(BASE+'/public/login.php')
    page.locator('[name=email]').fill(f'audit{uid}@example.invalid');page.locator('[name=password]').fill(fixture['password']);page.locator('button[type=submit]').click();page.wait_for_url('**/dashboard.php')
   for width in [1440,390]:
    page.set_viewport_size({'width':width,'height':1000})
    for route in paths:
     response=page.goto(BASE+route)
     if 'verify_password.php' in page.url:
      page.locator('[name=password]').fill(fixture['password']);page.get_by_role('button',name='Verify',exact=True).click();page.wait_for_load_state()
     page.wait_for_timeout(400)
     name=route.strip('/').replace('/','-').replace('?','-').replace('=','-')
     page.screenshot(path=str(out/f'{width}-{name}.png'),full_page=True)
     print(f"Captured {width} {route} HTTP {response.status}",flush=True)
  browser.close()
finally:
 server.terminate();server.wait(timeout=10);log.close()
