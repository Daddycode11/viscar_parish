"""Fail closed on private paths and recognizable secrets in the actual Git index."""
import subprocess,re,sys
from pathlib import Path
root=Path(__file__).resolve().parent.parent
r=subprocess.run(['git','rev-parse','--show-toplevel'],cwd=root,capture_output=True,text=True)
if r.returncode:raise SystemExit('BLOCKED: no Git repository; staged content cannot be verified.')
files=subprocess.check_output(['git','ls-files','--cached','-z'],cwd=root).decode().split('\0');fail=[]
for name in filter(None,files):
 sensitive=(name in ['config.local.php','config.production.php','htaccess'] or name.startswith(('.git/','.env','audit/','revision-evidence/','.claude/','.codex/','.agents/','.browser-tools/','.audit-tools/','system/','deployment-private/')) or (name.startswith(('storage/','uploads/','logs/')) and not name.endswith('.htaccess')) or (name.endswith('.sql') and not name.startswith('database/migration')) or name.endswith(('.log','.zip','.bak','.pem','.key','.p12','.pfx')) or re.search(r'(^|/)[^/]*-(results|manifest|files)\.json$',name))
 if sensitive:fail.append({'file':name,'reason':'private/generated path'});continue
 if Path(name).suffix not in ['.php','.py','.js','.json','.sql','.txt','.md','.ini']:continue
 text=subprocess.check_output(['git','show',':'+name],cwd=root).decode('utf-8',errors='replace')
 for pattern in [r'-----BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY-----',r'\b(?:ghp_|github_pat_|sk_live_)[A-Za-z0-9_]{12,}',r"(?i)(?:define\(\s*['\"](?:SMTP_PASS|SMTP_PASSWORD|SMS_API_TOKEN)['\"]\s*,|['\"](?:SMTP_PASSWORD|SMS_API_TOKEN|DB_PASSWORD)['\"]\s*=>)\s*['\"]([^'\"]+)['\"]"]:
  for m in re.finditer(pattern,text):
   if m.lastindex and (m[1].startswith('REPLACE') or name.startswith('tools/test_')):continue
   fail.append({'file':name,'line':text.count('\n',0,m.start())+1,'reason':'secret pattern; value redacted'})
for item in fail:print(item)
print('Staged scan:',len(fail),'blocking findings. Manual review and history review remain required.')
sys.exit(1 if fail else 0)
