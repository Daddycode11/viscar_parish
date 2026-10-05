"""Integration checks against a new synthetic database; never changes local parish records."""
import os, json, re, subprocess, time, urllib.request, urllib.parse, urllib.error, http.cookiejar, base64
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
PHP=r'C:\xampp\php\php.exe';MYSQL=r'C:\xampp\mysql\bin\mysql.exe';BASE='http://127.0.0.1:8771'
OUT=ROOT/'storage/private';OUT.mkdir(parents=True,exist_ok=True)
subprocess.run([PHP,str(ROOT/'tools/revision_fixture.php')],check=True,cwd=ROOT)
fixture=json.loads((OUT/'revision-fixture.json').read_text())
env=os.environ.copy();env.update(DB_NAME=fixture['database'],APP_ENV='local',APP_URL=BASE)
subprocess.run([PHP,str(ROOT/'tools/setup_local.php')],check=True,env=env,cwd=ROOT)
subprocess.run([PHP,str(ROOT/'tools/migrate_recommendations.php')],check=True,env=env,cwd=ROOT)
subprocess.run([PHP,str(ROOT/'tools/migrate_recommendations.php')],check=True,env=env,cwd=ROOT)
def sql(q):return subprocess.check_output([MYSQL,'--host=127.0.0.1','--user=root','--batch','--skip-column-names',fixture['database'],'-e',q],text=True).strip()
class Client:
 def __init__(self):self.jar=http.cookiejar.CookieJar();self.op=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(self.jar));self.token='';self.auto_verify=True
 def get(self,path,data=None,json_body=False,csrf=True,raw=None,content_type=None):
  if path == '/public/login.php' and data is not None and csrf:
   self.get('/public/login.php')
  headers={}
  if csrf and self.token:headers['X-CSRF-Token']=self.token
  if raw is not None:data=raw;headers['Content-Type']=content_type
  elif data is not None:
   if json_body:data=json.dumps(data).encode();headers['Content-Type']='application/json'
   else:data=urllib.parse.urlencode(data).encode()
  try:r=self.op.open(urllib.request.Request(BASE+path,data=data,headers=headers),timeout=20)
  except urllib.error.HTTPError as e:r=e
  body=r.read();text=body.decode('utf-8',errors='replace')
  if self.auto_verify and 'verify_password.php' in r.url and path != '/staff/verify_password.php':
   m=re.search(r'window.csrfToken="([a-f0-9]+)"',text)
   if m:self.token=m[1]
   return self.get('/staff/verify_password.php',{'password':fixture['password']})
  m=re.search(r'window.csrfToken="([a-f0-9]+)"',text)
  if m:self.token=m[1]
  else:
   m=re.search(r'name="_csrf" value="([a-f0-9]+)"',text)
   if m:self.token=m[1]
  try:j=json.loads(text)
  except ValueError:j={}
  return {'code':r.code,'text':text,'json':j,'url':r.url,'body':body}
results=[]
def check(name,ok,evidence=None):results.append({'test':name,'passed':bool(ok),'evidence':evidence});print(('PASS 'if ok else'FAIL ')+name,flush=True)
log=(OUT/'revision-server.log').open('w');tmp=OUT/'upload-tmp';tmp.mkdir(exist_ok=True)
server=subprocess.Popen([PHP,'-d','upload_tmp_dir='+str(tmp),'-d','display_errors=1','-d','disable_functions=mail,curl_exec,fsockopen,stream_socket_client','-S','127.0.0.1:8771','-t',str(ROOT)],env=env,cwd=ROOT,stdout=log,stderr=log)
try:
 time.sleep(1);clients={};anon=Client()
 for role,uid in [('admin',1),('secretary',2),('bookkeeper',3),('parishioner',4),('other',5)]:
  c=Client();r=c.get('/public/login.php',{'login':1,'email':f'audit{uid}@example.invalid','password':fixture['password']});clients[role]=c
  check('Login '+role,r['code']==200 and 'dashboard.php' in r['url'] and bool(c.token),{'code':r['code'],'url':r['url'],'token_present':bool(c.token)})
 a,s,b,p,o=[clients[x] for x in ['admin','secretary','bookkeeper','parishioner','other']]
 for role,paths in [('parishioner',['dashboard','apply_service','events','announcements','payments','requests','documents','security','faq','messages','help']),('secretary',['dashboard','applications','records','services','schedule','masses','announcements','requests','checkin','messages','security']),('bookkeeper',['dashboard','payments','receipts','finance','export','requests','security']),('admin',['dashboard','analytics','announcements','users','parishes','finance','security','reports','settings'])]:
  folder='staff' if role in ['secretary','bookkeeper'] else role
  for path in paths:
   before=(OUT/'revision-server.log').stat().st_size;r=clients[role].get(f'/{folder}/{path}.php');new=(OUT/'revision-server.log').read_text(errors='replace')[before:]
   errors=[x for x in new.splitlines() if 'PHP Fatal' in x or 'PHP Warning' in x]
   check('Page '+role+'/'+path,r['code']==200 and f'/{path}.php' in r['url'] and not errors,{'code':r['code'],'errors':errors})
 for report in ['financial','applications','parish_comparison','service_demand','user_activity']:
  r=a.get('/admin/reports.php?type='+report+'&export=csv&date_from=2020-01-01&date_to=2040-01-01');check('CSV report '+report,r['code']==200 and 'Fatal error' not in r['text'] and '<html' not in r['text'] and len(r['body'])>20)
 r=a.get('/admin/reports.php?type=parish_comparison');check('Zero revenue comparison renders',r['code']==200 and 'Fatal error' not in r['text'])
 r=a.get('/admin/reports.php?date_from=invalid');check('Invalid report date rejected',r['code']==422)
 r=b.get('/staff/parishioners.php?ajax=update_status',{'id':4,'status':'suspended'});check('Bookkeeper cannot edit parishioners',r['code']==403)
 r=s.get('/staff/parishioners.php?ajax=update',{'id':4,'name':'Audit User 4','email':'audit4@example.invalid','parish_id':2});check('Secretary cannot transfer member',r['code']==403 and sql('SELECT parish_id FROM users WHERE id=4')=='1')
 r=s.get('/staff/records.php?ajax=generate_cert&id=1');check('Certificate issuance rejects GET',r['code']==405)
 sql("INSERT INTO notifications(user_id,title,message,type) VALUES(4,'Private notification','Private body','system')")
 private_id=sql('SELECT MAX(id) FROM notifications');r=a.get('/admin/notifications.php',{'_action':'mark_all_read'});check('Admin inbox action preserves others unread state',sql('SELECT is_read FROM notifications WHERE id='+private_id)=='0')
 r=anon.get('/index.php');r=anon.get('/index.php');check('Homepage renders',r['code']==200 and 'Fatal error' not in r['text'])
 r=anon.get('/admin/finance.php');check('Anonymous admin blocked','login.php' in r['url'])
 r=s.get('/staff/applications.php?ajax=get&id=2');check('Cross-parish application denied',r['code']==404)
 r=b.get('/staff/payments.php?ajax=verify',{'id':2});check('Cross-parish payment denied',r['code']==404)
 r=p.get('/parishioner/apply_service.php?ajax=payment',{'application_id':2,'amount':1,'payment_method':'cash'});check('Other owner payment denied',r['code']==422)
 r=b.get('/staff/payments.php?ajax=verify',{'id':1},csrf=False);check('CSRF required',r['code']==403)
 r=b.get('/staff/payments.php?ajax=verify',{'id':1});check('Payment verified and app synchronized',r['json'].get('success') and sql('SELECT payment_status FROM applications WHERE id=1')=='paid',r['json'])
 r=b.get('/staff/dashboard.php?ajax=verify_payment',{'id':1});check('Duplicate confirmation blocked',r['code']==422,r['json'])
 r=b.get('/staff/receipts.php?ajax=generate',{'payment_id':1});receipt=r['json'].get('id');check('Receipt persists',bool(receipt),r['json'])
 if receipt:
  r=p.get('/public/receipt.php?id='+str(receipt));check('Own receipt prints',r['code']==200 and 'Acknowledgement Receipt' in r['text'])
  r=o.get('/public/receipt.php?id='+str(receipt));check('Other owner receipt denied',r['code']==404)
 r=p.get('/parishioner/apply_service.php?ajax=submit',{'parish_id':1,'service_id':1,'schedule':'2030-01-15T09:00','form_data':'{}'});check('Full schedule rejected',r['code']==422,r['json'])
 r=p.get('/parishioner/apply_service.php?ajax=submit',{'parish_id':1,'service_id':2,'schedule':'2030-01-20T09:00','form_data':'{}'});check('Mismatched service rejected',r['code']==422)
 def upload(payload):
  boundary='RevisionTestBoundary';fields={'payment_method':'cash','parish_id':1,'service_id':1,'schedule':'2030-02-21T09:00','form_data':json.dumps({'child_name':'Revision Child'})};data=b''
  for k,v in fields.items():data+=f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
  data+=f'--{boundary}\r\nContent-Disposition: form-data; name="req_1"; filename="document.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+payload+f'\r\n--{boundary}--\r\n'.encode()
  return p.get('/parishioner/apply_service.php?ajax=submit',raw=data,content_type='multipart/form-data; boundary='+boundary)
 r=upload(b'not a PNG');check('MIME spoof rejected',r['code']==422)
 png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')
 r=upload(png);booking=r['json'].get('app_id');check('Valid booking persists',bool(booking),r['json'])
 if booking:
  token=r['json']['qr_code'];qrurl=r['json']['qr_url'];verify=BASE+'/public/verify.php?'+urllib.parse.urlencode({'app':booking,'parish':1,'token':token})
  qr=anon.get(qrurl.removeprefix(BASE));check('Local QR PNG generated',qr['body'].startswith(b'\x89PNG'),qr['code'])
  r=anon.get(verify.removeprefix(BASE));check('Valid booking token verifies',r['code']==200)
  r=anon.get('/public/verify.php?app='+str(booking)+'&parish=1&token=bad');check('Invalid token rejected',r['code']==404)
  r=p.get(f'/public/document.php?app={booking}&key=req_1');check('Owner document download',r['body']==png)
  r=o.get(f'/public/document.php?app={booking}&key=req_1');check('Other owner document denied',r['code']==404)
  r=s.get('/staff/dashboard.php?ajax=request_docs',{'id':booking,'docs':'Additional identity document','message':'Please provide a clear scan.'});check('Additional document request persists',r['json'].get('success') and sql(f'SELECT COUNT(*) FROM application_document_requests WHERE application_id={booking}')=='1')
  request=sql(f'SELECT MAX(id) FROM application_document_requests WHERE application_id={booking}')
  boundary='AdditionalDocumentBoundary';data=f'--{boundary}\r\nContent-Disposition: form-data; name="request_id"\r\n\r\n{request}\r\n--{boundary}\r\nContent-Disposition: form-data; name="document"; filename="identity.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+png+f'\r\n--{boundary}--\r\n'.encode()
  r=o.get('/parishioner/documents.php?ajax=submit',raw=data,content_type='multipart/form-data; boundary='+boundary);check('Other owner cannot fulfill document request',r['code']==422)
  r=p.get('/parishioner/documents.php?ajax=submit',raw=data,content_type='multipart/form-data; boundary='+boundary);check('Additional document submission persists',r['json'].get('ok') and sql('SELECT submitted_at IS NOT NULL FROM application_document_requests WHERE id='+request)=='1')
  r=s.get(f'/public/document.php?app={booking}&key=additional_{request}');check('Secretary downloads additional document',r['body']==png)
  r=a.get(f'/admin/applications.php?ajax=get_app&id={booking}');check('Admin document details require verification',r['code']==403 and 'Fatal error' not in r['text'])
  r=s.get('/staff/applications.php?ajax=approve',{'id':booking});check('Approve valid booking',r['json'].get('success'),r['json'])
  sql(f"UPDATE applications SET schedule=NOW() WHERE id={booking}")
  r=s.get('/staff/checkin.php',{'code':verify});check('Event day check-in',bool(sql(f'SELECT checked_in_at FROM applications WHERE id={booking}')) and 'checked in.' in r['text'])
  r=s.get('/staff/checkin.php',{'code':verify});check('Repeated check-in blocked','already checked in' in r['text'])
 r=p.get('/parishioner/requests.php',{'application_id':1,'request_type':'reschedule','proposed_schedule':'2030-03-20T09:00','reason':'Revision schedule request'});rid=sql("SELECT MAX(id) FROM application_requests WHERE request_type='reschedule'");check('Reschedule request persists',rid!='NULL',r['code'])
 if rid!='NULL':
  check('Request does not change schedule',sql('SELECT schedule FROM applications WHERE id=1')=='2030-01-15 09:00:00')
  r=s.get('/staff/requests.php',{'id':rid,'decision':'approve','review_note':'Approved'});check('Approved reschedule changes booking',sql('SELECT schedule FROM applications WHERE id=1')=='2030-03-20 09:00:00',r['code'])
 r=p.get('/parishioner/requests.php',{'application_id':1,'request_type':'refund','reason':'Revision refund request'});rid=sql("SELECT MAX(id) FROM application_requests WHERE request_type='refund'");check('Refund request persists',rid!='NULL')
 if rid!='NULL':
  r=b.get('/staff/requests.php',{'id':rid,'decision':'approve','review_note':'Approved'});check('Refund approval does not claim money returned',sql('SELECT status FROM payments WHERE id=1')=='completed')
  r=b.get('/staff/requests.php',{'id':rid,'decision':'complete','review_note':'Cash return reference TEST-001'});check('Actual refund recorded separately',sql('SELECT status FROM payments WHERE id=1')=='refunded')
  r=b.get('/staff/dashboard.php?ajax=verify_payment',{'id':1});check('Refund cannot be reconfirmed',r['code']==422)
 sql("UPDATE services SET sacrament_type='Baptism',classification='Sacramental',general_type='Baptism' WHERE id=1")
 r=s.get('/staff/records.php?ajax=create',{'record_type':'Baptism','parishioner_name':'Revision Child','date_of_sacrament':'2026-01-15','application_id':1});record_id=r['json'].get('id');check('Duplicate manual record rejected',r['code']==422,r['json']);record_id=1
 if record_id:
  r=s.get('/staff/records.php?ajax=generate_cert&id='+str(record_id),{});cert=r['json'].get('certificate_number');check('Certificate ID persisted',bool(cert),r['json'].get('message'))
  if cert:check('Certificate public authenticity',anon.get('/public/verify.php?cert='+cert)['code']==200)
 r=a.get('/admin/announcements.php',{'_action':'send_announcement','subject':'Revision staff announcement','message':'Staff only test','target':'Staff Only','channel':'In-App'});check('Admin announcement persists',sql("SELECT COUNT(*) FROM announcements WHERE title='Revision staff announcement'")=='1')
 r=p.get('/parishioner/announcements.php');check('Staff announcement hidden from parishioner','Staff only test' not in r['text'])
 r=a.get('/admin/dashboard.php?ajax=stats');check('Admin statistics use real database',r['json'].get('total_parishes')==2,r['json'])
 sql("UPDATE users SET status='suspended' WHERE id=5");r=o.get('/parishioner/dashboard.php');check('Suspension revokes existing session','login.php' in r['url'])
 r=Client().get('/public/login.php',{'login':1,'email':'audit5@example.invalid','password':fixture['password']});check('Suspended login rejected','login.php' in r['url'])
 r=p.get('/parishioner/help.php',{'parish_id':1,'question':'Audit question A'});check('FAQ exact match uses stored answer',sql("SELECT COUNT(*) FROM messages WHERE is_bot=1 AND body='Audit answer A'")=='1')
 r=p.get('/parishioner/help.php',{'parish_id':1,'question':'Audit question B'});check('Other parish FAQ never answers',sql("SELECT COUNT(*) FROM messages WHERE is_bot=1 AND body='Audit answer B'")=='0')
 check('Unmatched FAQ activates staff handoff',sql('SELECT staff_active FROM help_conversations WHERE user_id=4 AND parish_id=1')=='1')
 r=p.get('/parishioner/help.php',{'parish_id':1,'question':'Audit question A'});check('Bot stays silent during staff handoff',sql('SELECT COUNT(*) FROM messages WHERE is_bot=1')=='1')
 r=s.get('/staff/messages.php?ajax=send',{'receiver_id':4,'body':'Revision staff answer'},json_body=True);check('Secretary reply persists',r['json'].get('ok'),r['json'])
 r=p.get('/parishioner/help.php?ajax=thread&parish_id=1');check('Same help conversation contains staff reply',any(x['body']=='Revision staff answer' for x in r['json'].get('messages',[])))
 r=s.get('/staff/messages.php?ajax=resolve_help',{'user_id':4});r=p.get('/parishioner/help.php',{'parish_id':1,'question':'Audit question A'});check('FAQ resumes only after staff resolves',sql('SELECT COUNT(*) FROM messages WHERE is_bot=1')=='2')
 r=s.get('/staff/messages.php?ajax=send',{'receiver_id':5,'body':'Cross parish attempt'},json_body=True);check('Staff cannot send cross-parish message',r['code'] in [403,422])
 r=s.get('/staff/masses.php',{'day':'Tuesday','start':'10:00','end':'11:00','mass_type':'Revision Mass','language':'Filipino'});check('Mass schedule persisted',sql("SELECT COUNT(*) FROM mass_schedules WHERE mass_type='Revision Mass' AND parish_id=1")=='1')
 r=p.get('/parishioner/dashboard.php?ajax=parish_info&id=1');check('Mass update reaches parishioner',any(x['mass_type']=='Revision Mass' for x in r['json'].get('schedules',[])))
 if booking:
  sql(f'UPDATE applications SET schedule=DATE_ADD(NOW(),INTERVAL 12 HOUR),checked_in_at=NULL WHERE id={booking}')
  subprocess.run([PHP,str(ROOT/'tools/reminders.php')],env=env,cwd=ROOT,check=True,capture_output=True)
  subprocess.run([PHP,str(ROOT/'tools/reminders.php')],env=env,cwd=ROOT,check=True,capture_output=True)
  check('Reminder job is idempotent',sql(f'SELECT COUNT(*) FROM reminder_deliveries WHERE application_id={booking}')=='1')
 r=p.get('/parishioner/security.php',{'password':fixture['password'],'enabled':1});check('Enable 2FA',sql('SELECT two_factor_enabled FROM users WHERE id=4')=='1')
 c=Client();r=c.get('/public/login.php',{'login':1,'email':'audit4@example.invalid','password':fixture['password']});check('2FA challenge required','verify_otp.php' in r['url'])
 r=c.get('/parishioner/dashboard.php');check('Pending 2FA cannot access dashboard','login.php' in r['url'])
 codes=re.findall(r'Your login code is (\d{6})',(OUT/'email.log').read_text(errors='replace'))
 if codes:
  r=c.get('/verify_otp.php',{'verify_otp':1,'otp':codes[-1]});check('Valid second factor completes login','dashboard.php' in r['url'])
  r=c.get('/verify_otp.php',{'verify_otp':1,'otp':codes[-1]});check('Second factor cannot be replayed','login.php' in r['url'])
 exec(compile((ROOT/'tools/master_checks.py').read_text(encoding='utf-8'), 'master_checks.py', 'exec'), globals())
 exec(compile((ROOT/'tools/separation_checks.py').read_text(encoding='utf-8'), 'separation_checks.py', 'exec'), globals())
 exec(compile((ROOT/'tools/attachment_checks.py').read_text(encoding='utf-8'), 'attachment_checks.py', 'exec'), globals())
 exec(compile((ROOT/'tools/pdf_revision_checks.py').read_text(encoding='utf-8'), 'pdf_revision_checks.py', 'exec'), globals())
 exec(compile((ROOT/'tools/latest_pdf_checks.py').read_text(encoding='utf-8'), 'latest_pdf_checks.py', 'exec'), globals())
 exec(compile((ROOT/'tools/recommendation_checks.py').read_text(encoding='utf-8'), 'recommendation_checks.py', 'exec'), globals())
finally:
 server.terminate();server.wait(timeout=10);log.close()
 report={'database':fixture['database'],'tests':results,'passed':sum(r['passed']for r in results),'failed':sum(not r['passed']for r in results)}
 (ROOT/'separation-test-results.json').write_text(json.dumps(report,indent=2),encoding='utf-8')
 print('Results:',report['passed'],'passed,',report['failed'],'failed')
if report['failed']: raise SystemExit(1)
