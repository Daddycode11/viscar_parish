"""Browser acceptance checks against the latest synthetic database only."""
import os,sys,json,subprocess,time
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'.browser-tools'))
from playwright.sync_api import sync_playwright
fixture=json.loads((ROOT/'storage/private/revision-fixture.json').read_text())
assert fixture['database'].startswith('vicar_revision_')
PHP='C:/xampp/php/php.exe';MYSQL='C:/xampp/mysql/bin/mysql.exe';BASE='http://127.0.0.1:8776'
env=os.environ.copy();env.update(DB_NAME=fixture['database'],APP_ENV='local',APP_URL=BASE)
def sql(query):return subprocess.check_output([MYSQL,'--host=127.0.0.1','--user=root','--batch','--skip-column-names',fixture['database'],'-e',query],text=True).strip()
sql("UPDATE users SET two_factor_enabled=0,language='en',status='active' WHERE id IN (1,2,3,4)")
sql('DELETE FROM security_rate_limits')
sql("UPDATE service_fields SET field_label='Child Name' WHERE id=1")
out=ROOT/'revision-evidence/master';out.mkdir(exist_ok=True)
results=[];errors=[]
def check(name,value):results.append({'test':name,'passed':bool(value)});print(('PASS ' if value else 'FAIL ')+name,flush=True)
log=(ROOT/'storage/private/master-browser.log').open('w')
server=subprocess.Popen([PHP,'-d','disable_functions=mail,curl_exec,fsockopen,stream_socket_client','-S','127.0.0.1:8776','-t',str(ROOT)],cwd=ROOT,env=env,stdout=log,stderr=log)
try:
 time.sleep(1)
 with sync_playwright() as pw:
  browser=pw.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe',headless=True)
  context=browser.new_context(viewport={'width':1440,'height':1000})
  context.route('**/*',lambda r:r.continue_() if r.request.url.startswith(BASE) or r.request.url.startswith('data:') else r.abort())
  page=context.new_page();page.on('pageerror',lambda error:errors.append(str(error)))
  def login(uid):
   page.goto(BASE+'/public/logout.php');page.goto(BASE+'/public/login.php');page.locator('[name=email]').fill(f'audit{uid}@example.invalid');page.locator('[name=password]').fill(fixture['password']);page.locator('button[type=submit]').click();page.wait_for_url('**/dashboard.php')
  def visit(path):
   page.goto(BASE+path)
   if 'verify_password.php' in page.url:
    page.locator('[name=password]').fill(fixture['password']);page.get_by_role('button',name='Verify',exact=True).click();page.wait_for_load_state()
   page.wait_for_timeout(150)
  login(2);visit('/staff/services.php')
  page.evaluate("openFields(1,'Baptism')");page.locator('#fieldsList button').first.wait_for();page.locator('#fieldsList button').first.click()
  check('Form builder Edit loads selected field',page.locator('#fieldLabel').input_value()=='Child Name')
  page.locator('#fieldLabel').fill('Child "Full Name"');page.locator('#fieldSaveBtn').click();page.wait_for_timeout(500)
  check('Browser form edit persists',sql("SELECT field_label FROM service_fields WHERE id=1")=='Child "Full Name"')
  page.locator('#fieldsList button').first.click();check('Quoted field label reopens safely',page.locator('#fieldLabel').input_value()=='Child "Full Name"')
  visit('/staff/services.php');page.evaluate('openCreate()');page.locator('#svcGeneral').select_option('Other / Custom');page.locator('#svcName').fill('Browser Custom Blessing');page.locator('#svcClassification').select_option('Non-Sacramental');page.locator('#svcMode').select_option('user_defined');page.locator('#svcSaveBtn').click();page.wait_for_timeout(1200)
  service=sql("SELECT MAX(id) FROM services WHERE name='Browser Custom Blessing'")
  check('Browser custom service creation',service!='NULL')
  login(4);visit('/parishioner/apply_service.php');page.locator('#selParish').select_option('1');page.locator('#btnStep1').click();page.locator(f'.svc-card[data-id="{service}"]').click();page.locator('#btnStep2').click();page.locator('.availability-grid button').first.wait_for()
  check('Availability calendar renders on service selection',page.locator('.availability-grid button').count()>=28)
  page.locator('.availability-calendar input[type=month]').fill('2037-04');page.locator('.availability-calendar input[type=month]').dispatch_event('change');page.wait_for_timeout(300);page.locator('.availability-grid button[data-date="2037-04-10"]').click();page.locator('#btnStep3').click();page.locator('#btnStep4').click();page.locator('#btnStep5').click();page.locator('#userAmount').fill('42.25');page.locator('#userAmount').dispatch_event('change')
  check('User-defined amount control validates cents',page.locator('#userAmount').input_value()=='42.25')
  # Submit through the existing browser confirmation control.
  page.locator('#btnSubmit').click();page.wait_for_timeout(1200)
  check('Browser booking stores selected amount',sql(f"SELECT fee_snapshot FROM applications WHERE service_id={service} ORDER BY id DESC LIMIT 1")=='42.25')
  for uid,paths in [(1,['/admin/dashboard.php','/admin/analytics.php','/admin/main_database.php','/admin/announcements.php','/admin/backup.php']),(2,['/staff/services.php','/staff/applications.php','/staff/application_details.php?id=1','/staff/parishioners.php','/staff/schedule.php','/staff/checkin.php']),(3,['/staff/finance.php','/staff/accounting.php?type=check_voucher','/staff/accounting.php?type=petty_cash_voucher','/staff/accounting.php?type=journal_voucher','/staff/accounting_report.php']),(4,['/parishioner/dashboard.php','/parishioner/events.php','/parishioner/requests.php?type=cancel'])]:
   login(uid)
   for width in [1440,768,390]:
    page.set_viewport_size({'width':width,'height':1000})
    for path in paths:
     visit(path);check(f'Responsive {width} {path}',page.evaluate('document.documentElement.scrollWidth<=innerWidth+2') and page.locator('body').inner_text().strip()!='')
    page.screenshot(path=str(out/f'role-{uid}-{width}.png'),full_page=True)
  page.set_viewport_size({'width':1440,'height':1000});visit('/index.php');visit('/index.php');page.screenshot(path=str(out/'homepage.png'),full_page=True)
  check('Homepage logo retains proportions',page.locator('.logo-img').evaluate("e=>getComputedStyle(e).objectFit==='contain'"))
  login(3);visit('/staff/accounting_report.php?document_type=check_voucher&date_from=2030-01-01&date_to=2040-01-01');page.pdf(path=str(out/'accounting-report.pdf'),format='A4',print_background=True)
  check('Accounting report PDF generated',(out/'accounting-report.pdf').stat().st_size>1000)
  check('No uncaught browser errors',not errors)
  browser.close()
finally:
 server.terminate();server.wait(timeout=10);log.close()
 (out/'results.json').write_text(json.dumps({'tests':results,'errors':errors,'passed':sum(r['passed'] for r in results),'failed':sum(not r['passed'] for r in results)},indent=2))
if any(not r['passed'] for r in results):raise SystemExit(1)

