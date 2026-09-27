"""Exercise delivery acceptance and rejection using loopback-only fake providers."""
import os, json, subprocess, threading, http.server, socketserver
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
fixture = json.loads((ROOT/'storage/private/revision-fixture.json').read_text())
assert fixture['database'].startswith('vicar_revision_')
env = os.environ.copy()
env.update(DB_HOST='127.0.0.1',DB_USER='root',DB_PASSWORD='',APP_URL='https://example.invalid',DB_NAME=fixture['database'], APP_ENV='production', EMAIL_ENABLED='0', SMS_API_TOKEN='')
results = []

def check(name, valid):
    results.append({'test': name, 'passed': bool(valid)})
    print(('PASS ' if valid else 'FAIL ') + name, flush=True)

def php(code, values=None):
    run_env = env.copy()
    if values: run_env.update(values)
    command = "require 'includes/notifications.php'; " + code
    result = subprocess.run(['C:/xampp/php/php.exe', '-r', command], cwd=ROOT, env=run_env, capture_output=True, text=True, check=True)
    return json.loads(result.stdout)

class SMS(http.server.BaseHTTPRequestHandler):
    reject = False
    malformed = False
    def do_POST(self):
        self.rfile.read(int(self.headers['Content-Length']))
        self.send_response(503 if self.reject else 200)
        self.end_headers()
        self.wfile.write(b'<html>gateway error</html>' if self.malformed else json.dumps({'status': 503 if self.reject else 200}).encode())
    def log_message(self, *args): pass

class SMTP(socketserver.StreamRequestHandler):
    reject = False
    def handle(self):
        self.wfile.write(b'220 local test provider\r\n')
        in_data = False
        while True:
            line = self.rfile.readline()
            if not line: break
            if in_data:
                if line == b'.\r\n':
                    self.wfile.write(b'250 accepted\r\n')
                    in_data = False
                continue
            if line.startswith(b'DATA'):
                self.wfile.write(b'354 send data\r\n'); in_data = True
            elif line.startswith(b'RCPT') and self.reject:
                self.wfile.write(b'550 rejected\r\n')
            elif line.startswith(b'QUIT'):
                self.wfile.write(b'221 closing\r\n'); break
            else:
                self.wfile.write(b'250 ok\r\n')

sms = http.server.HTTPServer(('127.0.0.1', 0), SMS)
smtp = socketserver.TCPServer(('127.0.0.1', 0), SMTP)
for service in [sms, smtp]:
    threading.Thread(target=service.serve_forever, daemon=True).start()
try:
    log = ROOT/'storage/private/email.log'
    before = log.stat().st_size if log.exists() else 0
    result = php("echo json_encode([send_email('test@example.invalid','Test','sensitive-test-body'),send_sms('09123456789','Test')]);")
    check('Production missing providers fail explicitly', all(not item['ok'] for item in result))
    check('Production missing email does not log reset body', (log.stat().st_size if log.exists() else 0) == before)
    sms_env = {'SMS_API_TOKEN': 'synthetic-provider-token', 'SMS_ENDPOINT': f'http://127.0.0.1:{sms.server_address[1]}'}
    check('SMS provider acceptance', php("echo json_encode(send_sms('09123456789','Test'));", sms_env)['ok'])
    SMS.malformed = True
    check('Malformed HTTP 200 SMS response is not delivery success', not php("echo json_encode(send_sms('09123456789','Test'));", sms_env)['ok'])
    SMS.malformed = False
    SMS.reject = True
    check('SMS provider rejection', not php("echo json_encode(send_sms('09123456789','Test'));", sms_env)['ok'])
    check('SMS invalid number rejected', not php("echo json_encode(send_sms('invalid','Test'));", sms_env)['ok'])
    smtp_env = {'EMAIL_ENABLED': '1', 'SMTP_HOST': '127.0.0.1', 'SMTP_PORT': str(smtp.server_address[1]), 'SMTP_TLS': '0', 'SMTP_USER': '', 'SMTP_PASSWORD': '', 'EMAIL_FROM_ADDRESS': 'sender@example.invalid'}
    check('Email provider acceptance', php("echo json_encode(send_email('test@example.invalid','Test','Test'));", smtp_env)['ok'])
    SMTP.reject = True
    check('Email provider rejection', not php("echo json_encode(send_email('test@example.invalid','Test','Test'));", smtp_env)['ok'])
    result = php("$conn->query(\"UPDATE users SET phone='',email='' WHERE id=5\"); echo json_encode(dispatch_to_user(5,'Test','Test',['sms','email']));")
    check('Missing contacts counted as failures', result['sms_failed'] == 1 and result['email_failed'] == 1)
    result = php("echo json_encode(dispatch_to_user(4,'Test','Test',['sms','email']));", {'APP_ENV':'local'})
    check('Local simulation not counted as provider acceptance', result['sms_simulated'] == 1 and result['email_simulated'] == 1 and result['sms_sent'] == 0 and result['email_sent'] == 0)
    check('Local SMS validates contacts before simulation', not php("echo json_encode(send_sms('invalid','Test'));", {'APP_ENV':'local'})['ok'])
    # A failed announcement remains failed on replay; it is never falsely marked accepted.
    php("$conn->query(\"INSERT INTO announcements(title,content,sent_by,target) VALUES('Transport test','Test',1,'All Parishes')\"); $id=$conn->insert_id; $conn->execute_query(\"INSERT INTO announcement_deliveries(announcement_id,user_id,channel) VALUES(?,4,'email')\",[$id]); require 'includes/announcement_delivery.php'; dispatch_announcement($id); dispatch_announcement($id); echo json_encode($conn->execute_query('SELECT status FROM announcement_deliveries WHERE announcement_id=?',[$id])->fetch_assoc());")
    result = php("echo json_encode($conn->query(\"SELECT status FROM announcement_deliveries ORDER BY id DESC LIMIT 1\")->fetch_assoc());")
    check('Failed announcement delivery persists on retry', result['status'] == 'failed')
    # Publishing returns immediately with queued external deliveries in production.
    result = php("require 'includes/announcement_delivery.php'; $actor=$conn->query('SELECT * FROM users WHERE id=1')->fetch_assoc(); echo json_encode(publish_announcement($actor,['subject'=>'Queue test','message'=>'Synthetic worker test','target'=>'All Staff','channel'=>'Email','request_key'=>bin2hex(random_bytes(32))]));")
    queued_id = result['id']
    expected = sum(int(row['total']) for row in result['delivery'] if row['status'] == 'queued')
    check('Production announcement queues external delivery', any(row['status'] == 'queued' for row in result['delivery']))
    SMTP.reject = False
    worker_env = env.copy(); worker_env.update(smtp_env)
    for _ in range(2):
        subprocess.run(['C:/xampp/php/php.exe','tools/deliver_announcements.php'],cwd=ROOT,env=worker_env,capture_output=True,text=True,check=True)
    result = php(f"echo json_encode($conn->query(\"SELECT status,COUNT(*) total FROM announcement_deliveries WHERE announcement_id={queued_id} AND channel='email' GROUP BY status\")->fetch_all(MYSQLI_ASSOC));")
    check('CLI worker completes queued email without replay duplicates', len(result) == 1 and result[0]['status'] == 'accepted' and int(result[0]['total']) == expected)
finally:
    for service in [sms, smtp]: service.shutdown(); service.server_close()
    (ROOT/'separation-transport-results.json').write_text(json.dumps(results, indent=2))
if any(not item['passed'] for item in results): raise SystemExit(1)
