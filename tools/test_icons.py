"""Verify static and dynamic SVG rendering in the synthetic browser environment."""
import os,sys,json,subprocess,time
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'.browser-tools'))
from playwright.sync_api import sync_playwright
fixture=json.loads((ROOT/'storage/private/revision-fixture.json').read_text())
assert fixture['database'].startswith('vicar_revision_')
base='http://127.0.0.1:8773';env=os.environ.copy();env.update(DB_NAME=fixture['database'],APP_ENV='local',APP_URL=base)
out=ROOT/'revision-evidence/icons';out.mkdir(parents=True,exist_ok=True)
log=(ROOT/'storage/private/icon-browser.log').open('w')
server=subprocess.Popen([r'C:\xampp\php\php.exe','-d','disable_functions=mail,curl_exec,fsockopen,stream_socket_client','-S','127.0.0.1:8773','-t',str(ROOT)],cwd=ROOT,env=env,stdout=log,stderr=log)
results=[];errors=[]
try:
 time.sleep(1)
 with sync_playwright() as pw:
  browser=pw.chromium.launch(executable_path=r'C:\Program Files\Google\Chrome\Application\chrome.exe',headless=True)
  def context():
   ctx=browser.new_context(viewport={'width':1440,'height':1000})
   ctx.route('**/*',lambda r:r.continue_() if r.request.url.startswith(base) or r.request.url.startswith('data:') else r.abort())
   page=ctx.new_page();page.on('pageerror',lambda e:errors.append(str(e)));return ctx,page
  def verify(page,name):
   page.wait_for_timeout(350)
   state=page.evaluate(r'''() => ({svg:document.querySelectorAll('svg.ui-icon').length,markers:document.body.innerText.includes('[icon:'),emoji:/[\u{1F000}-\u{1FAFF}\u2600-\u27BF\u2B50]/u.test(document.body.innerText)})''')
   results.append({'page':name,**state,'passed':(state['svg']>0 or name=='public/signup.php') and not state['markers'] and not state['emoji']})
   print(name,state,flush=True)
  ctx,page=context()
  for path in ['loading.php','index.php','index.php','public/login.php','public/signup.php']:
   page.goto(base+'/'+path);verify(page,path)
  ctx.close()
  for role,uid,folder,pages in [('admin',1,'admin',['dashboard','applications','reports','announcements','users']),('secretary',2,'staff',['dashboard','applications','records','messages','schedule','services']),('bookkeeper',3,'staff',['dashboard','payments','receipts','finance']),('parishioner',4,'parishioner',['dashboard','apply_service','notifications','messages','help'])]:
   ctx,page=context();page.goto(base+'/public/login.php');page.locator('input[name=email]').fill(f'audit{uid}@example.invalid');page.locator('input[name=password]').fill(fixture['password']);page.locator('button[type=submit]').click();page.wait_for_url('**/'+folder+'/dashboard.php')
   for name in pages:
    page.goto(base+'/'+folder+'/'+name+'.php');verify(page,role+'/'+name)
    if name=='dashboard':page.screenshot(path=str(out/(role+'.png')),full_page=True)
   page.evaluate("const b=document.createElement('button');b.id='icon-probe';b.textContent='[icon:check] Updated';document.body.append(b)")
   page.wait_for_timeout(100);results.append({'page':role+'/dynamic-text','passed':page.locator('#icon-probe svg[data-icon=check]').count()==1})
   ctx.close()
  browser.close()
finally:
 server.terminate();server.wait(timeout=10);log.close()
 report={'tests':results,'javascript_errors':errors,'passed':sum(r['passed'] for r in results),'failed':sum(not r['passed'] for r in results)}
 (out/'results.json').write_text(json.dumps(report,indent=2))
 print('Icon checks:',report['passed'],'passed,',report['failed'],'failed; JS errors:',len(errors))
 if report['failed'] or errors:sys.exit(1)
