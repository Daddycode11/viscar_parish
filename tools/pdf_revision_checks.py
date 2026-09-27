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
