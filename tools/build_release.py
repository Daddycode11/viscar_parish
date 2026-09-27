"""Build a code-only archive from the reviewed runtime manifest; never deploy it."""
from pathlib import Path
import zipfile,hashlib
root=Path(__file__).resolve().parent.parent
names=(root/'deployment/DEPLOY_FILES.txt').read_text().splitlines()
output=root/'deployment/viscar-hostinger-code.zip'
with zipfile.ZipFile(output,'w',zipfile.ZIP_DEFLATED) as archive:
 for name in names:
  path=Path(name)
  assert not path.is_absolute() and '..' not in path.parts
  assert not name.startswith(('config.local','config.production','audit/','revision-evidence/'))
  assert not name.startswith(('uploads/','storage/','logs/')) or name.endswith('.htaccess')
  source=root/('deployment/hostinger.htaccess' if name=='.htaccess' else name)
  archive.write(source,name)
 archive.write(root/'config.production.example.php','config.production.example.php')
print(output.name,output.stat().st_size,'bytes; SHA-256',hashlib.sha256(output.read_bytes()).hexdigest())
