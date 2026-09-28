"""Responsive evidence for For Enhancement (2).pdf on isolated synthetic records."""
import os, sys, json, subprocess, time
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'.browser-tools'))
from playwright.sync_api import sync_playwright
fixture=json.loads((ROOT/'storage/private/revision-fixture.json').read_text())
assert fixture['database'].startswith('vicar_revision_')
BASE='http://127.0.0.1:8781'
env=os.environ.copy();env.update(DB_NAME=fixture['database'],APP_ENV='local',APP_URL=BASE)
subprocess.run(['C:/xampp/mysql/bin/mysql.exe','--host=127.0.0.1','--user=root',fixture['database'],'-e',"DELETE FROM site_settings WHERE setting_key IN ('site_logo','hero_bg_image','hero_headline','site_name','color_navy','color_gold','color_wine'); INSERT INTO applications(user_id,parish_id,service_id,schedule,status) SELECT 4,1,1,'2038-01-20 09:00:00','pending' FROM users a CROSS JOIN users b WHERE (SELECT COUNT(*) FROM applications)<60 LIMIT 60;"],check=True)
out=ROOT/'audit/pdf-final/browser';out.mkdir(parents=True,exist_ok=True)
log=(ROOT/'storage/private/final-pdf-browser.log').open('w')
server=subprocess.Popen(['C:/xampp/php/php.exe','-d','disable_functions=mail,curl_exec,fsockopen,stream_socket_client','-S','127.0.0.1:8781','-t',str(ROOT)],env=env,cwd=ROOT,stdout=log,stderr=log)
results=[]
def check(name,ok,details=None):
 results.append(dict(test=name,passed=bool(ok),details=details))
 print(('PASS ' if ok else 'FAIL ')+name,flush=True)
try:
 time.sleep(1)
 with sync_playwright() as pw:
  browser=pw.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe',headless=True)
  ctx=browser.new_context(viewport={'width':1440,'height':1000})
  ctx.route('**/*',lambda r:r.continue_() if r.request.url.startswith(BASE) or r.request.url.startswith('data:') else r.abort())
  page=ctx.new_page();errors=[];page.on('pageerror',lambda error:errors.append(str(error)))
  def modal_checks(route,action,content,name):
   for width in [1440,390,320]:
    page.set_viewport_size({'width':width,'height':1000});page.goto(BASE+route);page.evaluate(action);page.wait_for_timeout(500)
    check(f'{name} {width}: content flows vertically',page.locator(content).evaluate("e=>getComputedStyle(e).display==='block'") and page.locator(content+' h2').bounding_box()['width']>100)
    check(f'{name} {width}: fits viewport',page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'))
    check(f'{name} {width}: one close control',page.locator('#viewModal button:visible').filter(has_text='Close').count()==1)
    page.screenshot(path=str(out/f'{name}-{width}.png'),full_page=True)
  routes={0:['/index.php','/public/login.php','/public/forgot_password.php'],1:['/admin/dashboard.php','/admin/analytics.php','/admin/finance.php','/admin/main_database.php','/admin/applications.php','/admin/announcements.php','/admin/reports.php?type=check_voucher&date_from=2020-01-01&date_to=2040-01-01'],2:['/staff/dashboard.php','/staff/services.php','/staff/parishioners.php','/staff/messages.php','/staff/application_details.php?id=1','/staff/requests.php?type=cancel','/staff/security.php'],3:['/staff/dashboard.php','/staff/payments.php','/staff/accounting.php?type=check_voucher','/staff/accounting.php?type=journal_voucher','/staff/export.php'],4:['/parishioner/dashboard.php','/parishioner/settings.php','/parishioner/announcements.php','/parishioner/apply_service.php','/parishioner/events.php','/parishioner/requests.php?type=cancel']}
  for uid,paths in routes.items():
   if uid:
    page.goto(BASE+'/public/logout.php');page.goto(BASE+'/public/login.php')
    page.locator('[name=email]').fill(f'audit{uid}@example.invalid');page.locator('[name=password]').fill(fixture['password']);page.locator('button[type=submit]').click();page.wait_for_url('**/dashboard.php')
   for width in [1440,1024,768,390,320]:
    page.set_viewport_size({'width':width,'height':1000})
    for route in paths:
     errors.clear();response=page.goto(BASE+route)
     if route=='/index.php' and '/loading.php' in page.url:
      response=page.goto(BASE+route)
     if 'verify_password.php' in page.url:
      page.locator('[name=password]').fill(fixture['password']);page.get_by_role('button',name='Verify',exact=True).click();page.wait_for_load_state()
     page.wait_for_timeout(450)
     overflow=page.evaluate('document.documentElement.scrollWidth > innerWidth+1')
     offenders=page.evaluate("Array.from(document.querySelectorAll('body *')).filter(e=>e.getBoundingClientRect().right>innerWidth+2 && getComputedStyle(e).position!=='fixed').slice(0,8).map(e=>e.tagName+'.'+e.className)") if overflow else []
     check(f'{uid} {width} {route}',response.status==200 and page.url.split('?')[0]==(BASE+route).split('?')[0] and not overflow and not errors,dict(url=page.url,overflow=overflow,offenders=offenders,errors=list(errors)))
     if width in [1440,390]:
      name=route.strip('/').replace('/','-').replace('?','-').replace('=','-').replace('&','-')
      page.screenshot(path=str(out/f'{uid}-{width}-{name}.png'),full_page=True)
     if 'dashboard.php' in route: check(f'{uid} {width} dashboard has no return-home control',page.locator('.return-navigation').count()==0)
   if uid==1:
    modal_checks('/admin/applications.php','viewApp(1)','#viewModalContent','admin-application-modal')
    check('Admin application modal opens after password verification',page.locator('#viewModalContent').inner_text().find('Audit User 4')>=0)
    page.screenshot(path=str(out/'admin-application-modal.png'),full_page=True)
   if uid==2:
    page.goto(BASE+'/staff/services.php');page.evaluate("openRequirements(1,'Audit Baptism A')");page.wait_for_timeout(250)
    page.locator('[data-edit-requirement="1"]').click();page.locator('#reqDescription').fill('Browser reviewed description');page.locator('#saveRequirementBtn').click();page.wait_for_timeout(300)
    check('Requirement editor saves and refreshes description','Browser reviewed description' in page.locator('#reqsList').inner_text())
    check('Requirement modal has one close control',page.locator('#reqsModal .dialog-return').count()==0 and page.locator('#reqsModal button[aria-label="Close dialog"]').count()==1)
    page.screenshot(path=str(out/'requirement-editor-mobile.png'),full_page=True)
    page.goto(BASE+'/staff/dashboard.php')
    check('Dense Secretary dashboard preview bounded to eight',page.locator('tr[id^="app-row-"]').count()<=8)
   if uid==3:
    modal_checks('/staff/payments.php','viewPayment(1)','#viewContent','payment-modal')
   if uid==4:
    page.goto(BASE+'/parishioner/settings.php');avatar=page.locator('.profile-avatar')
    check('Settings avatar is circular',avatar.evaluate("e=>getComputedStyle(e).borderRadius==='50%'") and avatar.bounding_box()['width']==avatar.bounding_box()['height'])
  browser.close()
finally:
 server.terminate();server.wait(timeout=10);log.close()
 (out/'results.json').write_text(json.dumps(results,indent=2))
print(f'Results: {sum(r["passed"] for r in results)} passed, {sum(not r["passed"] for r in results)} failed')
sys.exit(any(not r['passed'] for r in results))
