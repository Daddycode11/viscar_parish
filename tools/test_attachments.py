import os,json,re,subprocess,time,urllib.request,urllib.parse,urllib.error,http.cookiejar,base64
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
PHP='C:/xampp/php/php.exe';MYSQL='C:/xampp/mysql/bin/mysql.exe';BASE='http://127.0.0.1:8780'
OUT=ROOT/'storage/private';fixture=json.loads((OUT/'revision-fixture.json').read_text());assert fixture['database'].startswith('vicar_revision_')
env=os.environ.copy();env.update(DB_NAME=fixture['database'],APP_ENV='local',APP_URL=BASE)
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
def check(name,ok,detail=None):results.append({'test':name,'passed':bool(ok)});print(('PASS ' if ok else 'FAIL ')+name,flush=True);print(detail if not ok else '',flush=True)
png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')
log=(OUT/'attachments-http.log').open('w')
server=subprocess.Popen([PHP,'-d','upload_tmp_dir='+str(OUT/'upload-tmp'),'-d','disable_functions=mail,curl_exec,fsockopen,stream_socket_client','-S','127.0.0.1:8780','-t',str(ROOT)],env=env,cwd=ROOT,stdout=log,stderr=log)
try:
 time.sleep(1);exec(compile((ROOT/'tools/attachment_checks.py').read_text(encoding='utf-8'),'attachment_checks.py','exec'),globals())
finally:
 server.terminate();server.wait(timeout=10);log.close()
 (ROOT/'attachment-test-results.json').write_text(json.dumps({'tests':results,'passed':sum(r['passed'] for r in results),'failed':sum(not r['passed'] for r in results)},indent=2))
if any(not r['passed'] for r in results):raise SystemExit(1)
