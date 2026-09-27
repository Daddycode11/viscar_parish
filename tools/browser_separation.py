"""Browser checks on synthetic data only; no external requests."""
import os, sys, json, subprocess, time
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'.browser-tools'))
from playwright.sync_api import sync_playwright
fixture=json.loads((ROOT/'storage/private/revision-fixture.json').read_text())
assert fixture['database'].startswith('vicar_revision_')
PHP='C:/xampp/php/php.exe'; MYSQL='C:/xampp/mysql/bin/mysql.exe'
def sql(query):
    return subprocess.check_output([MYSQL,'--host=127.0.0.1','--user=root','--batch','--skip-column-names',fixture['database'],'-e',query],text=True).strip()
sql('UPDATE users SET two_factor_enabled=0 WHERE id=4')
base='http://127.0.0.1:8772'
env=os.environ.copy();env.update(DB_NAME=fixture['database'],APP_ENV='local',APP_URL=base)
out=ROOT/'revision-evidence/separation';out.mkdir(parents=True,exist_ok=True)
log=(ROOT/'storage/private/separation-browser.log').open('w')
server=subprocess.Popen([PHP,'-d','disable_functions=mail,curl_exec,fsockopen,stream_socket_client','-S','127.0.0.1:8772','-t',str(ROOT)],cwd=ROOT,env=env,stdout=log,stderr=log)
results=[];errors=[]
def check(name,valid):
    results.append({'test':name,'passed':bool(valid)})
    print(('PASS ' if valid else 'FAIL ')+name,flush=True)
try:
    time.sleep(1)
    with sync_playwright() as pw:
        browser=pw.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe',headless=True)
        context=browser.new_context(viewport={'width':1440,'height':1000})
        context.route('**/*',lambda route:route.continue_() if route.request.url.startswith(base) or route.request.url.startswith('data:') else route.abort())
        page=context.new_page();page.on('pageerror',lambda error:errors.append(str(error)))
        def login(uid):
            page.goto(base+'/public/login.php')
            page.locator('[name=email]').fill(f'audit{uid}@example.invalid')
            page.locator('[name=password]').fill(fixture['password'])
            page.locator('button[type=submit]').click();page.wait_for_load_state()
            if 'verify_password.php' in page.url:
                page.locator('[name=password]').fill(fixture['password'])
                page.get_by_role('button',name='Verify',exact=True).click();page.wait_for_load_state()
        login(4)
        check('Browser login with CSRF','dashboard.php' in page.url)
        check('Dashboard profile image visible',page.locator('.sb-user-avatar img').evaluate('(image)=>image.complete && image.naturalWidth>0'))
        page.goto(base+'/parishioner/settings.php')
        page.locator('[name=language]').select_option('fil')
        page.locator('form').filter(has=page.locator('[name=language]')).locator('button').click()
        check('Filipino settings render after save',page.get_by_role('heading',name='Mga setting',exact=True).count()==1)
        page.screenshot(path=str(out/'settings-filipino.png'),full_page=True)
        page.goto(base+'/parishioner/faq.php')
        check('Filipino FAQ guidance visible',page.get_by_text('Paano mag-aplay?',exact=True).count()==1)
        app=sql("SELECT MAX(id) FROM applications WHERE user_id=4 AND source='walk_in'")
        page.goto(base+'/parishioner/application.php?id='+app)
        qr=page.locator('.application-qr')
        qr.wait_for(state='visible')
        check('Application QR loads in browser',qr.evaluate('(image)=>image.complete && image.naturalWidth>=200'))
        page.emulate_media(media='print')
        check('QR remains visible in print',qr.is_visible())
        page.pdf(path=str(out/'application-print.pdf'),format='A4',print_background=True)
        page.emulate_media(media='screen')
        page.wait_for_timeout(500)
        check('QR unobstructed after returning from print',qr.evaluate('(image)=>{const r=image.getBoundingClientRect();return document.elementFromPoint(r.x+r.width/2,r.y+r.height/2)===image;}'))
        page.screenshot(path=str(out/'application-qr.png'),full_page=True)
        page.goto(base+'/parishioner/settings.php');page.locator('[name=language]').select_option('en');page.locator('form').filter(has=page.locator('[name=language]')).locator('button').click()
        context.clear_cookies();login(3)
        page.goto(base+'/staff/accounting.php?type=check_voucher')
        check('All five accounting choices visible',all(page.get_by_role('link',name=label,exact=True).count() for label in ['Official Receipt','Check Voucher','Petty Cash Voucher','Disbursement','Deposits']))
        page.screenshot(path=str(out/'accounting.png'),full_page=True)
        document=sql("SELECT MIN(id) FROM accounting_documents WHERE parish_id=1 AND document_type='check_voucher'")
        page.goto(base+'/staff/accounting.php?print='+document)
        page.pdf(path=str(out/'check-voucher-print.pdf'),format='A4')
        check('Voucher print contains persisted payee','Synthetic Payee' in page.locator('body').inner_text())
        context.clear_cookies();login(2)
        page.goto(base+'/staff/walk_in.php')
        check('Walk-in includes dynamic required document',page.locator('input[name=req_1]').count()==1)
        page.screenshot(path=str(out/'walk-in.png'),full_page=True)
        check('No uncaught browser JavaScript errors',not errors)
        browser.close()
except Exception as error:
    results.append({'test':'Browser execution','passed':False,'error':str(error)})
    print(str(error),flush=True)
finally:
    server.terminate();server.wait(timeout=10);log.close()
    (out/'results.json').write_text(json.dumps({'tests':results,'javascript_errors':errors},indent=2))
if any(not row['passed'] for row in results): raise SystemExit(1)
