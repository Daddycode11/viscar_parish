"""Recommendation (2).pdf checks using the runner's isolated database and clients."""
service_data={'name':'PDF Revision Capacity','general_type':'Other / Custom','classification':'Non-Sacramental','fee':0,'status':'active','schedule_mode':'fixed','time_slots':'09:00,10:00','slot_capacity':2}
r=s.get('/staff/services.php?ajax=create',service_data);pdf_sid=r['json'].get('id')
check('PDF: create service without daily-limit field',bool(pdf_sid),r['json'])
if pdf_sid:
 sql(f'UPDATE services SET max_daily_limit=3 WHERE id={pdf_sid}')
 r=s.get('/staff/services.php?ajax=update',dict(service_data,id=pdf_sid))
 check('PDF: editing without daily limit preserves stored limit',r['json'].get('success') and sql(f'SELECT max_daily_limit FROM services WHERE id={pdf_sid}')=='3')
 r=p.get('/parishioner/apply_service.php?ajax=submit',{'parish_id':1,'service_id':pdf_sid,'schedule':'2039-01-10T09:00','form_data':'{}'})
 pdf_app=r['json'].get('app_id');check('PDF: booking persists',bool(pdf_app),r['json'])
 if pdf_app:
  days=p.get(f'/parishioner/apply_service.php?ajax=availability&parish_id=1&service_id={pdf_sid}&month=2039-01')['json']['days']
  day=next(x for x in days if x['date']=='2039-01-10')
  check('PDF: daily and per-time remaining counts',day['remaining']==2 and day['slots'][0]['remaining']==1 and day['slots'][1]['remaining']==2,day)
  html=o.get('/parishioner/events.php?parish_id=1&month=2039-01')['text']
  check('PDF: parish schedules visible without private application links','PDF Revision Capacity' in html and f'application.php?id={pdf_app}' not in html and 'Audit User 4' not in html)
  sql(f'''UPDATE applications SET form_schema='[{{"field_name":"answer","field_label":"Answer","field_type":"text","is_required":1}}]',form_data='{{"answer":"Before"}}' WHERE id={pdf_app}''')
  r=p.get(f'/parishioner/application.php?id={pdf_app}',{'fields[answer]':'After'})
  check('PDF: owner edits pending answers with audit',r['code']==200 and 'After' in sql(f'SELECT form_data FROM applications WHERE id={pdf_app}') and sql(f"SELECT COUNT(*) FROM audit_trail WHERE entity_id={pdf_app} AND action='edit_application'")=='1')
  check('PDF: edit requires CSRF',p.get(f'/parishioner/application.php?id={pdf_app}',{'fields[answer]':'Blocked'},csrf=False)['code']==403)
  check('PDF: other owner cannot edit',o.get(f'/parishioner/application.php?id={pdf_app}',{'fields[answer]':'Blocked'})['code']==404 and 'After' in sql(f'SELECT form_data FROM applications WHERE id={pdf_app}'))
  sql(f"UPDATE applications SET status='approved' WHERE id={pdf_app}")
  check('PDF: approved answers remain locked',p.get(f'/parishioner/application.php?id={pdf_app}',{'fields[answer]':'Blocked'})['code']==422 and 'After' in sql(f'SELECT form_data FROM applications WHERE id={pdf_app}'))
check('PDF: daily-limit editor removed and per-time retained','svcMaxDaily' not in s.get('/staff/services.php')['text'] and 'Applications per time' in s.get('/staff/services.php')['text'])
check('PDF: secretary sees refund history read-only','name="decision"' not in s.get('/staff/requests.php?type=refund')['text'] and 'Refunds' in s.get('/staff/requests.php?type=refund')['text'])
refund_id=sql("SELECT MAX(id) FROM application_requests WHERE request_type='refund'")
if refund_id!='NULL':check('PDF: secretary cannot approve refund',s.get('/staff/requests.php?type=refund',{'id':refund_id,'decision':'approve'})['code']==422)
check('PDF: payment details submitted only during application','Submit Payment Details' not in p.get('/parishioner/payments.php')['text'])
check('PDF: parishioner dashboard removals',all(x not in p.get('/parishioner/dashboard.php')['text'] for x in ['<h3>Payment History</h3>','Service applications over time']))
check('PDF: removed staff dashboard panels',all(x not in b.get('/staff/dashboard.php')['text'] for x in ['<h3>Pending Payments</h3>','<h3>Service Breakdown</h3>','<h3>Recent Activity</h3>']) and '<h3>Pending Applications</h3>' not in s.get('/staff/dashboard.php')['text'])
check('PDF: service comparison chart and exact values','Service trend legend' in b.get('/staff/dashboard.php')['text'] and 'View exact monthly values' in b.get('/staff/dashboard.php')['text'])
check('PDF: login organization label','Apostolic Vicariate of San Jose in Occidental Mindoro' in anon.get('/public/login.php')['text'])
sql('DELETE FROM security_rate_limits')
lock_client=Client()
for attempt in range(1,4):
 text=lock_client.get('/public/login.php',{'email':'audit6@example.invalid','password':'wrong-pdf-password'})['text']
 check(f'PDF: login attempt {attempt} indicator',f'{attempt} / 3' in text)
check('PDF: lockout countdown shown','loginCountdown' in text)
