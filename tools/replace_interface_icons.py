"""Replace source emoji decorations with named markers for the local SVG renderer."""
from pathlib import Path
import re, html, json

ROOT = Path(__file__).resolve().parent.parent
groups = {
 'check':'2713 2714 2705', 'close':'2715 2716 274c 274e',
 'alert':'26a0 2757 203c', 'announcement':'1f4e2 1f4e3',
 'attachment':'1f4ce', 'clipboard':'1f4cb', 'search':'1f50d 1f50e 2315',
 'eye':'1f441', 'file':'1f4c4 1f4dd 1f4dc 1f5d2', 'church':'26ea 271d 271e 1f54a 1f56f 1f4ff 1f64f',
 'user':'1f464', 'users':'1f465', 'sun':'2600 26c5',
 'chart':'1f4ca 1f4c8 1f4c9 25c8 2b50', 'save':'1f4be',
 'calendar':'1f4c5 1f4c6', 'print':'1f5a8', 'bell':'1f514',
 'pin':'1f4cd', 'phone':'1f4de 1f4f2', 'mail':'2709 1f4e7',
 'edit':'270e 270f 270d 1f58c', 'book':'1f4d7 1f4d6',
 'wallet':'1f4b0 1f4b3', 'ban':'1f6ab', 'settings':'2699',
 'lock':'1f512 1f513', 'help':'2753 2754', 'folder':'1f4c1 1f5c2',
 'message':'1f4ac 1f916', 'plus':'271a 2726', 'archive':'1f5c3 1f4e5',
 'menu':'2630', 'receipt':'1f9fe', 'square':'2610', 'rings':'1f48d',
 'dashboard':'229e 229f 25a6 25a3', 'refresh':'21bb 21ba', 'swap':'21c4 21c5',
 'clock':'23f3 23f0 231b', 'logout':'23cb 238b', 'download':'2b07',
}
mapping = {chr(int(code,16)):name for name,codes in groups.items() for code in codes.split()}
paths = []
for folder in ['admin','staff','parishioner','public']:
 paths.extend(p for p in (ROOT/folder).rglob('*') if p.suffix in ['.php','.js','.css'])
paths += [ROOT/name for name in ['index.php','loading.php','verify_otp.php']]
changed=[]
for path in paths:
 source=path.read_text(encoding='utf-8-sig');original=source
 def replace(char):return '[icon:'+mapping[char]+']' if char in mapping else char
 source=re.sub(r'&(?:#(?:x[0-9a-f]+|[0-9]+)|check|cross|checkmark|crossmark);',lambda m:replace(html.unescape(m[0])) if html.unescape(m[0]) in mapping else m[0],source,flags=re.I)
 source=re.sub(r'\\u([0-9a-f]{4})',lambda m:replace(chr(int(m[1],16))) if chr(int(m[1],16)) in mapping else m[0],source,flags=re.I)
 source=''.join(replace(c) for c in source)
 source=source.replace(chr(0xfe0f),'').replace(chr(0xfe0e),'')
 # Currency remains text in amounts; convert only the navigation icon value.
 source=re.sub(r"('icon'\s*=>\s*')"+chr(0x20b1)+"(')",r'\1[icon:wallet]\2',source)
 source=re.sub(r"('icon'\s*=>\s*')\?(')",r'\1[icon:help]\2',source)
 if source!=original:
  path.write_text(source,encoding='utf-8');changed.append(str(path.relative_to(ROOT)))
manifest=ROOT/'icon-revision-files.json'
previous=json.loads(manifest.read_text()) if manifest.exists() else []
manifest.write_text(json.dumps(sorted(set(previous+changed)),indent=2))
print('Updated',len(changed),'interface files.')
