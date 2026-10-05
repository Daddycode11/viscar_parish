"""Recommendation.pdf regression cases, using the isolated runner's clients/database."""
slot_service={'name':'Recommendation Slots','general_type':'Other / Custom','classification':'Non-Sacramental','fee':0,'max_daily_limit':0,'status':'active','schedule_mode':'fixed','time_slots':'09:00, 10:30, 13:30','slot_capacity':2,'weekdays_present':1,'available_weekdays[]':'6'}
r=s.get('/staff/services.php?ajax=create',slot_service);slot_id=r['json'].get('id')
check('Recommendation: fixed service saves',bool(slot_id),r['json'])
if slot_id:
    booking_fields={'parish_id':1,'service_id':slot_id,'schedule':'2038-01-02T09:00','form_data':'{}'}
    check('Recommendation: rejects unlisted time',p.get('/parishioner/apply_service.php?ajax=submit',dict(booking_fields,schedule='2038-01-02T08:00'))['code']==422)
    check('Recommendation: rejects unavailable weekday',p.get('/parishioner/apply_service.php?ajax=submit',dict(booking_fields,schedule='2038-01-03T09:00'))['code']==422)
    first=p.get('/parishioner/apply_service.php?ajax=submit',booking_fields)['json'].get('app_id')
    second=o.get('/parishioner/apply_service.php?ajax=submit',booking_fields)['json'].get('app_id')
    check('Recommendation: two applicants may share capacity-two slot',bool(first and second))
    days=p.get(f'/parishioner/apply_service.php?ajax=availability&parish_id=1&service_id={slot_id}&month=2038-01')['json']['days']
    day=next(d for d in days if d['date']=='2038-01-02')
    check('Recommendation: full time hidden while other times remain available',not day['full'] and not day['slots'][0]['available'] and day['slots'][1]['available'])
    check('Recommendation: full time rejects another booking',p.get('/parishioner/apply_service.php?ajax=submit',booking_fields)['code']==422)
    if first:
        check('Recommendation: approved application can reschedule',s.get('/staff/applications.php?ajax=approve',{'id':first})['json'].get('success') and s.get('/staff/applications.php?ajax=assign_schedule',{'id':first,'schedule':'2038-01-02T10:30'})['json'].get('success'))
        p.get('/parishioner/requests.php?type=reschedule',{'application_id':first,'request_type':'reschedule','reason':'Need a later appointment','proposed_schedule':'2038-01-09T13:30'})
        check('Recommendation: request preserves original schedule',sql(f"SELECT original_schedule FROM application_requests WHERE application_id={first} ORDER BY id DESC LIMIT 1")=='2038-01-02 10:30:00')
    check('Recommendation: invalid slot edit is atomic',s.get('/staff/services.php?ajax=update',dict(slot_service,id=slot_id,time_slots='25:90'))['code']==422 and sql(f'SELECT time_slots FROM services WHERE id={slot_id}')=='["09:00","10:30","13:30"]')
    check('Recommendation: unlimited time capacity saves',s.get('/staff/services.php?ajax=update',dict(slot_service,id=slot_id,slot_capacity=0))['json'].get('success'))
check('Recommendation: parish search uses parish name',any(x['name']=='Audit Parish A' for x in p.get('/parishioner/messages.php?ajax=search_users&q=Audit%20Parish%20A')['json'].get('users',[])))
for client,path in [(s,'/staff/schedule.php?view=available&month=2038-01'),(p,'/parishioner/events.php?view=available&month=2038-01'),(b,'/staff/export.php')]:
    response=client.get(path);check('Recommendation: page '+path,response['code']==200 and 'Fatal error' not in response['text'])
check('Recommendation: report has one date filter',b.get('/staff/export.php')['text'].count('name="date_from"')==1)
check('Recommendation: privacy and terms linked',all(x in anon.get('/public/login.php')['text'] for x in ['privacy.php','terms.php']))
for invalid_phone in ['0912345678','091234567890','abc09123456789']:
    check('Recommendation: invalid profile phone '+invalid_phone,p.get('/parishioner/settings.php',{'_action':'save_profile','name':'Audit User 4','email':'audit4@example.invalid','phone':invalid_phone,'language':'en'})['code']==422)

def recommendation_upload(client,path,fields,file_key,payload=png):
    boundary='RecommendationUploadBoundary';body=b''
    for key,value in fields.items():body+=f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()
    body+=f'--{boundary}\r\nContent-Disposition: form-data; name="{file_key}"; filename="receipt.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+payload+f'\r\n--{boundary}--\r\n'.encode()
    return client.get(path,raw=body,content_type='multipart/form-data; boundary='+boundary)
check('Recommendation: bookkeeper cannot configure bank QR',recommendation_upload(b,'/staff/payment_methods.php',{'name':'Probe Bank','instructions':'Reference only'},'qr')['code']==403)
r=recommendation_upload(s,'/staff/payment_methods.php',{'name':'Probe Bank','instructions':'Use the parish account'},'qr')
method_id=sql("SELECT MAX(id) FROM parish_payment_methods WHERE name='Probe Bank'")
check('Recommendation: secretary configures manual payment QR',method_id!='NULL',r['code'])
if method_id!='NULL':
    check('Recommendation: parishioner sees configured method',any(str(m['id'])==method_id for m in p.get('/parishioner/apply_service.php?ajax=payment_methods&parish_id=1')['json']['methods']))
    check('Recommendation: bank QR image loads',p.get('/public/payment_file.php?method='+method_id)['body']==png)
    r=s.get('/staff/services.php?ajax=create',dict(slot_service,name='Manual Payment Probe',fee=25,schedule_mode='user_defined',weekdays_present=1,**{'available_weekdays[]':6}));pay_sid=r['json'].get('id')
    payment_fields={'parish_id':1,'service_id':pay_sid,'schedule':'2038-02-06T09:00','form_data':'{}','payment_method':'gcash','manual_method_id':method_id,'reference_number':'REC-PROOF-001'}
    check('Recommendation: digital bank requires proof',p.get('/parishioner/apply_service.php?ajax=submit',payment_fields)['code']==422)
    r=recommendation_upload(p,'/parishioner/apply_service.php?ajax=submit',payment_fields,'payment_proof');pay_app=r['json'].get('app_id')
    check('Recommendation: manual payment with proof remains pending',bool(pay_app) and sql(f"SELECT status FROM payments WHERE application_id={pay_app}")=='pending',r['json'])
    if pay_app:
        pay_id=sql(f'SELECT id FROM payments WHERE application_id={pay_app}')
        check('Recommendation: bookkeeper can read receipt proof',b.get('/public/payment_file.php?payment='+pay_id)['body']==png)
        check('Recommendation: other parishioner cannot read proof',o.get('/public/payment_file.php?payment='+pay_id)['code']==404)
        check('Recommendation: proof does not authorize Secretary payment verification',s.get('/staff/payments.php?ajax=verify',{'id':pay_id})['code']==403)
        check('Recommendation: bookkeeper approves proof payment',b.get('/staff/payments.php?ajax=verify',{'id':pay_id})['json'].get('success'))
sql('DELETE FROM security_rate_limits')
locked=Client()
for attempt in range(3):locked.get('/public/login.php',{'email':'audit6@example.invalid','password':'wrong-password'})
fresh=Client();r=fresh.get('/public/login.php',{'email':'audit6@example.invalid','password':fixture['password']})
check('Recommendation: three wrong passwords block a fresh session','dashboard.php' not in r['url'] and 'five minutes' in r['text'])
sql("UPDATE security_rate_limits SET window_started=UNIX_TIMESTAMP()-301")
r=fresh.get('/public/login.php',{'email':'audit6@example.invalid','password':fixture['password']})
check('Recommendation: expired login lock allows valid password','dashboard.php' in r['url'])
