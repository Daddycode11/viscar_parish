"""Browser verification against synthetic revision fixtures only."""
import os,sys,json,subprocess,time
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'.browser-tools'))
from playwright.sync_api import sync_playwright
PHP=r'C:\xampp\php\php.exe'
fixture=json.loads((ROOT/'storage/private/revision-fixture.json').read_text())
assert fixture['database'].startswith('vicar_revision_')
def sql(q):
 return subprocess.check_output([r'C:\xampp\mysql\bin\mysql.exe','--host=127.0.0.1','--user=root','--batch','--skip-column-names',fixture['database'],'-e',q],text=True).strip()
sql('UPDATE users SET two_factor_enabled=0 WHERE id=4')
base='http://127.0.0.1:8772';env=os.environ.copy();env.update(DB_NAME=fixture['database'],APP_ENV='local',APP_URL=base)
out=ROOT/'revision-evidence';out.mkdir(exist_ok=True)
log=(ROOT/'storage/private/browser-server.log').open('w')
server=subprocess.Popen([PHP,'-d','disable_functions=mail,curl_exec,fsockopen,stream_socket_client','-S','127.0.0.1:8772','-t',str(ROOT)],cwd=ROOT,env=env,stdout=log,stderr=log)
results=[];errors=[]
def check(name,ok):
 results.append({'test':name,'passed':bool(ok)});print(('PASS ' if ok else 'FAIL ')+name,flush=True)
try:
 time.sleep(1)
 with sync_playwright() as pw:
  browser=pw.chromium.launch(executable_path=r'C:\Program Files\Google\Chrome\Application\chrome.exe',headless=True)
  context=browser.new_context(viewport={'width':1440,'height':1000})
  context.route('**/*',lambda route:route.continue_() if route.request.url.startswith(base) or route.request.url.startswith('data:') else route.abort())
  page=context.new_page();page.on('pageerror',lambda error:errors.append(str(error)))
  page.goto(base+'/public/login.php');page.locator('input[name=email]').fill('audit4@example.invalid');page.locator('input[name=password]').fill(fixture['password']);page.locator('button[type=submit]').click();page.wait_for_url('**/parishioner/dashboard.php')
  check('Browser password login and dashboard',True)
  page.goto(base+'/parishioner/apply_service.php');page.locator('#selParish').select_option('2');page.locator('#btnStep1').click();page.locator('.svc-card').first.click();page.locator('#btnStep2').click()
  date='2032-03-21T10:00';sql("UPDATE applications SET status='rejected' WHERE schedule='2032-03-21 10:00:00' AND service_id=2")
  page.locator('#selSchedule').fill(date);page.locator('#selSchedule').dispatch_event('change');page.wait_for_timeout(500);page.locator('#btnStep3').click();page.locator('#btnStep4').click();page.locator('#btnStep5').click();page.locator('#btnSubmit').click();page.locator('#step7').wait_for(state='visible')
  check('Seven-step browser booking persists',sql("SELECT COUNT(*) FROM applications WHERE user_id=4 AND service_id=2 AND status='pending' AND schedule='2032-03-21 10:00:00'")=='1')
  page.locator('.payment-option[data-method=cash]').click();page.locator('#btnPay').click();page.wait_for_timeout(800)
  check('Browser cash payment persists pending verification',sql("SELECT COUNT(*) FROM payments p JOIN applications a ON a.id=p.application_id WHERE a.user_id=4 AND a.service_id=2 AND a.schedule='2032-03-21 10:00:00' AND p.status='pending'")=='1')
  page.screenshot(path=str(out/'booking-confirmation.png'),full_page=True)
  for name in ['dashboard','events','help','requests','payments','documents']:
   page.goto(base+'/parishioner/'+name+'.php');page.wait_for_timeout(300);page.screenshot(path=str(out/(name+'.png')),full_page=True)
  check('Browser pages have no uncaught JavaScript errors',not errors)
  browser.close()
except Exception as error:
 results.append({'test':'Browser execution','passed':False,'error':str(error)})
 print(str(error),flush=True)
finally:
 server.terminate();server.wait(timeout=10);log.close()
 (out/'browser-results.json').write_text(json.dumps({'tests':results,'javascript_errors':errors},indent=2))
 if any(not r['passed'] for r in results):sys.exit(1)
