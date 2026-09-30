"""Regression checks for gaps found in the 14-page primary PDF."""
empty_check=voucher('check_voucher','15.00');empty_check['reference']=''
check('PDF: check number required by server',b.get('/staff/accounting.php?type=check_voucher',empty_check)['code']==422)
for kind in ['deposit','disbursement','petty_cash_voucher']:
    original=sql(f"SELECT id FROM accounting_documents WHERE document_type='{kind}' LIMIT 1")
    if original:
        r=b.get('/staff/accounting.php?type=journal_voucher',dict(voucher('journal_voucher','0'),original_reference='document:'+original,correction_reason='Wrong source type',description='Forbidden correction'))
        check('PDF: journal rejects '+kind,r['code']==422)
check('PDF: Bookkeeper cannot replenish',b.get('/staff/petty_cash.php')['code']==403)
check('PDF: Parishioner cannot replenish',p.get('/staff/petty_cash.php')['code']==403)
check('PDF: Admin cannot replenish',a.get('/staff/petty_cash.php')['code']==403)
current=sql("SELECT id FROM petty_cash_sets WHERE parish_id=1 AND status='open' ORDER BY id DESC LIMIT 1")
check('PDF: replenishment CSRF protected',s.get('/staff/petty_cash.php',{'_action':'replenish','set_id':current},csrf=False)['code']==403)
check('PDF: stale replenishment refused',s.get('/staff/petty_cash.php',{'_action':'replenish','set_id':'999999'})['code']==422)
entry=voucher('petty_cash_voucher','1.00');r=b.get('/staff/accounting.php?type=petty_cash_voucher',entry)
doc=sql("SELECT MAX(id) FROM accounting_documents WHERE document_type='petty_cash_voucher'")
check('PDF: Secretary cannot replenish pending drafts',s.get('/staff/petty_cash.php',{'_action':'replenish','set_id':current})['code']==422)
b.get('/staff/accounting.php?type=petty_cash_voucher',{'_action':'complete','id':doc})
r=s.get('/staff/petty_cash.php',{'_action':'replenish','set_id':current})
check('PDF: Secretary replenishment closes own set',r['code']==200 and sql(f'SELECT status FROM petty_cash_sets WHERE id={current}')=='closed',r['code'])
check('PDF: replenishment audit identifies Secretary',sql(f"SELECT COUNT(*) FROM audit_trail WHERE action='replenish_petty_cash' AND entity_id={current} AND user_id=2")=='1')
check('PDF: repeated replenishment refused',s.get('/staff/petty_cash.php',{'_action':'replenish','set_id':current})['code']==422)
check('PDF: Secretary still cannot create vouchers',s.get('/staff/accounting.php',voucher('check_voucher','1.00'))['code']==403)
app=sql("SELECT a.id FROM applications a JOIN sacramental_records r ON r.application_id=a.id WHERE a.status='approved' AND a.checked_in_at IS NULL AND r.certificate_generated_at IS NOT NULL LIMIT 1")
if app:
    original=sql(f'SELECT form_data FROM applications WHERE id={app}')
    certificates=sql(f'SELECT * FROM sacramental_records WHERE application_id={app}')
    schema=json.loads(sql(f'SELECT form_schema FROM applications WHERE id={app}'))
    answers=json.loads(original)
    fields={'fields['+field['field_name']+']':answers.get(field['field_name'],'') for field in schema if field['field_type']!='file'}
    text_fields=[field for field in schema if field['field_type'] in ['text','textarea']]
    if text_fields: fields['fields['+text_fields[0]['field_name']+']']='Audited correction after issue'
    r=s.get('/staff/application_details.php?id='+app,fields)
    check('PDF: approved answers can be corrected after certificate issue',r['code']==200 and (not text_fields or 'Audited correction after issue' in sql(f'SELECT form_data FROM applications WHERE id={app}')),r['code'])
    check('PDF: correction preserves issued certificate snapshot',certificates==sql(f'SELECT * FROM sacramental_records WHERE application_id={app}'))
# Exercise announcement actions against a disposable synthetic announcement.
sql("INSERT INTO announcements(title,content,message,target,channel,sent_by,status) VALUES('PDF edit probe','Original','Original','All Parishes','In-App',1,'active')")
announcement=sql("SELECT MAX(id) FROM announcements WHERE title='PDF edit probe'")
r=a.get('/admin/announcements.php',{'_action':'edit_announcement','id':announcement,'title':'PDF edited','content':'Updated particulars'})
check('PDF: announcement edit persists',r['code']==200 and sql(f'SELECT content FROM announcements WHERE id={announcement}')=='Updated particulars')
r=a.get('/admin/announcements.php',{'_action':'delete_announcement','id':announcement})
check('PDF: announcement removal preserves inactive record',r['code']==200 and sql(f'SELECT status FROM announcements WHERE id={announcement}')=='inactive')

# Latest 15-page reference: requirement editing and association-based parish access.
sql("INSERT INTO applications(user_id,parish_id,service_id,schedule,status) VALUES(4,1,1,'2038-06-12 09:00:00','approved')")
cancel_app=sql('SELECT MAX(id) FROM applications')
r=p.get('/parishioner/requests.php?type=cancel',{'request_type':'cancel','application_id':cancel_app,'reason':'Unable to attend approved booking'})
cancel_request=sql(f"SELECT MAX(id) FROM application_requests WHERE application_id={cancel_app}")
check('Final PDF: approved cancellation remains active until review',r['code']==200 and sql(f'SELECT status FROM applications WHERE id={cancel_app}')=='approved')
check('Final PDF: duplicate pending cancellation rejected',p.get('/parishioner/requests.php?type=cancel',{'request_type':'cancel','application_id':cancel_app,'reason':'Duplicate cancellation'})['code']==422)
check('Final PDF: cancellation notification links to correct tab',sql("SELECT COUNT(*) FROM notifications WHERE user_id=2 AND link='requests.php?type=cancel'")!='0')
r=s.get('/staff/requests.php?type=cancel',{'id':cancel_request,'decision':'reject','review_note':'Please confirm the booking reference'})
check('Final PDF: rejected cancellation preserves approved booking',r['code']==200 and sql(f'SELECT status FROM applications WHERE id={cancel_app}')=='approved')
r=s.get('/staff/services.php?ajax=update_requirement',{'id':1,'document_name':'Certificate reviewed','description':'Clear original scan','is_required':1})
check('Final PDF: requirement edit persists',r['json'].get('success') and sql('SELECT document_name FROM service_requirements WHERE id=1')=='Certificate reviewed')
check('Final PDF: requirement edit CSRF protected',s.get('/staff/services.php?ajax=update_requirement',{'id':1,'document_name':'Forbidden'},csrf=False)['code']==403)
sql("INSERT INTO service_requirements(service_id,document_name) VALUES(2,'Other parish requirement')")
other_req=sql('SELECT MAX(id) FROM service_requirements')
check('Final PDF: requirement edit parish scoped',s.get('/staff/services.php?ajax=update_requirement',{'id':other_req,'document_name':'Forbidden'})['code']==404)
check('Final PDF: unrelated parishioner remains private',s.get('/staff/parishioners.php?ajax=get&id=5')['code']==404)
sql("INSERT INTO parish_subscriptions(user_id,parish_id,in_app,email,sms) VALUES(5,1,0,1,0) ON DUPLICATE KEY UPDATE email=1")
r=s.get('/staff/parishioners.php?ajax=get&id=5')
check('Final PDF: subscriber visible without home parish change',r['json'].get('success') and sql('SELECT parish_id FROM users WHERE id=5')=='2')
check('Final PDF: subscriber application history stays parish scoped',all(str(x['id'])!='2' for x in r['json'].get('data',{}).get('applications',[])))
r=s.get('/staff/messages.php?ajax=send',{'receiver_id':5,'subject':'Parish inquiry','body':'Subscriber reply'},json_body=True)
check('Final PDF: Secretary can reply to subscriber',r['json'].get('ok') and sql("SELECT COUNT(*) FROM messages WHERE sender_id=2 AND receiver_id=5 AND parish_id=1 AND body='Subscriber reply'")=='1',r['json'])
sql('DELETE FROM parish_subscriptions WHERE user_id=5 AND parish_id=1')
sql("INSERT INTO applications(user_id,parish_id,service_id,schedule,status) VALUES(5,1,1,'2037-01-10 09:00:00','pending')")
r=s.get('/staff/messages.php?ajax=send',{'receiver_id':5,'subject':'Application inquiry','body':'Applicant reply'},json_body=True)
check('Final PDF: Secretary can reply to applicant from another home parish',r['json'].get('ok') and sql("SELECT COUNT(*) FROM messages WHERE sender_id=2 AND receiver_id=5 AND parish_id=1 AND body='Applicant reply'")=='1',r['json'])
for kind in ['revenue','refund','receipt','check_voucher','petty_cash_voucher','disbursement','deposit','journal_voucher']:
    r=a.get('/admin/reports.php?type='+kind+'&date_from=2020-01-01&date_to=2040-01-01&export=csv')
    check('Final PDF: Admin audit CSV '+kind,r['code']==200 and 'Payee / payer' in r['text'] and '<html' not in r['text'])
    r=a.get('/admin/reports.php?type='+kind+'&date_from=2020-01-01&date_to=2040-01-01&format=print')
    check('Final PDF: Admin printable audit '+kind,r['code']==200 and 'Audit Report' in r['text'] and 'Fatal error' not in r['text'])

# Dashboard dates and pagination use one selected period without losing older records.
for i in range(55):
    sql("INSERT INTO applications(user_id,parish_id,service_id,schedule,status,created_at) VALUES(4,1,1,'2036-01-20 09:00:00','pending','2036-01-10 10:00:00')")
dashboard_app=sql('SELECT MAX(id) FROM applications')
sql(f"INSERT INTO payments(application_id,amount,status,created_at,paid_at,verified_at,reference_number) VALUES({dashboard_app},342.21,'completed','2035-12-20 10:00:00','2036-01-11 10:00:00','2036-01-12 10:00:00','DASHBOARD-PERIOD-PROBE')")
dashboard_payment=sql('SELECT MAX(id) FROM payments')
period='/parishioner/dashboard.php?date_from=2036-01-01&date_to=2036-01-31'
first=p.get(period+'&ajax=live')['json'];second=p.get(period+'&applications_page=2&ajax=live')['json']
check('Final PDF: dashboard pagination reaches all matching applications',len(first.get('applications',[]))==50 and len(second.get('applications',[]))==5 and not ({x['id'] for x in first['applications']} & {x['id'] for x in second['applications']}))
check('Final PDF: dashboard payment history uses verification date','DASHBOARD-PERIOD-PROBE' in p.get(period)['text'])
earlier=p.get('/parishioner/dashboard.php?date_from=2035-12-01&date_to=2035-12-31')['text']
check('Final PDF: original payment creation date does not bypass period','DASHBOARD-PERIOD-PROBE' not in earlier)
html=p.get(period)['text']
check('Final PDF: filtered payment total agrees with history','342.21' in html and 'Next applications' in html and 'date_from=2036-01-01' in html)
check('Final PDF: backup CSRF remains enforced',a.get('/admin/backup.php',{'_action':'manual_backup'},csrf=False)['code']==403)
check('Final PDF: backup remains Admin-only',s.get('/admin/backup.php')['code']==403 and p.get('/admin/backup.php')['code']==403)
check('Final PDF: restore rejects missing confirmation',a.get('/admin/backup.php',{'_action':'restore','confirmation':'wrong'})['code']==422)
