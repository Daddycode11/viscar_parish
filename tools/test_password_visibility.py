"""Visibility presentation checks using synthetic accounts; no password-changing POSTs."""
import os,sys,json,subprocess,time,re
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'.browser-tools'))
from playwright.sync_api import sync_playwright
fixture=json.loads((ROOT/'storage/private/revision-fixture.json').read_text())
assert fixture['database'].startswith('vicar_revision_')
BASE='http://127.0.0.1:8778';PHP='C:/xampp/php/php.exe';MYSQL='C:/xampp/mysql/bin/mysql.exe'
env=os.environ.copy();env.update(DB_NAME=fixture['database'],APP_ENV='local',APP_URL=BASE)
subprocess.run([MYSQL,'--host=127.0.0.1','--user=root',fixture['database'],'-e',"UPDATE users SET two_factor_enabled=0,language='en',status='active' WHERE id IN (1,2,3,4); DELETE FROM security_rate_limits"],check=True)
results=[];errors=[]
def check(name,ok):
 results.append({'test':name,'passed':bool(ok)});print(('PASS ' if ok else 'FAIL ')+name,flush=True)
log=(ROOT/'storage/private/password-browser.log').open('w')
server=subprocess.Popen([PHP,'-d','disable_functions=mail,curl_exec,fsockopen,stream_socket_client','-S','127.0.0.1:8778','-t',str(ROOT)],cwd=ROOT,env=env,stdout=log,stderr=log)
try:
 time.sleep(1)
 with sync_playwright() as pw:
  browser=pw.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe',headless=True)
  page=browser.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
  page.route('**/*',lambda r:r.continue_() if r.request.url.startswith(BASE) else r.abort())
  def audit(path,width):
   fields=page.locator('[data-password-visibility]');count=fields.count()
   check(f'{path} {width}: fields enhanced',count>0 and count==page.locator('.password-visibility-toggle').count())
   for i in range(count):
    field=fields.nth(i);button=field.locator('..').locator('button');name=field.get_attribute('name');key=f'{path} {width} {name}'
    check(key+' concealed by default',field.get_attribute('type')=='password')
    field.fill('Visibility-Test-42!')
    before=field.evaluate('(e)=>({value:e.value,valid:e.checkValidity(),required:e.required,min:e.minLength,max:e.maxLength,autocomplete:e.autocomplete})')
    # Trap submissions to ensure the toggle cannot send a form.
    field.evaluate("e=>{window.visibilitySubmits=0;if(!e.form.dataset.testSubmit){e.form.dataset.testSubmit='1';e.form.addEventListener('submit',ev=>{ev.preventDefault();window.visibilitySubmits++})}}")
    button.click();check(key+' reveals only selected field',field.get_attribute('type')=='text' and fields.evaluate_all('(es)=>es.filter(e=>e.type==="text").length')==1)
    button.focus();button.press('Space');check(key+' keyboard hides',field.get_attribute('type')=='password')
    after=field.evaluate('(e)=>({value:e.value,valid:e.checkValidity(),required:e.required,min:e.minLength,max:e.maxLength,autocomplete:e.autocomplete})')
    check(key+' unchanged value/validation and no submit',before==after and page.evaluate('visibilitySubmits')==0)
    check(key+' accessible control',button.get_attribute('type')=='button' and button.get_attribute('aria-controls')==field.get_attribute('id') and button.get_attribute('aria-label').startswith('Show password'))
    # Isolate implicit Enter submission from unrelated required inputs, preserving the form setting.
    old=field.evaluate('e=>{const old=e.form.noValidate;e.form.noValidate=true;return old}')
    field.press('Enter');check(key+' Enter submits',page.evaluate('visibilitySubmits')==1)
    field.evaluate('(e,old)=>e.form.noValidate=old',old)
    field.fill('');button.click();check(key+' required validation retained while visible',field.evaluate('e=>!e.required || !e.checkValidity()'));button.click()
    box=button.bounding_box();check(key+' mobile-sized target',box['width']>=44 and box['height']>=44)
   check(f'{path} {width}: fields fit viewport',fields.evaluate_all('(es)=>es.every(e=>e.getBoundingClientRect().right<=innerWidth && e.getBoundingClientRect().left>=0)'))
  for width in [1440,390]:
   page.set_viewport_size({'width':width,'height':1000})
   for path in ['/public/login.php','/public/signup.php','/public/reset_password.php']:
    page.goto(BASE+path);audit(path,width)
   for uid,role in [(1,'admin'),(2,'staff'),(3,'staff'),(4,'parishioner')]:
    page.goto(BASE+'/public/logout.php');page.goto(BASE+'/public/login.php')
    page.locator('[name=email]').fill(f'audit{uid}@example.invalid');page.locator('[name=password]').fill(fixture['password'])
    page.locator('.password-visibility-toggle').click();page.locator('[name=password]').press('Enter');page.wait_for_url('**/dashboard.php')
    check(f'{uid} {width}: real login Enter with visible password',page.url.endswith('/dashboard.php'))
    paths=[f'/{role}/settings.php',f'/{role}/security.php']
    if uid in [1,2,3]:paths.append(f'/{role}/verify_password.php')
    if uid==1:paths.append('/admin/users.php?action=add')
    for path in paths:
     page.goto(BASE+path);audit(path+f' role {uid}',width)
   # Setup routes intentionally redirect when an admin exists. Exercise their actual
   # form markup without disabling server guards or submitting setup.
   for setup in ['public/setup_admin.php','admin/setup_admin.php']:
    source=(ROOT/setup).read_text(encoding='utf-8');markup=source[source.index('<form'):source.index('</form>')+7]
    markup=re.sub(r'<\?php.*?\?>|<\?=.*?\?>','',markup,flags=re.S)
    page.goto(BASE+'/public/login.php')
    styles=re.search(r'<style>(.*?)</style>',source,re.S).group(1)
    container='setup-container' if setup.startswith('public') else 'container'
    page.evaluate('(data)=>{const style=document.createElement("style");style.textContent=data.styles;document.head.append(style);document.body.innerHTML=data.markup}',{'styles':styles,'markup':f'<div class="{container}">{markup}</div>'});page.wait_for_timeout(100)
    audit(setup+' guarded template',width)
  check('No browser script errors',not errors)
  browser.close()
finally:
 server.terminate();server.wait(timeout=10);log.close()
 (ROOT/'password-visibility-results.json').write_text(json.dumps({'results':results,'errors':errors,'passed':sum(r['passed'] for r in results),'failed':sum(not r['passed'] for r in results)},indent=2))
if any(not r['passed'] for r in results):raise SystemExit(1)
