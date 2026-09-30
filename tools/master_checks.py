"""Master revision checks, executed only in the isolated integration fixture."""
import hashlib

for action in ['approve','reject','request_docs','assign_schedule']:
    response=a.get('/admin/applications.php?ajax='+action,{'id':1,'reason':'Test reason','docs':'Test document','schedule':'2035-01-01T09:00'})
    check('Admin operation blocked '+action,response['code']==403)
for action in ['update','update_status']:
    check('Secretary account mutation blocked '+action,s.get('/staff/parishioners.php?ajax='+action,{'id':4,'name':'Changed','email':'changed@example.invalid','status':'suspended','parish_id':1})['code']==403)
check('Secretary parishioner list removes controls','name="parish"' not in s.get('/staff/parishioners.php')['text'] and 'title="Suspend"' not in s.get('/staff/parishioners.php')['text'])

service={'name':'Custom Mass Intention','general_type':'Mass Intention','classification':'Non-Sacramental','amount_mode':'user_defined','fee':'0','max_daily_limit':'2','status':'active','requirements_note':'Bring identification'}
r=s.get('/staff/services.php?ajax=create',service);sid=r['json'].get('id')
check('Canonical user-defined service creation',bool(sid),r['json'])
if sid:
    check('Canonical type persisted',sql(f'SELECT general_type FROM services WHERE id={sid}')=='Mass Intention')
    check('Service fee rejects negative',s.get('/staff/services.php?ajax=update',dict(service,id=sid,fee='-1'))['code']==422)
    check('Service fee rejects precision loss',s.get('/staff/services.php?ajax=update',dict(service,id=sid,fee='1.234'))['code']==422)
    field={'service_id':sid,'field_name':'participant','field_label':'Participant "Name"','field_type':'text','is_required':1,'sort_order':1,'field_options':''}
    r=s.get('/staff/services.php?ajax=add_field',field);fid=sql(f'SELECT MAX(id) FROM service_fields WHERE service_id={sid}')
    check('Form field creation',r['json'].get('success'),r['json'])
    booking_data={'payment_method':'cash','parish_id':1,'service_id':sid,'schedule':'2035-05-10T09:00','amount':'77.25','form_data':json.dumps({'participant':'Original Participant'})}
    r=p.get('/parishioner/apply_service.php?ajax=submit',dict(booking_data,amount='-1'));check('User-defined fee rejects negative',r['code']==422)
    r=p.get('/parishioner/apply_service.php?ajax=submit',dict(booking_data,amount='1.234'));check('User-defined fee rejects precision loss',r['code']==422)
    r=p.get('/parishioner/apply_service.php?ajax=submit',booking_data);master_app=r['json'].get('app_id')
    check('User-defined booking amount snapshot',bool(master_app) and sql(f'SELECT fee_snapshot FROM applications WHERE id={master_app}')=='77.25',r['json'])
    if master_app:
        r=s.get('/staff/services.php?ajax=update_field',dict(field,id=fid,field_name='renamed_participant',field_label='New label',field_type='textarea',sort_order=2))
        check('Form field update persists',r['json'].get('success') and sql(f'SELECT field_label FROM service_fields WHERE id={fid}')=='New label')
        check('Historical form labels preserved','Participant' in sql(f'SELECT form_schema FROM applications WHERE id={master_app}'))
        r=s.get('/staff/application_details.php?id='+str(master_app));check('Secretary full historical details',r['code']==200 and 'Original Participant' in r['text'] and 'Participant &quot;Name&quot;' in r['text'],r['code'])
        r=s.get('/staff/application_details.php?id='+str(master_app),{'fields[participant]':'Corrected Participant'});check('Secretary audited correction',r['code']==200 and 'Corrected Participant' in sql(f'SELECT form_data FROM applications WHERE id={master_app}') and sql(f"SELECT COUNT(*) FROM audit_trail WHERE action='correct_application' AND entity_id={master_app}")=='1')
        check('Cross parish detail denied',s.get('/staff/application_details.php?id=2')['code']==404)
        before=sql(f'SELECT payment_status FROM applications WHERE id={master_app}')
        r=p.get('/parishioner/requests.php?type=cancel',{'application_id':master_app,'request_type':'cancel','reason':'Cannot attend this schedule'})
        check('Cancellation waits for Secretary confirmation',r['code']==200 and sql(f'SELECT status FROM applications WHERE id={master_app}')=='pending' and sql(f'SELECT payment_status FROM applications WHERE id={master_app}')==before,r['code'])
        cancel_id=sql(f"SELECT MAX(id) FROM application_requests WHERE application_id={master_app} AND request_type='cancel'")
        check('Bookkeeper cannot confirm cancellation',b.get('/staff/requests.php?type=cancel',{'id':cancel_id,'decision':'approve'})['code']==422)
        r=s.get('/staff/requests.php?type=cancel',{'id':cancel_id,'decision':'approve','review_note':'Confirmed cancellation'})
        check('Secretary confirmation cancels without refund',r['code']==200 and sql(f'SELECT status FROM applications WHERE id={master_app}')=='cancelled' and sql(f'SELECT payment_status FROM applications WHERE id={master_app}')==before)
        check('Cancellation reason and timestamp persisted',sql(f"SELECT COUNT(*) FROM application_requests WHERE application_id={master_app} AND request_type='cancel' AND completed_at IS NOT NULL")=='1')
        check('Cancellation Secretary history visible','Cannot attend this schedule' in s.get('/staff/requests.php?type=cancel')['text'])
        check('Duplicate cancellation blocked',p.get('/parishioner/requests.php?type=cancel',{'application_id':master_app,'request_type':'cancel','reason':'Duplicate cancellation'})['code']==422)
        check('Cancelled booking cannot be paid',p.get('/parishioner/apply_service.php?ajax=payment',{'application_id':master_app,'amount':'77.25','payment_method':'cash'})['code']==422)
        check('Cancelled booking cannot be rescheduled',p.get('/parishioner/requests.php?type=reschedule',{'application_id':master_app,'request_type':'reschedule','reason':'Try another date','proposed_schedule':'2035-05-12T09:00'})['code']==422)
        r=p.get(f'/parishioner/apply_service.php?ajax=availability&service_id={sid}&parish_id=1&month=2035-05');check('Availability releases cancelled slot',r['json'].get('ok') and next(d for d in r['json']['days'] if d['date']=='2035-05-10')['count']==0)
    r=s.get('/staff/services.php?ajax=delete',{'id':sid});check('Service deletion preserves history',r['json'].get('success') and sql(f'SELECT status FROM services WHERE id={sid}')=='inactive' and sql(f'SELECT COUNT(*) FROM service_fields WHERE service_id={sid}')=='1')

def voucher(kind,amount,**extra):
    return dict(document_type=kind,document_date='2035-02-01',party_name='Master Payee',amount=amount,description='Master expense',reference='CHK-MASTER',bank_name='Test Bank',bank_account_number='000123',secretary_signatory='Secretary',finance_signatory='Finance VP',priest_signatory='Priest',request_key=hashlib.sha256(os.urandom(32)).hexdigest(),**extra)
check('Check voucher over 20000 rejected',b.get('/staff/accounting.php?type=check_voucher',voucher('check_voucher','20000.01'))['code']==422)
check('Petty cash over 500 rejected',b.get('/staff/accounting.php?type=petty_cash_voucher',voucher('petty_cash_voucher','500.01'))['code']==422)
entry=voucher('check_voucher','20000.00');r=b.get('/staff/accounting.php?type=check_voucher',entry);vid=sql("SELECT MAX(id) FROM accounting_documents WHERE document_type='check_voucher'")
check('Check voucher 20000 boundary accepted',r['code']==200 and vid!='NULL',r['code'])
if vid!='NULL':
    r=b.get('/staff/accounting.php?type=journal_voucher',dict(voucher('journal_voucher','999999'),original_reference='document:'+vid,description='Corrected expense particulars',correction_reason='Correct the original particulars'))
    jid=sql("SELECT MAX(id) FROM accounting_documents WHERE document_type='journal_voucher'")
    check('Journal correction is separate immutable history',r['code']==200 and jid!='NULL' and sql(f'SELECT description FROM accounting_documents WHERE id={vid}')=='Master expense' and sql(f'SELECT amount FROM accounting_documents WHERE id={jid}')=='0.00',r['code'])
    check('Journal original particulars recorded',jid!='NULL' and sql(f'SELECT original_particulars FROM accounting_documents WHERE id={jid}')=='Master expense')
    sql(f'DELETE FROM accounting_documents WHERE id IN ({vid},{jid if jid!="NULL" else 0})') # Only synthetic rows, retain baseline cardinality.

before=sql("SELECT COUNT(*) FROM accounting_documents WHERE document_type='petty_cash_voucher'")
for n in range(20):
    r=b.get('/staff/accounting.php?type=petty_cash_voucher',voucher('petty_cash_voucher','500.00'))
check('Petty cash 10000 set boundary accepted',r['code']==200 and int(sql("SELECT COUNT(*) FROM accounting_documents WHERE document_type='petty_cash_voucher'"))==int(before)+20)
check('Petty cash set overflow rejected',b.get('/staff/accounting.php?type=petty_cash_voucher',voucher('petty_cash_voucher','0.01'))['code']==422)
check('Replenishment refuses uncompleted drafts',b.get('/staff/accounting.php?type=petty_cash_voucher',{'_action':'replenish'})['code']==422)
# Remove only this block's synthetic rows so older tests retain their assertions.
sql("DELETE FROM accounting_documents WHERE document_type='petty_cash_voucher' AND party_name='Master Payee'")

a.auto_verify=False
check('Central record menu requires password','verify_password.php' in a.get('/admin/main_database.php')['url'])
r=a.get('/admin/verify_password.php',{'password':fixture['password']});check('Admin password unlocks central records',r['code']==200 and 'MAIN DATABASE' in r['text'],r['code'])
a.auto_verify=True
approved=sql("SELECT a.id FROM applications a JOIN services s ON s.id=a.service_id WHERE a.status='approved' AND s.general_type='Baptism' LIMIT 1")
if approved:
    r=a.get('/admin/main_database.php?id='+approved);check('Central approved application details',r['code']==200 and 'Applicant Information' in r['text'],r['code'])
    r=a.get('/admin/main_database.php?id='+approved+'&download=1');check('Central application download audited',r['code']==200 and sql(f"SELECT COUNT(*) FROM audit_trail WHERE action='central_record_download' AND entity_id={approved}")=='1')
check('Non-admin central records denied',s.get('/admin/main_database.php')['code']==403)
check('Dashboard invalid date rejected',p.get('/parishioner/dashboard.php?date_from=bad')['code']==422)
check('Dashboard empty period hides applications','Original Participant' not in p.get('/parishioner/dashboard.php?date_from=1901-01-01&date_to=1901-01-02')['text'])

