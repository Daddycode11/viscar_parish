"""Targeted browser/API regressions against the latest isolated synthetic fixture."""
import os, sys, json, subprocess, time, re, urllib.parse
from pathlib import Path
ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / '.browser-tools'))
from playwright.sync_api import sync_playwright
fixture = json.loads((ROOT / 'storage/private/revision-fixture.json').read_text())
assert fixture['database'].startswith('vicar_revision_')
PHP = 'C:/xampp/php/php.exe'
MYSQL = 'C:/xampp/mysql/bin/mysql.exe'
base = 'http://127.0.0.1:8774'
env = os.environ.copy()
env.update(DB_NAME=fixture['database'], APP_ENV='local', APP_URL=base)
out = ROOT / 'revision-evidence/navigation'
out.mkdir(parents=True, exist_ok=True)
results, errors = [], []
def check(name, valid):
    results.append({'test':name, 'passed':bool(valid)})
    print(('PASS ' if valid else 'FAIL ') + name, flush=True)
def sql(query):
    return subprocess.check_output([MYSQL, '--host=127.0.0.1', '--user=root', '--batch', '--skip-column-names', fixture['database'], '-e', query], text=True).strip()
def session_value(context, key, value):
    sid = next(c['value'] for c in context.cookies() if c['name'] == 'PHPSESSID')
    test_env = env.copy(); test_env['TEST_SESSION_ID'] = sid
    code = "session_save_path('storage/private/sessions'); session_id(getenv('TEST_SESSION_ID')); session_start(); $_SESSION['"+key+"']="+str(value)+"; session_write_close();"
    subprocess.run([PHP, '-r', code], env=test_env, cwd=ROOT, check=True, capture_output=True)
sql("UPDATE users SET two_factor_enabled=0,language='en' WHERE id IN (1,2,3,4)")
sql('DELETE FROM security_rate_limits')
log = (ROOT / 'storage/private/navigation-browser.log').open('w')
server = subprocess.Popen([PHP, '-d', 'disable_functions=mail,curl_exec,fsockopen,stream_socket_client', '-S', '127.0.0.1:8774', '-t', str(ROOT)],cwd=ROOT,env=env,stdout=log,stderr=log)
try:
    time.sleep(1)
    with sync_playwright() as pw:
        browser=pw.chromium.launch(executable_path='C:/Program Files/Google/Chrome/Application/chrome.exe',headless=True)
        context=browser.new_context(viewport={'width':1440,'height':1000})
        context.route('**/*',lambda route:route.continue_() if route.request.url.startswith(base) or route.request.url.startswith('data:') else route.abort())
        page=context.new_page();page.on('pageerror',lambda error:errors.append(str(error)))
        def login(uid):
            page.goto(base+'/public/login.php');page.locator('[name=email]').fill(f'audit{uid}@example.invalid');page.locator('[name=password]').fill(fixture['password'])
            page.locator('button[type=submit]').click();page.wait_for_url('**/dashboard.php')
        login(4)
        check('Shared page has visible Back',page.locator('[data-app-back]').is_visible())
        page.goto(base+'/parishioner/payments.php?status=paid')
        page.goto(base+'/parishioner/settings.php')
        page.locator('[data-app-back]').click();page.wait_for_url('**/payments.php?status=paid')
        check('Back preserves previous URL and filters','status=paid' in page.url)
        page.go_forward();check('Normal browser Forward remains available','settings.php' in page.url)
        direct=context.new_page();direct.goto(base+'/parishioner/settings.php');direct.locator('[data-app-back]').click();direct.wait_for_url('**/dashboard.php')
        check('Direct tab has a working dashboard fallback','dashboard.php' in direct.url);direct.close()
        page.goto(base+'/parishioner/apply_service.php')
        page.locator('#selParish').select_option('1');page.locator('#btnStep1').click();page.locator('.svc-card[data-id="1"]').click();page.locator('#btnStep2').click()
        if page.locator('#requirementNotesStep').is_visible():page.get_by_role('button',name='Continue to schedule').click()
        page.locator('#selSchedule').fill('2035-04-01T09:00');page.locator('#btnStep3').click()
        field=page.locator('#dynamicForm input').first;field.wait_for();field.fill('Preserved draft')
        # Back is a wizard action here; no new history entries or persisted private drafts.
        page.locator('[data-app-back]').click();check('Wizard Back returns to schedule',page.locator('#step3').is_visible())
        page.locator('#btnStep3').click();check('Wizard Back preserves entered fields',field.input_value()=='Preserved draft')
        page.locator('#btnStep4').click()
        upload=page.locator('#requirementsList input[type=file]').first
        upload.set_input_files({'name':'draft.png','mimeType':'image/png','buffer':b'draft-only-test'})
        page.locator('[data-app-back]').click();page.locator('#btnStep4').click()
        check('Wizard Back preserves selected file',upload.evaluate('(e)=>e.files[0].name')=='draft.png')
        page.set_viewport_size({'width':390,'height':844});page.wait_for_timeout(400)
        check('Mobile Back is visible and fits viewport',page.locator('[data-app-back]').is_visible() and page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
        page.screenshot(path=str(out/'wizard-mobile.png'),full_page=True)
        page.set_viewport_size({'width':1440,'height':1000})
        login(2);page.goto(base+'/staff/messages.php')
        page.get_by_role('button',name='Compose',exact=False).click();page.locator('#composeBody').fill('Keep this draft')
        page.locator('#composeModal .dialog-return').click();check('Close hides modal',not page.locator('#composeModal').is_visible())
        page.get_by_role('button',name='Compose',exact=False).click();check('Reopening modal retains draft',page.locator('#composeBody').input_value()=='Keep this draft')
        page.keyboard.press('Escape');check('Escape closes modal',not page.locator('#composeModal').is_visible())
        check('Close returns focus to opener','Compose' in page.evaluate('document.activeElement.textContent'))
        login(1)
        doc=sql("SELECT id,uploaded_files FROM applications WHERE uploaded_files IS NOT NULL AND uploaded_files NOT IN ('{}','[]','') LIMIT 1").split('\t',1)
        key=next(iter(json.loads(doc[1])))
        page.goto(base+'/public/document_view.php?app='+doc[0]+'&key='+urllib.parse.quote(key))
        check('Document viewer exposes Back and Download',page.locator('[data-app-back]').is_visible() and page.get_by_role('link',name='Download document').is_visible())
        page.screenshot(path=str(out/'document-viewer.png'))
        login(3);page.goto(base+'/staff/accounting.php')
        page.locator('[name=password]').fill(fixture['password']);page.get_by_role('button',name='Verify',exact=True).click();page.wait_for_load_state()
        print_id=sql('SELECT MIN(id) FROM accounting_documents WHERE parish_id=1')
        popup=context.new_page();popup.goto(base+'/staff/accounting.php?print='+print_id)
        check('Standalone print has visible Back',popup.locator('[data-app-back]').is_visible())
        popup.emulate_media(media='print');check('Back is hidden from printed output',not popup.locator('[data-app-back]').is_visible());popup.emulate_media(media='screen')
        popup.locator('[data-app-back]').click();popup.wait_for_url('**/accounting.php');check('Direct print tab Back has useful fallback','accounting.php' in popup.url);popup.close()
        # Cancelling a pending OTP must return to login and invalidate that challenge.
        sql('UPDATE users SET two_factor_enabled=1 WHERE id=4')
        page.goto(base+'/public/login.php');page.locator('[name=email]').fill('audit4@example.invalid');page.locator('[name=password]').fill(fixture['password'])
        page.locator('button[type=submit]').click();page.wait_for_url('**/verify_otp.php')
        page.locator('[name=cancel_login]').click();page.wait_for_url('**/public/login.php')
        page.goto(base+'/verify_otp.php');check('Cancel sign-in clears pending OTP and returns to login','public/login.php' in page.url)
        sql('UPDATE users SET two_factor_enabled=0 WHERE id=4')
        response=context.request.get(base+'/public/document_view.php?app=1&key=req_1')
        check('Anonymous document viewer denied',response.status==401)
        check('No JavaScript errors',not errors)
        browser.close()
except Exception as error:
    results.append({'test':'Browser execution','passed':False,'error':str(error)});print(str(error),flush=True)
finally:
    server.terminate();server.wait(timeout=10);log.close()
    (out/'results.json').write_text(json.dumps({'tests':results,'javascript_errors':errors},indent=2))
if any(not r['passed'] for r in results):raise SystemExit(1)
