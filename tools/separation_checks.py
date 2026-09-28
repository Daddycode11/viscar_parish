"""Additional workflow assertions, executed in test_separation.py's isolated fixture."""
import hashlib, struct, zlib
# Valid PNG with correct chunk CRCs, decoded and re-encoded by the upload helper.
def png_chunk(kind, data):
    return struct.pack('!I',len(data))+kind+data+struct.pack('!I',zlib.crc32(kind+data)&0xffffffff)
png=b'\x89PNG\r\n\x1a\n'+png_chunk(b'IHDR',struct.pack('!2I5B',1,1,8,2,0,0,0))+png_chunk(b'IDAT',zlib.compress(b'\x00\xff\xff\xff'))+png_chunk(b'IEND',b'')

check('Admin request management URL denied', a.get('/admin/requests.php')['code'] == 403)
check('Admin cannot use staff request route', a.get('/staff/requests.php')['code'] == 403)
check('PDF: finance separate from analytics', 'Financial totals by parish' not in a.get('/admin/analytics.php')['text'] and 'Financial totals by parish' in a.get('/admin/finance.php')['text'])
for role, client, routes in [
    ('secretary', s, ['accounting', 'accounting_report', 'payments', 'receipts', 'export']),
    ('bookkeeper', b, ['walk_in', 'records', 'record_application', 'announcements', 'services', 'parishioners']),
    ('parishioner', p, ['accounting', 'walk_in', 'requests', 'records']),
    ('admin', a, ['accounting', 'walk_in', 'requests']),
]:
    for route in routes:
        check(f'{role} denied staff/{route}', client.get('/staff/' + route + '.php')['code'] == 403)
sql("INSERT INTO application_requests(application_id,requested_by,request_type,reason) VALUES(1,4,'refund','Private refund separation reason')")
refund_id = sql('SELECT MAX(id) FROM application_requests')
sql("INSERT INTO application_requests(application_id,requested_by,request_type,reason,proposed_schedule) VALUES(1,4,'reschedule','Test role separation','2035-04-01 09:00:00')")
reschedule_id = sql('SELECT MAX(id) FROM application_requests')
check('Secretary cannot review refund', s.get('/staff/requests.php', {'id': refund_id, 'decision': 'approve'})['code'] == 422)
check('Bookkeeper cannot review reschedule', b.get('/staff/requests.php', {'id': reschedule_id, 'decision': 'approve'})['code'] == 422)
check('Secretary request view excludes refund reason', 'Private refund separation reason' not in s.get('/staff/requests.php')['text'])

def multipart(client, path, fields, file_field=None, payload=None, filename='image.png'):
    boundary = 'SeparationBoundary'
    body = b''
    for key, value in fields.items():
        body += f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()
    if file_field:
        body += f'--{boundary}\r\nContent-Disposition: form-data; name="{file_field}"; filename="{filename}"\r\nContent-Type: image/png\r\n\r\n'.encode()+payload+b'\r\n'
    body += f'--{boundary}--\r\n'.encode()
    return client.get(path, raw=body, content_type='multipart/form-data; boundary='+boundary)

for role, client, uid in [('admin',a,1),('secretary',s,2),('bookkeeper',b,3),('parishioner',p,4)]:
    folder = 'staff' if role in ['secretary','bookkeeper'] else role
    profile = {'_action':'save_profile','name':f'Audit User {uid}','email':f'audit{uid}@example.invalid','phone':'09123456789','language':'fil'}
    response = multipart(client, f'/{folder}/settings.php', profile, 'profile_picture', png)
    picture = sql(f'SELECT profile_picture FROM users WHERE id={uid}')
    check(role+' profile image and Filipino settings', response['code']==200 and picture in response['text'] and 'Mga setting' in response['text'], response['code'])
    check(role+' profile picture in dashboard', picture in client.get(f'/{folder}/dashboard.php')['text'])
    check(role+' spoof image rejected', multipart(client,f'/{folder}/settings.php',profile,'profile_picture',b'<?php echo 1; ?>')['code']==422)
    profile['language']='en'
    client.get(f'/{folder}/settings.php',profile)
check('Oversized profile rejected',multipart(p,'/parishioner/settings.php',{'_action':'save_profile','name':'Audit User 4','email':'audit4@example.invalid','language':'en'},'profile_picture',png+b' '*(6*1024*1024))['code']==422)
check('Settings CSRF enforced',p.get('/parishioner/settings.php',{'_action':'save_profile'},csrf=False)['code']==403)

site = {'_action':'save_system','site_name':'Separation Test Parish','hero_headline':'New hero <script>alert(1)</script>','hero_subtitle':'New subtitle','hero_badge':'Welcome','homepage_text':'Homepage test content','contact_details':'Contact parish office','color_gold':'#C9A84C','color_navy':'#123456','color_wine':'#6B2737'}
response=multipart(a,'/admin/settings.php',site,'site_logo',png)
home=anon.get('/index.php')['text']
check('Homepage settings persist and escape text',response['code']==200 and 'Separation Test Parish' in home and '#123456' in home and '<script>alert(1)</script>' not in home)
check('Staff cannot modify homepage settings',s.get('/staff/settings.php',site)['code']==422)
check('Parishioner cannot modify homepage settings',p.get('/parishioner/settings.php',site)['code']==422)

for doc_type in ['check_voucher','petty_cash_voucher','disbursement','deposit']:
    data={'bank_name':'Synthetic Bank','bank_account_number':'TEST-001','secretary_signatory':'Secretary Test','finance_signatory':'Finance Test','priest_signatory':'Priest Test','document_type':doc_type,'document_date':'2031-01-10','party_name':'Synthetic Payee','amount':'123.45','description':'Synthetic accounting document','reference':'TEST-REF','request_key':hashlib.sha256(doc_type.encode()).hexdigest()}
    response=b.get('/staff/accounting.php?type='+doc_type,data)
    doc_id=sql(f"SELECT MAX(id) FROM accounting_documents WHERE document_type='{doc_type}'")
    check(doc_type+' create',response['code']==200 and doc_id!='NULL')
    b.get('/staff/accounting.php?type='+doc_type,data)
    check(doc_type+' duplicate protection',sql(f"SELECT COUNT(*) FROM accounting_documents WHERE document_type='{doc_type}'")=='1')
    response=b.get('/staff/accounting.php?type='+doc_type,{'_action':'complete','id':doc_id})
    check(doc_type+' completion audited',response['code']==200 and sql(f"SELECT status FROM accounting_documents WHERE id={doc_id}")=='completed' and sql(f"SELECT COUNT(*) FROM audit_trail WHERE entity_type='accounting_document' AND entity_id={doc_id} AND action='complete_accounting'")=='1')
    response=b.get('/staff/accounting.php?print='+doc_id)
    check(doc_type+' printable',response['code']==200 and 'Synthetic Payee' in response['text'] and 'window.print' in response['text'])
    response=b.get('/staff/accounting_report.php?export=csv&date_from=2031-01-01&date_to=2031-01-31&document_type='+doc_type+'&status=completed')
    check(doc_type+' filtered export totals',response['code']==200 and 'TOTAL' in response['text'] and response['text'].count('123.45')==2)
check('Receipt export preserved',b.get('/staff/accounting_report.php?export=csv&document_type=receipt&date_from=2020-01-01&date_to=2040-01-01')['code']==200)
check('Accounting cross parish filter denied',b.get('/staff/accounting_report.php?parish_id=2')['code']==422)
check('Accounting date validation',b.get('/staff/accounting_report.php?date_from=bad')['code']==422)
check('Accounting invalid amount rejected',b.get('/staff/accounting.php?type=deposit',{'document_type':'deposit','document_date':'2031-01-01','party_name':'Test','amount':'NaN','description':'Test','request_key':'a'*64})['code']==422)
sql("INSERT INTO accounting_documents(parish_id,document_type,document_number,document_date,party_name,amount,description,created_by,request_key) VALUES(2,'deposit','OTHER-001','2031-01-01','Other parish',10,'Private',6,REPEAT('f',64))")
other_document=sql('SELECT MAX(id) FROM accounting_documents')
check('Accounting other parish document hidden',b.get('/staff/accounting.php?print='+other_document)['code']==404)

walk={'parish_id':1,'service_id':1,'user_id':4,'schedule':'2032-02-15T09:00','fields[child_name]':'Walk-in Child'}
response=multipart(s,'/staff/walk_in.php',walk,'req_1',png)
walk_id=sql("SELECT MAX(id) FROM applications WHERE source='walk_in'")
check('Walk-in booking with shared documents',response['code']==200 and walk_id!='NULL',response['code'])
if walk_id!='NULL':
    check('Walk-in Secretary provenance',sql(f'SELECT created_by FROM applications WHERE id={walk_id}')=='2')
    response=s.get('/staff/applications.php?ajax=approve',{'id':walk_id})
    rec_id=sql(f'SELECT MAX(id) FROM sacramental_records WHERE application_id={walk_id}')
    check('Approval automatically creates sacramental record',response['json'].get('success') and rec_id!='NULL',response['json'])
    if rec_id!='NULL':
        check('Automatic record blank priest and remarks',sql(f"SELECT CONCAT(COALESCE(minister_name,''),'|',COALESCE(remarks,'')) FROM sacramental_records WHERE id={rec_id}")=='|')
        check('Automatic record has certificate',sql(f'SELECT certificate_number IS NOT NULL FROM sacramental_records WHERE id={rec_id}')=='1')
        check('Record references form and documents','Walk-in Child' in s.get('/staff/record_application.php?id='+rec_id)['text'])
    check('Duplicate approval rejected',s.get('/staff/applications.php?ajax=approve',{'id':walk_id})['code']==422)
    check('Duplicate approval leaves one record',sql(f'SELECT COUNT(*) FROM sacramental_records WHERE application_id={walk_id}')=='1')
    details=p.get('/parishioner/application.php?id='+walk_id)
    token_before=sql('SELECT qr_code FROM applications WHERE id='+walk_id)
    p.get('/parishioner/application.php?id='+walk_id)
    check('Visible stable application QR and printable details','application-qr' in details['text'] and 'public/qr.php' in details['text'] and token_before==sql('SELECT qr_code FROM applications WHERE id='+walk_id))
    check('Other owner application details denied',o.get('/parishioner/application.php?id='+walk_id)['code'] in [403,404] or 'login.php' in o.get('/parishioner/application.php?id='+walk_id)['url'])
walk['schedule']='2032-02-16T09:00'
check('Walk-in required documents enforced',s.get('/staff/walk_in.php',walk)['code']==422)
walk.update(user_id=0,name='New Walk-in',email='new-walk@example.invalid',phone='09123456789')
check('New walk-in parishioner created',multipart(s,'/staff/walk_in.php',walk,'req_1',png)['code']==200 and sql("SELECT COUNT(*) FROM users WHERE email='new-walk@example.invalid'")=='1')
walk['schedule']='2032-02-17T09:00'
check('Duplicate walk-in account rejected',multipart(s,'/staff/walk_in.php',walk,'req_1',png)['code']==422)
sql("INSERT INTO services(parish_id,name,fee) VALUES(1,'Ordinary service',0)")
ordinary=sql('SELECT MAX(id) FROM services')
response=s.get('/staff/walk_in.php',{'parish_id':1,'service_id':ordinary,'user_id':4,'schedule':'2033-01-01T09:00'})
ordinary_app=sql('SELECT MAX(id) FROM applications')
s.get('/staff/applications.php?ajax=approve',{'id':ordinary_app})
check('Non-sacramental approval creates no record',sql(f'SELECT COUNT(*) FROM sacramental_records WHERE application_id={ordinary_app}')=='0')

p.get('/parishioner/settings.php',{'_action':'save_preferences','subscriptions[1][in_app]':1,'subscriptions[1][email]':1,'subscriptions[1][sms]':1,'subscriptions[2][in_app]':1})
check('Multiple parish preferences stored',sql('SELECT COUNT(*) FROM parish_subscriptions WHERE user_id=4')=='2')
announcement={'title':'Targeted unique announcement','content':'Subscribed audience only','send_email':1,'send_sms':1,'request_key':'b'*64}
response=s.get('/staff/announcements.php?ajax=create',announcement)
announcement_id=response['json'].get('id')
check('Targeted announcement publishes',bool(announcement_id),response['json'])
if announcement_id:
    check('Only subscribers receive parish announcement',sql(f'SELECT COUNT(DISTINCT user_id) FROM announcement_deliveries WHERE announcement_id={announcement_id}')=='1')
    check('Local email and SMS explicitly simulated',sql(f"SELECT COUNT(*) FROM announcement_deliveries WHERE announcement_id={announcement_id} AND status='simulated'")=='2')
    check('Subscribed announcement visible','Targeted unique announcement' in p.get('/parishioner/announcements.php')['text'])
    s.get('/staff/announcements.php?ajax=create',announcement)
    check('Refresh does not duplicate deliveries',sql(f'SELECT COUNT(*) FROM announcement_deliveries WHERE announcement_id={announcement_id}')=='3' and sql("SELECT COUNT(*) FROM announcements WHERE title='Targeted unique announcement'")=='1')
check('Secretary cross parish target rejected',s.get('/staff/announcements.php?ajax=create',dict(announcement,**{'parish_ids[0]':2}))['code']==422)
p.get('/parishioner/settings.php',{'_action':'save_preferences'})
check('Unsubscribe clears preferences',sql('SELECT COUNT(*) FROM parish_subscriptions WHERE user_id=4')=='0')
announcement.update(title='After unsubscribe',request_key='c'*64)
response=s.get('/staff/announcements.php?ajax=create',announcement)
check('Unsubscribed audience not sent new messages',sql('SELECT COUNT(*) FROM announcement_deliveries WHERE announcement_id='+str(response['json'].get('id',0)))=='0')
check('Parish announcement hidden after unsubscribe','Targeted unique announcement' not in p.get('/parishioner/announcements.php')['text'])
dashboard=b.get('/staff/dashboard.php')['text']
check('Bookkeeper quick actions authorized','announcements.php' not in dashboard and 'applications.php' not in dashboard and 'accounting.php' in dashboard)

# New sessions exercise the gate without the integration client's auto-verification.
gate=Client();gate.auto_verify=False
response=gate.get('/public/login.php',{'login':1,'email':'audit3@example.invalid','password':fixture['password']})
check('Bookkeeper dashboard opens without extra password','dashboard.php' in response['url'])
check('Unverified payment API available',gate.get('/staff/payments.php?ajax=list')['code']==200)
check('Unverified accounting print gated','verify_password.php' in gate.get('/staff/accounting.php?print=1')['url'])
response=gate.get('/staff/verify_password.php',{'password':'incorrect'})
check('Wrong re-verification password rejected','Incorrect password.' in response['text'])
response=gate.get('/staff/verify_password.php',{'password':fixture['password']})
check('Correct re-verification allows records','accounting.php' in response['url'])
check('Re-verification audit recorded',int(sql("SELECT COUNT(*) FROM audit_trail WHERE action='password_reverified' AND user_id=3"))>0)

reset_client=Client();response=reset_client.get('/public/forgot_password.php')
response=reset_client.get('/public/forgot_password.php',{'email':'new-walk@example.invalid'})
generic='If the address is registered, a reset link will be sent.'
check('Password reset request generic response',generic in response['text'])
check('Unknown reset email same response',generic in reset_client.get('/public/forgot_password.php',{'email':'not-registered@example.invalid'})['text'])
tokens=re.findall(r'reset_password.php#token=([a-f0-9]{64})',(OUT/'email.log').read_text(errors='replace'))
if tokens:
    token=tokens[-1]
    check('Only reset token hash stored',sql("SELECT COUNT(*) FROM password_resets WHERE token_hash='"+hashlib.sha256(token.encode()).hexdigest()+"'")=='1' and sql("SELECT COUNT(*) FROM password_resets WHERE token_hash='"+token+"'")=='0')
    reset_client.get('/public/reset_password.php')
    new_password='SyntheticReset123!'
    response=reset_client.get('/public/reset_password.php',{'token':token,'password':new_password,'confirmation':new_password})
    check('Password reset succeeds','Password reset. You can now sign in.' in response['text'])
    check('Reset token cannot replay',reset_client.get('/public/reset_password.php',{'token':token,'password':new_password,'confirmation':new_password})['code']==422)
    check('New password login works','dashboard.php' in Client().get('/public/login.php',{'login':1,'email':'new-walk@example.invalid','password':new_password})['url'])
expired='d'*64
sql("INSERT INTO password_resets(user_id,token_hash,expires_at) VALUES(4,'"+hashlib.sha256(expired.encode()).hexdigest()+"',DATE_SUB(NOW(),INTERVAL 1 MINUTE))")
check('Expired reset token rejected',reset_client.get('/public/reset_password.php',{'token':expired,'password':'NewExpired123','confirmation':'NewExpired123'})['code']==422)
check('Reset CSRF enforced',reset_client.get('/public/reset_password.php',{'token':expired,'password':'NewExpired123','confirmation':'NewExpired123'},csrf=False)['code']==403)

# Rejection paths and failed approval preserve application state.
check('Bookkeeper refund rejection',b.get('/staff/requests.php',{'id':refund_id,'decision':'reject','review_note':'Synthetic rejection'})['code']==200 and sql('SELECT status FROM application_requests WHERE id='+refund_id)=='rejected')
check('Secretary reschedule rejection',s.get('/staff/requests.php',{'id':reschedule_id,'decision':'reject','review_note':'Synthetic rejection'})['code']==200 and sql('SELECT status FROM application_requests WHERE id='+reschedule_id)=='rejected')
check('Walk-in source visible in Secretary list','Walk-in application' in s.get('/staff/applications.php')['text'])
failed_walk={'parish_id':1,'service_id':1,'user_id':4,'schedule':'2034-05-15T09:00','fields[child_name]':'Atomic approval test'}
multipart(s,'/staff/walk_in.php',failed_walk,'req_1',png)
failed_id=sql('SELECT MAX(id) FROM applications')
sql("CREATE TRIGGER separation_reject_record BEFORE INSERT ON sacramental_records FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic record failure'")
try:
    response=s.get('/staff/applications.php?ajax=approve',{'id':failed_id})
    check('Record failure rolls back approval',response['code']==422 and sql('SELECT status FROM applications WHERE id='+failed_id)=='pending' and sql('SELECT COUNT(*) FROM sacramental_records WHERE application_id='+failed_id)=='0')
finally:
    sql('DROP TRIGGER separation_reject_record')
# Multi-parish targeting must still deduplicate each recipient/channel.
p.get('/parishioner/settings.php',{'_action':'save_preferences','subscriptions[1][in_app]':1,'subscriptions[2][in_app]':1})
response=a.get('/admin/announcements.php',{'_action':'send_announcement','subject':'Multi-parish test','message':'Synthetic targeted message','target':'Selected Parishes','parish_ids[0]':1,'parish_ids[1]':2,'channel':'In-App','request_key':'e'*64})
multi_id=sql("SELECT MAX(id) FROM announcements WHERE title='Multi-parish test'")
check('Admin multi-parish audience deduplicated',response['code']==200 and multi_id!='NULL' and sql('SELECT COUNT(*) FROM announcement_deliveries WHERE user_id=4 AND announcement_id='+multi_id)=='1')
# Language is a persisted account preference, including a new login session.
a.get('/admin/settings.php',{'_action':'save_profile','name':'Audit User 1','email':'audit1@example.invalid','phone':'09123456789','language':'fil'})
lang=Client();lang.get('/public/login.php',{'email':'audit1@example.invalid','password':fixture['password']})
check('Filipino language survives new login','Mga setting' in lang.get('/admin/settings.php')['text'])
a.get('/admin/settings.php',{'_action':'save_profile','name':'Audit User 1','email':'audit1@example.invalid','phone':'09123456789','language':'en'})
check('No PHP warnings in revised workflows',not any('PHP Warning' in line or 'PHP Fatal' in line for line in (OUT/'revision-server.log').read_text(errors='replace').splitlines()))

# Resetting credentials revokes sessions that were already authenticated.
old_login=Client();old_login.get('/public/login.php',{'email':'new-walk@example.invalid','password':'SyntheticReset123!'})
reset_client.get('/public/forgot_password.php')
reset_client.get('/public/forgot_password.php',{'email':'new-walk@example.invalid'})
new_token=re.findall(r'reset_password.php#token=([a-f0-9]{64})',(OUT/'email.log').read_text(errors='replace'))[-1]
reset_client.get('/public/reset_password.php',{'token':new_token,'password':'SecondSynthetic123!','confirmation':'SecondSynthetic123!'})
check('Password reset revokes existing sessions','login.php' in old_login.get('/parishioner/dashboard.php')['url'])

# Expire only synthetic sessions; no production session files are touched.
def session_value(client, key, value):
    sid = next(cookie.value for cookie in client.jar if cookie.name == 'PHPSESSID')
    test_env = env.copy(); test_env['TEST_SESSION_ID'] = sid
    code = "session_save_path('storage/private/sessions'); session_id(getenv('TEST_SESSION_ID')); session_start(); $_SESSION['"+key+"']="+str(value)+"; session_write_close();"
    subprocess.run([PHP,'-r',code],env=test_env,cwd=ROOT,check=True,capture_output=True)
session_value(gate,'sensitive_until',0)
check('Legacy five-minute deadline does not interrupt active verification', 'accounting.php' in gate.get('/staff/accounting.php')['url'])
session_value(gate,'sensitive_verified',0)
check('Missing sensitive verification remains gated', 'verify_password.php' in gate.get('/staff/accounting.php')['url'])
for attempt in range(5): gate.get('/staff/verify_password.php',{'password':'wrong'})
check('Password re-verification rate limited','Too many attempts' in gate.get('/staff/verify_password.php',{'password':fixture['password']})['text'])
session_value(p,'last_activity',0)
check('Idle session timeout returns to login','login.php' in p.get('/parishioner/dashboard.php')['url'])
check('Login CSRF enforced',Client().get('/public/login.php',{'email':'audit1@example.invalid','password':fixture['password']},csrf=False)['code']==403)
check('Admin refund endpoint denied',a.get('/admin/finance.php?ajax=refund',{'id':1})['code']==403)
check('Admin refund buttons removed','refundPayment' not in a.get('/admin/finance.php')['text'])
# Confirm persistence after an actual logout and a new login.
a.get('/public/logout.php',{})
fresh=Client();fresh.get('/public/login.php',{'email':'audit1@example.invalid','password':fixture['password']})
check('Profile survives logout and login',sql('SELECT profile_picture FROM users WHERE id=1') in fresh.get('/admin/dashboard.php')['text'])
check('Logout invalidates session','login.php' in a.get('/admin/dashboard.php')['url'])
# Secretary gate and private attachment route both require re-verification.
secretary_gate=Client();secretary_gate.auto_verify=False
check('Secretary dashboard opens without extra password','dashboard.php' in secretary_gate.get('/public/login.php',{'email':'audit2@example.invalid','password':fixture['password']})['url'])
check('Secretary private documents gated','verify_password.php' in secretary_gate.get('/public/document.php?app=1&key=req_1')['url'])
