"""Desktop/mobile multi-selection and removal against synthetic records only."""
import os,sys,json,subprocess,time,base64
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'.browser-tools'))
from playwright.sync_api import sync_playwright
fixture=json.loads((ROOT/'storage/private/revision-fixture.json').read_text());assert fixture['database'].startswith('vicar_revision_')
BASE='http://127.0.0.1:8779';PHP='C:/xampp/php/php.exe';MYSQL='C:/xampp/mysql/bin/mysql.exe'
env=os.environ.copy();env.update(DB_NAME=fixture['database'],APP_ENV='local',APP_URL=BASE)
def sql(q):return subprocess.check_output([MYSQL,'--host=127.0.0.1','--user=root','--batch','--skip-column-names',fixture['database'],'-e',q],text=True).strip()
sql('DELETE FROM security_rate_limits');sql('UPDATE users SET two_factor_enabled=0 WHERE id IN (2,4)')
sid=sql("SELECT MAX(id) FROM services WHERE name='Attachment Test'");rid=sql(f'SELECT id FROM service_requirements WHERE service_id={sid}')
png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')
pdf=b'%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF'
files=[{'name':'front.png','mimeType':'image/png','buffer':png},{'name':'accidental.png','mimeType':'image/png','buffer':png},{'name':'support.pdf','mimeType':'application/pdf','buffer':pdf}]
results=[];errors=[]
def check(name,ok):results.append({'test':name,'passed':bool(ok)});print(('PASS ' if ok else 'FAIL ')+name,flush=True)
log=(ROOT/'storage/private/attachments-browser.log').open('w');out=ROOT/'revision-evidence/attachments';out.mkdir(exist_ok=True)
server=subprocess.Popen([PHP,'-d','disable_functions=mail,curl_exec,fsockopen,stream_socket_client','-S','127.0.0.1:8779','-t',str(ROOT)],cwd=ROOT,env=env,stdout=log,stderr=log)
try:
 time.sleep(1)
 with sync_playwright() as pw:
  browser=pw.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe',headless=True)
  page=browser.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
  page.route('**/*',lambda r:r.continue_() if r.request.url.startswith(BASE) else r.abort())
  def login(uid):
   page.goto(BASE+'/public/logout.php');page.goto(BASE+'/public/login.php');page.locator('[name=email]').fill(f'audit{uid}@example.invalid');page.locator('[name=password]').fill(fixture['password']);page.locator('[name=password]').press('Enter');page.wait_for_url('**/dashboard.php')
  for width in [1440,390]:
   page.set_viewport_size({'width':width,'height':1000});login(4)
   page.goto(BASE+'/parishioner/apply_service.php');page.locator('#selParish').select_option('1');page.locator('#btnStep1').click();page.locator(f'.svc-card[data-id="{sid}"]').click();page.locator('#btnStep2').click()
   page.locator('.availability-calendar input[type=month]').fill('2041-04');page.locator('.availability-calendar input[type=month]').dispatch_event('change');page.wait_for_timeout(300)
   day='12' if width==1440 else '13'
   page.locator(f'.availability-grid button[data-date="2041-04-{day}"]').click();page.locator('#btnStep3').click();page.locator('#btnStep4').click()
   field=page.locator(f'#req_{rid}');field.set_input_files(files)
   check(f'{width}: multiple file input',field.get_attribute('multiple') is not None)
   check(f'{width}: all filenames shown',all(name in field.locator('..').inner_text() for name in ['front.png','accidental.png','support.pdf']))
   page.get_by_role('button',name='Remove accidental.png',exact=True).click()
   check(f'{width}: remove only selected file',field.evaluate('(e)=>Array.from(e.files).map(f=>f.name)')==['front.png','support.pdf'])
   check(f'{width}: selection fits viewport',field.evaluate('(e)=>e.getBoundingClientRect().right<=innerWidth'))
   page.screenshot(path=str(out/f'selection-{width}.png'),full_page=True)
   page.locator('#btnStep5').click();check(f'{width}: review includes remaining files',all(name in page.locator('#reviewSummary').inner_text() for name in ['front.png','support.pdf']) and 'accidental.png' not in page.locator('#reviewSummary').inner_text())
   page.locator('#btnSubmit').click();page.wait_for_timeout(1000)
   app=sql(f"SELECT MAX(id) FROM applications WHERE service_id={sid} AND schedule LIKE '2041-04-{day}%'")
   check(f'{width}: both remaining files persisted',app!='NULL' and sql(f'SELECT COUNT(*) FROM application_attachments WHERE application_id={app}')=='2')
   login(2);page.goto(BASE+f'/staff/application_details.php?id={app}')
   if 'verify_password.php' in page.url:page.locator('[name=password]').fill(fixture['password']);page.locator('[name=password]').press('Enter');page.wait_for_load_state()
   check(f'{width}: Secretary sees requirement and both files',all(x in page.locator('body').inner_text() for x in ['Valid ID','front.png','support.pdf']))
   token=page.evaluate('window.csrfToken');response=page.request.post(BASE+'/staff/applications.php?ajax=request_docs',form={'id':app,'docs':'All pages','message':'Please upload both pages'},headers={'X-CSRF-Token':token});check(f'{width}: document request created',response.ok)
   req=sql(f'SELECT MAX(id) FROM application_document_requests WHERE application_id={app}')
   login(4);page.goto(BASE+'/parishioner/documents.php');form=page.locator('form').filter(has=page.locator(f'input[name=request_id][value="{req}"]'))
   form.locator('input[type=file]').set_input_files(files);form.get_by_role('button',name='Remove accidental.png',exact=True).click();form.locator('button[type=submit]').click();page.wait_for_timeout(1200)
   check(f'{width}: requested documents append both files',sql(f'SELECT COUNT(*) FROM application_attachments WHERE application_id={app}')=='4')
  check('No browser errors',not errors);browser.close()
finally:
 server.terminate();server.wait(timeout=10);log.close()
 (ROOT/'attachment-browser-results.json').write_text(json.dumps({'tests':results,'errors':errors,'passed':sum(x['passed'] for x in results),'failed':sum(not x['passed'] for x in results)},indent=2))
if any(not x['passed'] for x in results):raise SystemExit(1)
