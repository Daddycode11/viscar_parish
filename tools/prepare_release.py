"""List candidate commit/deployment files without initializing or staging the user's repo."""
import subprocess,json,re,hashlib
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
private=ROOT/'storage/private/release-audit.git'
if not private.exists():subprocess.run(['git','init','--bare',str(private)],check=True,capture_output=True)
git=['git','--git-dir='+str(private),'--work-tree='+str(ROOT)]
paths=subprocess.check_output(git+['ls-files','--others','--exclude-standard'],cwd=ROOT,text=True).splitlines()
for forbidden in ['config.local.php','config.production.php','database/indigo_church.sql','storage/private/revision-fixture.json']:
 assert forbidden not in paths, 'Sensitive path included: '+forbidden
assert not any(p.startswith(('audit/','revision-evidence/','.browser-tools/','.audit-tools/','system/')) for p in paths)
assert not any(p.startswith(('uploads/','storage/','logs/')) and not p.endswith('.htaccess') for p in paths)
findings=[]
patterns={
 'private_key':r'-----BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY-----',
 'provider_token':r'\b(?:ghp_|github_pat_|sk_live_)[A-Za-z0-9_]{12,}',
 'literal_secret':r"(?i)(?:define\(\s*['\"](?:SMTP_PASS|SMTP_PASSWORD|SMS_API_TOKEN)['\"]\s*,|['\"](?:SMTP_PASSWORD|SMS_API_TOKEN|DB_PASSWORD)['\"]\s*=>)\s*['\"]([^'\"]+)['\"]",
}
for name in paths:
 p=ROOT/name
 if p.suffix.lower() not in ['.php','.js','.py','.json','.sql','.md','.txt','.ini','.env']:continue
 text=p.read_text(encoding='utf-8',errors='replace')
 for kind,pattern in patterns.items():
  for m in re.finditer(pattern,text):
   if kind=='literal_secret' and (m.group(1).startswith('REPLACE') or name.startswith('tools/test_')):continue
   findings.append({'file':name,'line':text.count('\n',0,m.start())+1,'kind':kind})
(root_out:=ROOT/'deployment').mkdir(exist_ok=True)
(root_out/'COMMIT_FILES.txt').write_text('\n'.join(paths)+'\n')
allowed_tools={'tools/deployment_preflight.php','tools/migrate_production.php','tools/migrate_compatibility.php','tools/migrate_separation.php','tools/migrate_master.php','tools/migrate_attachments.php','tools/deliver_announcements.php','tools/reminders.php','tools/.htaccess'}
deploy=[p for p in paths if (p.startswith(('admin/','staff/','parishioner/','public/','includes/','assets/','database/')) or p in allowed_tools or p in ['index.php','loading.php','verify_otp.php','.htaccess','storage/.htaccess','storage/private/.htaccess','uploads/.htaccess','uploads/requirements/.htaccess','logs/.htaccess'])]
(root_out/'DEPLOY_FILES.txt').write_text('\n'.join(deploy)+'\n')
(root_out/'SECRET_SCAN.json').write_text(json.dumps({'candidate_files':len(paths),'findings':findings,'note':'Values redacted. Heuristic current-tree scan, not a history scan or proof that every secret format is detected.'},indent=2))
print('Candidate commit files:',len(paths),'Runtime deployment files:',len(deploy),'Unresolved secret patterns:',len(findings))
for f in findings:print(f)
if findings:raise SystemExit(1)
