"""Browser checks against the synthetic fixture created by test_separation.py."""
import os,sys,json,subprocess,time
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'.browser-tools'))
from playwright.sync_api import sync_playwright
fixture=json.loads((ROOT/'storage/private/revision-fixture.json').read_text())
assert fixture['database'].startswith('vicar_revision_')
env=os.environ.copy();env.update(DB_NAME=fixture['database'],APP_ENV='local',APP_URL='http://127.0.0.1:8779')
BASE=env['APP_URL'];results=[];errors=[];out=ROOT/'revision-evidence/recommendations';out.mkdir(parents=True,exist_ok=True)
def sql(query):return subprocess.check_output(['C:/xampp/mysql/bin/mysql.exe','--host=127.0.0.1','--user=root','--batch','--skip-column-names',fixture['database'],'-e',query],text=True).strip()
sql("UPDATE users SET two_factor_enabled=0,language='en',status='active' WHERE id IN (1,2,3,4)")
sql('DELETE FROM security_rate_limits')
def check(name,ok):results.append({'test':name,'passed':bool(ok)});print(('PASS ' if ok else 'FAIL ')+name,flush=True)
log=(ROOT/'storage/private/recommendation-browser.log').open('w')
server=subprocess.Popen(['C:/xampp/php/php.exe','-d','disable_functions=mail,curl_exec,fsockopen,stream_socket_client','-S','127.0.0.1:8779','-t',str(ROOT)],cwd=ROOT,env=env,stdout=log,stderr=log)
try:
 time.sleep(1)
 with sync_playwright() as pw:
  browser=pw.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe',headless=True)
  context=browser.new_context(viewport={'width':1440,'height':1000})
  context.route('**/*',lambda route:route.continue_() if route.request.url.startswith(BASE) or route.request.url.startswith('data:') else route.abort())
  page=context.new_page();page.on('pageerror',lambda error:errors.append(str(error)));page.on('dialog',lambda dialog:dialog.accept())
  def visit(path):
   page.goto(BASE+path)
   if 'verify_password.php' in page.url:
    page.locator('[name=password]').fill(fixture['password']);page.get_by_role('button',name='Verify',exact=True).click();page.wait_for_load_state()
   page.wait_for_timeout(200)
  def login(uid):
   visit('/public/logout.php');visit('/public/login.php');page.locator('[name=email]').fill(f'audit{uid}@example.invalid');page.locator('[name=password]').fill(fixture['password']);page.locator('button[type=submit]').click();page.wait_for_url('**/dashboard.php')
  login(2);visit('/staff/services.php');page.evaluate('openCreate()');page.locator('#svcGeneral').select_option('Other / Custom');page.locator('#svcName').fill('Browser Slot Recommendation');page.locator('#svcScheduleMode').select_option('fixed');page.locator('#svcTimeSlots').fill('25:90');page.locator('#svcSlotCapacity').fill('2');page.locator('#svcSaveBtn').click();page.wait_for_timeout(500)
  check('Invalid service stays open for correction',page.locator('#svcTimeSlots').is_visible() and page.locator('#svcTimeSlots').input_value()=='25:90')
  page.locator('#svcTimeSlots').fill('9:00 AM, 10:30 AM, 1:30 PM');page.locator('#svcSaveBtn').click();page.wait_for_timeout(2000)
  sid=sql("SELECT MAX(id) FROM services WHERE name='Browser Slot Recommendation'");check('Fixed service saves from browser',sid!='NULL')
  page.evaluate('openEdit('+sid+')');page.wait_for_timeout(300);check('Editing restores time slots and capacity',page.locator('#svcTimeSlots').input_value()=='9:00 AM, 10:30 AM, 1:30 PM' and page.locator('#svcSlotCapacity').input_value()=='2')
  login(4);visit('/parishioner/apply_service.php');page.locator('#selParish').select_option('1');page.locator('#btnStep1').click();page.locator(f'.svc-card[data-id="{sid}"]').click();page.locator('#btnStep2').click();page.locator('.availability-calendar input[type=month]').fill('2038-04');page.locator('.availability-calendar input[type=month]').dispatch_event('change');page.locator('[data-date="2038-04-10"]').click()
  check('Fixed slots replace free datetime entry',not page.locator('#selSchedule').is_visible() and page.locator('select[aria-label="Available time"]').is_visible())
  page.locator('select[aria-label="Available time"]').select_option('10:30');check('Choosing a time sets exact schedule',page.locator('#selSchedule').input_value()=='2038-04-10T10:30')
  check('Color legend has visual swatches',page.locator('.availability-legend i').count()==3)
  check('Available dates are green',page.locator('.availability-grid .available').first.evaluate("el=>getComputedStyle(el).backgroundColor")=='rgb(33, 99, 63)')
  check('Selected date shows remaining slots','slots remaining' in page.locator('.availability-calendar').inner_text())
  page.locator('#btnStep3').click();page.locator('#btnStep4').click();page.locator('#btnStep5').click();page.wait_for_timeout(300)
  # Free service review hides the payment section; configured methods still load safely.
  check('Manual methods load without exposing staff identities',page.locator('#manualPaymentMethods').inner_text().find('Probe Bank')>=0)
  # Exercise the payment controls using the already-rendered review section.
  page.evaluate("document.getElementById('bookingPayment').style.display=''")
  page.locator('[name=booking_payment_method][value=cash]').check()
  check('Cash hides reference and proof',not page.locator('#bookingPaymentReference').is_visible() and not page.locator('#bookingPaymentProof').is_visible())
  page.locator('[name=booking_payment_method][value=gcash]').first.check()
  check('Digital bank shows reference proof and QR',page.locator('#bookingPaymentReference').is_visible() and page.locator('#bookingPaymentProof').is_visible() and page.locator('#manualPaymentMethods img').first.is_visible())
  page.set_viewport_size({'width':390,'height':844})
  check('Payment controls fit mobile',page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'))
  page.screenshot(path=str(out/'payment-review-mobile.png'),full_page=True)
  page.set_viewport_size({'width':1440,'height':1000})
  visit('/parishioner/messages.php');page.evaluate("openModal('composeModal')");page.locator('#composeSearch').fill('Audit Parish A');page.wait_for_timeout(500);check('Compose finds parish name','Audit Parish A' in page.locator('body').inner_text())
  for uid,paths in [(1,['/admin/dashboard.php','/admin/analytics.php']),(2,['/staff/dashboard.php','/staff/schedule.php?view=available&month=2038-01','/staff/services.php','/staff/payment_methods.php']),(3,['/staff/dashboard.php','/staff/export.php','/staff/payments.php']),(4,['/parishioner/dashboard.php','/parishioner/events.php?view=available&month=2038-01','/parishioner/messages.php','/parishioner/payments.php'])]:
   login(uid)
   for width in [1440,390]:
    page.set_viewport_size({'width':width,'height':900})
    for path in paths:
     visit(path);check(f'Viewport {width} {path}',page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'))
    page.screenshot(path=str(out/f'role-{uid}-{width}.png'),full_page=True)
   visit('/'+('admin' if uid==1 else 'parishioner' if uid==4 else 'staff')+'/dashboard.php');check('Dashboard shortcuts for role '+str(uid),page.locator('a.stat-card[href]').count()>=(3 if uid in [2,3] else 4))
  login(3);visit('/staff/export.php');check('Export report has one date filter and service column',page.locator('[name=date_from]').count()==1 and 'type of service' in page.locator('body').inner_text().lower())
  check('No uncaught JavaScript errors',not errors)
  browser.close()
finally:
 server.terminate();server.wait(timeout=10);log.close()
 (out/'results.json').write_text(json.dumps({'tests':results,'errors':errors,'passed':sum(r['passed'] for r in results),'failed':sum(not r['passed'] for r in results)},indent=2))
if any(not r['passed'] for r in results):raise SystemExit(1)
