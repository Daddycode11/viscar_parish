"""AM/PM, Secretary form and Bookkeeper browser regression on synthetic data only."""
import os,sys,json,subprocess,time
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'.browser-tools'))
from playwright.sync_api import sync_playwright
fixture=json.loads((ROOT/'storage/private/revision-fixture.json').read_text());assert fixture['database'].startswith('vicar_revision_')
BASE='http://127.0.0.1:8780';env=os.environ.copy();env.update(DB_NAME=fixture['database'],APP_ENV='local',APP_URL=BASE)
out=ROOT/'revision-evidence/reported-issues';out.mkdir(parents=True,exist_ok=True);results=[];errors=[]
def sql(query):return subprocess.check_output(['C:/xampp/mysql/bin/mysql.exe','--host=127.0.0.1','--user=root','--batch','--skip-column-names',fixture['database'],'-e',query],text=True).strip()
sql("UPDATE users SET two_factor_enabled=0,language='en',status='active' WHERE id IN (1,2,3,4)");sql('DELETE FROM security_rate_limits')
def check(name,ok):results.append({'test':name,'passed':bool(ok)});print(('PASS ' if ok else 'FAIL ')+name,flush=True)
log=(ROOT/'storage/private/reported-browser.log').open('w')
server=subprocess.Popen(['C:/xampp/php/php.exe','-d','disable_functions=mail,curl_exec,fsockopen,stream_socket_client','-S','127.0.0.1:8780','-t',str(ROOT)],cwd=ROOT,env=env,stdout=log,stderr=log)
try:
 time.sleep(1)
 with sync_playwright() as pw:
  browser=pw.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe',headless=True)
  context=browser.new_context(viewport={'width':1440,'height':1000},timezone_id='Asia/Manila')
  context.route('**/*',lambda route:route.continue_() if route.request.url.startswith(BASE) or route.request.url.startswith('data:') else route.abort())
  page=context.new_page();page.on('pageerror',lambda error:errors.append(str(error)));page.on('dialog',lambda dialog:dialog.accept())
  def visit(path):
   page.goto(BASE+path)
   if 'verify_password.php' in page.url:
    page.locator('[name=password]').fill(fixture['password']);page.get_by_role('button',name='Verify',exact=True).click();page.wait_for_load_state()
   page.wait_for_timeout(150)
  def login(uid):
   visit('/public/logout.php');visit('/public/login.php');page.locator('[name=email]').fill(f'audit{uid}@example.invalid');page.locator('[name=password]').fill(fixture['password']);page.locator('button[type=submit]').click();page.wait_for_url('**/dashboard.php')
  def set_time(group,hour,minute,period,date=None):
   if date:group.locator('input[type=date]').fill(date)
   group.locator('select').nth(0).select_option(str(hour));group.locator('select').nth(1).select_option(minute);group.locator('select').nth(2).select_option(period)
  login(2);visit('/staff/services.php');page.evaluate('openCreate()');page.locator('#svcGeneral').select_option('Other / Custom');page.locator('#svcName').fill('Browser AM PM Issue');page.locator('#svcClassification').select_option('Non-Sacramental');page.locator('#svcScheduleMode').select_option('fixed');page.locator('#svcTimeSlots').fill('13:00 PM');page.locator('#svcSaveBtn').click();page.locator('#svcTimeSlotsError').wait_for()
  check('Service field validation preserves entered data',page.locator('#svcName').input_value()=='Browser AM PM Issue' and page.locator('#svcTimeSlots').input_value()=='13:00 PM')
  slots='12:00 AM, 11:59 AM, 12:00 PM, 12:01 PM, 1:00 PM, 11:59 PM';page.locator('#svcTimeSlots').fill(slots);page.locator('#svcSaveBtn').click();page.wait_for_timeout(1600)
  sid=sql("SELECT MAX(id) FROM services WHERE name='Browser AM PM Issue'");check('Secretary creates service after AM/PM correction',sid!='NULL')
  visit('/staff/services.php');page.evaluate('openEdit('+sid+')');page.locator('#svcTimeSlots').wait_for();check('Service edit displays all times in AM/PM',page.locator('#svcTimeSlots').input_value()==slots)
  page.locator('#svcDescription').fill('Edited without changing appointment hours');page.locator('#svcSaveBtn').click();page.wait_for_timeout(1500)
  check('Editing preserves exact stored slot values',json.loads(sql(f'SELECT time_slots FROM services WHERE id={sid}'))==['00:00','11:59','12:00','12:01','13:00','23:59'])
  visit('/staff/services.php');page.evaluate('openFields('+sid+',"AM PM Issue")');page.locator('#fieldLabel').fill('1st contact');check('Digit-leading label generates a valid field name',page.locator('#fieldName').input_value()=='field_1st_contact')
  page.locator('#fieldName').fill('1invalid');page.locator('#fieldSaveBtn').click();page.locator('#fieldNameError').wait_for();check('Form field failure retains label',page.locator('#fieldLabel').input_value()=='1st contact')
  page.locator('#fieldName').fill('first_contact');page.locator('#fieldSaveBtn').click();page.wait_for_timeout(450)
  fid=sql(f'SELECT MAX(id) FROM service_fields WHERE service_id={sid}');check('Secretary creates form field after correction',fid!='NULL')
  page.locator('#fieldsList button').first.click();page.locator('#fieldLabel').fill('Updated "contact"');page.locator('#fieldSaveBtn').click();page.wait_for_timeout(450);check('Secretary edits quoted field label',sql(f'SELECT field_label FROM service_fields WHERE id={fid}')=='Updated "contact"')
  visit('/staff/schedule.php');page.evaluate('openCreateEvent()');group=page.locator('[data-time-for="eventDate"]')
  for hour,minute,period,stored in [(12,'00','AM','00:00'),(12,'01','AM','00:01'),(11,'59','AM','11:59'),(12,'00','PM','12:00'),(12,'01','PM','12:01'),(1,'00','PM','13:00'),(11,'59','PM','23:59')]:
   set_time(group,hour,minute,period,'2038-01-02');check('Picker '+str(hour)+':'+minute+' '+period,page.locator('#eventDate').input_value()=='2038-01-02T'+stored)
  set_time(group,12,'00','AM','2038-01-02');page.locator('#eventTitle').fill('Midnight Issue Event');page.locator('#eventSaveBtn').click();page.wait_for_timeout(1500)
  eid=sql("SELECT MAX(id) FROM events WHERE title='Midnight Issue Event'");check('Midnight event saves without shifting date',eid!='NULL' and sql('SELECT event_date FROM events WHERE id='+eid)=='2038-01-02 00:00:00')
  visit('/staff/schedule.php');page.evaluate('editEvent('+eid+')');page.locator('#eventTitle').wait_for();check('Event edit restores 12 AM',page.locator('[data-time-for="eventDate"] select').nth(0).input_value()=='12' and page.locator('[data-time-for="eventDate"] select').nth(2).input_value()=='AM')
  visit('/staff/dashboard.php');page.evaluate("openScheduleModal(1,'2038-01-02 13:30:00')");check('Reschedule editor does not convert parish time to UTC',page.locator('#scheduleInput').input_value()=='2038-01-02T13:30' and page.locator('[data-time-for="scheduleInput"] select').nth(2).input_value()=='PM')
  page.set_viewport_size({'width':390,'height':900});page.screenshot(path=str(out/'secretary-time-mobile.png'),full_page=True);check('Mobile time picker fits viewport',page.evaluate('document.documentElement.scrollWidth<=innerWidth+2'))
  login(4);visit('/parishioner/apply_service.php');page.locator('#selParish').select_option('1');page.locator('#btnStep1').click();page.locator(f'.svc-card[data-id="{sid}"]').click();page.locator('#btnStep2').click();page.locator('.availability-calendar input[type=month]').fill('2038-01');page.locator('.availability-calendar input[type=month]').dispatch_event('change');page.locator('[data-date="2038-01-02"]').click();times=page.locator('select[aria-label="Available time"]');check('Available slots visibly distinguish midnight and noon','12:00 AM' in times.inner_text() and '12:00 PM' in times.inner_text())
  times.select_option('12:00');check('Parishioner noon selection keeps noon value',page.locator('#selSchedule').input_value()=='2038-01-02T12:00')
  login(3);visit('/staff/payments.php');check('Bookkeeper loads records','All Payments' in page.locator('body').inner_text());visit('/staff/payments.php?q=NoMatchingPaymentForBrowser');check('Bookkeeper gets useful empty state','No payments found.' in page.locator('body').inner_text());page.screenshot(path=str(out/'bookkeeper-empty-mobile.png'),full_page=True)
  login(1);visit('/admin/finance.php');check('Admin finance remains available','Unable to process' not in page.locator('body').inner_text())
  check('No uncaught browser errors',not errors);browser.close()
finally:
 server.terminate();server.wait(timeout=10);log.close()
 (out/'results.json').write_text(json.dumps({'tests':results,'errors':errors,'passed':sum(r['passed'] for r in results),'failed':sum(not r['passed'] for r in results)},indent=2))
if any(not r['passed'] for r in results):raise SystemExit(1)
