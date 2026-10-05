"""Targeted reported-issue checks; only the synthetic regression database is modified."""
issue_service={'name':'AM PM Service','general_type':'Other / Custom','classification':'Non-Sacramental','fee':0,'schedule_mode':'fixed','time_slots_format':'12h','time_slots':'12:00 AM, 11:59 AM, 12:00 PM, 12:01 PM, 1:00 PM, 11:59 PM','slot_capacity':1,'max_daily_limit':0}
r=s.get('/staff/services.php?ajax=create',issue_service);issue_sid=r['json'].get('id')
check('Reported: Secretary creates AM/PM service',bool(issue_sid),r['json'])
if issue_sid:
    check('Reported: AM/PM converts to canonical times without shifting',json.loads(sql(f'SELECT time_slots FROM services WHERE id={issue_sid}'))==['00:00','11:59','12:00','12:01','13:00','23:59'])
    r=s.get('/staff/services.php?ajax=update',dict(issue_service,id=issue_sid,time_slots='12:00 PM, 1:00 PM'))
    check('Reported: Secretary edits AM/PM service',r['json'].get('success') and json.loads(sql(f'SELECT time_slots FROM services WHERE id={issue_sid}'))==['12:00','13:00'])
    for invalid in ['13:00 PM','12:60 AM','9:00','12:00 AM,']:
        r=s.get('/staff/services.php?ajax=update',dict(issue_service,id=issue_sid,time_slots=invalid))
        check('Reported: invalid time has field error '+invalid,r['code']==422 and 'time_slots' in r['json'].get('errors',{}) and json.loads(sql(f'SELECT time_slots FROM services WHERE id={issue_sid}'))==['12:00','13:00'])
    field={'service_id':issue_sid,'field_label':'Contact name','field_name':'contact_name','field_type':'text','is_required':1}
    r=s.get('/staff/services.php?ajax=add_field',field);field_id=sql(f'SELECT MAX(id) FROM service_fields WHERE service_id={issue_sid}')
    check('Reported: Secretary creates form field',r['json'].get('success') and field_id!='NULL')
    if field_id!='NULL':
        r=s.get('/staff/services.php?ajax=update_field',{'id':field_id,'field_label':'Updated contact','field_name':'contact_name','field_type':'text','is_required':1})
        check('Reported: Secretary edits form field',r['json'].get('success') and sql(f'SELECT field_label FROM service_fields WHERE id={field_id}')=='Updated contact')
        r=s.get('/staff/services.php?ajax=update_field',{'id':field_id,'field_label':'Preserved contact','field_name':'1bad','field_type':'text'})
        check('Reported: form validation identifies field and preserves saved data',r['code']==422 and 'field_name' in r['json'].get('errors',{}) and sql(f'SELECT field_label FROM service_fields WHERE id={field_id}')=='Updated contact')
        check('Reported: form save still requires CSRF',s.get('/staff/services.php?ajax=update_field',{'id':field_id},csrf=False)['code']==403)
    for client,role in [(a,'Admin'),(b,'Bookkeeper'),(p,'Parishioner')]:
        check('Reported: '+role+' cannot edit Secretary service',client.get('/staff/services.php?ajax=update',dict(issue_service,id=issue_sid))['code']==403)
    other_secretary=Client();other_secretary.get('/public/login.php',{'email':'audit6@example.invalid','password':fixture['password']})
    check('Reported: Secretary cannot edit another parish service',other_secretary.get('/staff/services.php?ajax=update',dict(issue_service,id=issue_sid))['code']==404)
check('Reported: Bookkeeper payment records load',b.get('/staff/payments.php')['code']==200)
check('Reported: Bookkeeper useful empty payment state','No payments found.' in b.get('/staff/payments.php?q=NoSuchPaymentIssueProbe')['text'])
check('Reported: Admin authorized finance screen loads',a.get('/admin/finance.php')['code']==200)
for client,role in [(a,'Admin'),(s,'Secretary'),(p,'Parishioner')]:
    check('Reported: '+role+' cannot enter Bookkeeper payment route',client.get('/staff/payments.php')['code']==403)
# Reproduce the earlier query against a pre-recommendation schema without deleting data.
sql('ALTER TABLE payments CHANGE manual_method_name legacy_manual_method_name VARCHAR(100) NULL, CHANGE proof_file legacy_proof_file VARCHAR(255) NULL')
try:
    old=subprocess.run([MYSQL,'--host=127.0.0.1','--user=root',fixture['database'],'-e','SELECT p.manual_method_name,p.proof_file FROM payments p LIMIT 1'],capture_output=True,text=True)
    check('Reported: old Payments query reproduces missing-column failure',old.returncode!=0 and '1054' in old.stderr)
    r=b.get('/staff/payments.php');check('Reported: Payments tolerates absent optional metadata',r['code']==200 and 'All Payments' in r['text'])
finally:sql('ALTER TABLE payments CHANGE legacy_manual_method_name manual_method_name VARCHAR(100) NULL, CHANGE legacy_proof_file proof_file VARCHAR(255) NULL')
# A genuine data-query failure is logged and is never disguised as an empty list.
sql('ALTER TABLE payments CHANGE reference_number issue_reference_number VARCHAR(255) NULL')
try:
    r=b.get('/staff/payments.php?q=Probe');check('Reported: failed payment query gets useful error state',r['code']==500 and 'Payment records could not be loaded' in r['text'])
    check('Reported: real payment error logged','Payments page [' in (OUT/'revision-server.log').read_text(errors='replace'))
finally:sql('ALTER TABLE payments CHANGE issue_reference_number reference_number VARCHAR(255) NULL')
