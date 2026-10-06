"""Executed by the isolated HTTP regression runner."""
sql("UPDATE users SET two_factor_enabled=0,status='active' WHERE id IN (1,2,4,5)")
sql('DELETE FROM security_rate_limits')
a,s,p,o=[Client() for _ in range(4)]
a.auto_verify=False
for client,uid in [(a,1),(s,2),(p,4),(o,5)]:client.get('/public/login.php',{'email':f'audit{uid}@example.invalid','password':fixture['password']})
def upload_many(client,path,fields,files):
 boundary='MultipleAttachmentBoundary'
 body=b''
 for key,value in fields.items():body+=f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()
 for key,name,payload in files:body+=f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"; filename="{name}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+payload+b'\r\n'
 body+=f'--{boundary}--\r\n'.encode()
 return client.get(path,raw=body,content_type='multipart/form-data; boundary='+boundary)
r=s.get('/staff/services.php?ajax=create',{'name':'Attachment Test','general_type':'Other / Custom','classification':'Non-Sacramental','fee':'0','amount_mode':'fixed','max_daily_limit':'0','status':'active'})
attachment_service=r['json']['id']
sql(f"INSERT INTO service_requirements(service_id,document_name,is_required) VALUES({attachment_service},'Valid ID',1)")
requirement=sql(f'SELECT id FROM service_requirements WHERE service_id={attachment_service}')
base={'payment_method':'cash','parish_id':1,'service_id':attachment_service,'schedule':'2039-04-10T08:00','form_data':'{}'}
pdf=b'%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF'
route='/parishioner/apply_service.php?ajax=submit'
r=upload_many(p,route,base,[(f'req_{requirement}','single.png',png)])
single=r['json'].get('app_id');check('Attachments: legacy single upload shape accepted',bool(single),r['json'])
base['schedule']='2039-04-11T08:00'
r=upload_many(p,route,{**base,'attachment_count':3},[(f'req_{requirement}[]','same.png',png),(f'req_{requirement}[]','same.png',png),(f'req_{requirement}[]','support.pdf',pdf)])
multi=r['json'].get('app_id');check('Attachments: mixed types and duplicate original names accepted',bool(multi),r['json'])
if multi:
 check('Attachments: three unique stored filenames',sql(f'SELECT COUNT(DISTINCT stored_name) FROM application_attachments WHERE application_id={multi}')=='3')
 check('Attachments: duplicate original names preserved',sql(f"SELECT COUNT(*) FROM application_attachments WHERE application_id={multi} AND original_name='same.png'")=='2')
 ids=sql(f'SELECT id FROM application_attachments WHERE application_id={multi} ORDER BY id').splitlines()
 for attachment in ids:
  check('Attachments: owner downloads '+attachment,p.get(f'/public/document.php?app={multi}&attachment={attachment}')['code']==200)
  check('Attachments: Secretary downloads '+attachment,s.get(f'/public/document.php?app={multi}&attachment={attachment}')['code']==200)
  check('Attachments: other owner blocked '+attachment,o.get(f'/public/document.php?app={multi}&attachment={attachment}')['code']==404)
 check('Attachments: mismatched application denied',p.get(f'/public/document.php?app={single}&attachment={ids[0]}')['code']==404)
 detail=s.get(f'/staff/application_details.php?id={multi}')
 check('Attachments: Secretary sees grouped names',all(x in detail['text'] for x in ['Valid ID','same.png','support.pdf']) and all('attachment='+x in detail['text'] for x in ids))
 a.get('/admin/verify_password.php',{'password':fixture['password']})
 detail=a.get(f'/admin/applications.php?ajax=get_app&id={multi}')
 check('Attachments: Admin sees all attachments',len(detail['json'].get('data',{}).get('docs',[]))==3)
 s.get('/staff/applications.php?ajax=request_docs',{'id':multi,'docs':'Baptismal Requirement','message':'Upload all pages'})
 req=sql(f'SELECT MAX(id) FROM application_document_requests WHERE application_id={multi}')
 r=upload_many(p,'/parishioner/documents.php?ajax=submit',{'request_id':req,'attachment_count':2},[('document[]','page_1.png',png),('document[]','page_2.pdf',pdf)])
 check('Attachments: request resubmission accepts multiple files',r['json'].get('ok') and sql(f'SELECT COUNT(*) FROM application_attachments WHERE application_id={multi}')=='5',r['json'])
 check('Attachments: completed resubmission leaves queue and files remain in application',all(x not in p.get('/parishioner/documents.php')['text'] for x in ['page_1.png','page_2.pdf']) and all(x in p.get(f'/parishioner/application.php?id={multi}')['text'] for x in ['page_1.png','page_2.pdf']))
for name,payload in [('invalid.php',b'<?php echo 1;'),('spoof.png',b'<?php echo 1;'),('large.png',png+b' '*(5*1024*1024))]:
 before=sql('SELECT COUNT(*) FROM applications')
 r=upload_many(p,route,base,[(f'req_{requirement}[]','valid.png',png),(f'req_{requirement}[]',name,payload)])
 check('Attachments: rejects entire invalid batch '+name,r['code']==422 and sql('SELECT COUNT(*) FROM applications')==before,r['json'])
r=upload_many(p,route,{**base,'attachment_count':2},[(f'req_{requirement}[]','single.png',png)])
check('Attachments: detects incomplete file count',r['code']==422)
if single:
 sql(f'DELETE FROM application_attachments WHERE application_id={single}')
 check('Attachments: old single record still downloads',p.get(f'/public/document.php?app={single}&key=req_{requirement}')['body']==png)
 check('Attachments: old single record still renders','Valid ID' in s.get(f'/staff/application_details.php?id={single}')['text'])
 subprocess.run([PHP,str(ROOT/'tools/migrate_attachments.php')],check=True,cwd=ROOT,env=env)
 subprocess.run([PHP,str(ROOT/'tools/migrate_attachments.php')],check=True,cwd=ROOT,env=env)
 check('Attachments: rerunnable legacy backfill avoids duplicates',sql(f'SELECT COUNT(*) FROM application_attachments WHERE application_id={single}')=='1')

walk={**base,'user_id':4,'schedule':'2042-05-02T09:00','attachment_count':2}
r=upload_many(s,'/staff/walk_in.php',walk,[(f'req_{requirement}[]','front.png',png),(f'req_{requirement}[]','back.png',png)])
walk_id=sql(f"SELECT MAX(id) FROM applications WHERE service_id={attachment_service} AND source='walk_in'")
check('Attachments: walk-in stores both files',r['code']==200 and walk_id!='NULL' and sql(f'SELECT COUNT(*) FROM application_attachments WHERE application_id={walk_id}')=='2')
oldtoken=p.token;p.token='';r=upload_many(p,route,base,[(f'req_{requirement}[]','no-csrf.png',png)]);p.token=oldtoken
check('Attachments: upload requires CSRF',r['code']==403)
