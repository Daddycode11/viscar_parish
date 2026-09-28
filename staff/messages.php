<?php
require_once __DIR__ . '/../includes/access.php';
$memberScope=parish_member_sql($scopeParish);
require_once __DIR__ . '/../includes/workflow_routes.php';

$page_id = 'messages'; $page_title = 'Messages'; $page_sub = 'Communication';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/../includes/notifications.php';

if(($_GET['ajax']??'')==='resolve_help'){
 if($_SERVER['REQUEST_METHOD']!=='POST'||$user['role']!=='secretary')fail_request('Secretary POST required.',405);
 $conn->execute_query('UPDATE help_conversations SET staff_active=0 WHERE user_id=? AND parish_id=? AND secretary_id=?',[(int)($_POST['user_id']??0),$user['parish_id'],$user['id']]);
 header('Content-Type: application/json');echo json_encode(['ok'=>true]);exit;
}
// ── AJAX Endpoints ──────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $me = $user['id'];

    // List conversations (unique parishioners)
    if ($_GET['ajax'] === 'conversations') {
        $stmt = $conn->prepare("
            SELECT u.id, u.name, u.email,
                (SELECT body FROM messages m2
                 WHERE m2.parish_id={$scopeParish} AND ((m2.sender_id=u.id AND m2.receiver_id=?) OR (m2.sender_id=? AND m2.receiver_id=u.id))
                 ORDER BY m2.created_at DESC LIMIT 1) as last_message,
                (SELECT created_at FROM messages m3
                 WHERE m3.parish_id={$scopeParish} AND ((m3.sender_id=u.id AND m3.receiver_id=?) OR (m3.sender_id=? AND m3.receiver_id=u.id))
                 ORDER BY m3.created_at DESC LIMIT 1) as last_time,
                (SELECT COUNT(*) FROM messages m4
                 WHERE m4.parish_id={$scopeParish} AND m4.sender_id=u.id AND m4.receiver_id=? AND m4.is_read=0) as unread_count
            FROM (SELECT * FROM users WHERE {$memberScope} OR id IN (SELECT user_id FROM help_conversations WHERE parish_id = {$scopeParish})) u
            WHERE u.role='parishioner' AND u.id IN (
                SELECT DISTINCT sender_id FROM messages WHERE parish_id={$scopeParish} AND receiver_id=?
                UNION
                SELECT DISTINCT receiver_id FROM messages WHERE parish_id={$scopeParish} AND sender_id=?
            )
            ORDER BY last_time DESC
        ");
        $stmt->bind_param('iiiiiii', $me,$me,$me,$me,$me,$me,$me);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['ok'=>true,'conversations'=>$rows]);
        exit;
    }

    // Get thread with a specific user
    if ($_GET['ajax'] === 'thread' && isset($_GET['user_id'])) {
        $uid = (int)$_GET['user_id'];
        $stmt = $conn->prepare("
            SELECT m.*, u.name as sender_name
            FROM messages m
            JOIN (SELECT * FROM users WHERE {$memberScope} OR id IN (SELECT user_id FROM help_conversations WHERE parish_id = {$scopeParish})) u ON u.id = m.sender_id
            WHERE m.parish_id={$scopeParish} AND ((m.sender_id=? AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=?))
            ORDER BY m.created_at ASC
        ");
        $stmt->bind_param('iiii', $me,$uid,$uid,$me);
        $stmt->execute();
        $msgs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['ok'=>true,'messages'=>$msgs]);
        exit;
    }

    // Send message
    if ($_GET['ajax'] === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $receiver_id = (int)($input['receiver_id'] ?? 0);
        $subject = trim($input['subject'] ?? '');
        $body = trim($input['body'] ?? '');

        if (!$receiver_id || !$body) {
            echo json_encode(['ok'=>false,'error'=>'Receiver and message body are required.']);
            exit;
        }

        $recipient=$conn->execute_query("SELECT id,role,parish_id,status FROM users WHERE id=? AND status='active'",[$receiver_id])->fetch_assoc();
        if(!$recipient || strlen($body)>2000 || strlen($subject)>255)fail_request('Invalid recipient or message length.',422);
        if($user['role']!=='secretary'||$recipient['role']!=='parishioner')fail_request('Secretary-to-parishioner messages only.');
        $thread=$conn->execute_query('SELECT id FROM help_conversations WHERE user_id=? AND parish_id=? AND secretary_id=?',[$receiver_id,$user['parish_id'],$user['id']])->fetch_assoc();
        $member=$conn->execute_query('SELECT id FROM users WHERE id=? AND '.parish_member_sql($scopeParish),[$receiver_id])->fetch_assoc();
        if(!$member&&!$thread)fail_request('Recipient is outside your parish.');
        $conn->execute_query('UPDATE help_conversations SET staff_active=1 WHERE user_id=? AND parish_id=? AND secretary_id=?',[$receiver_id,$user['parish_id'],$user['id']]);
        $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, parish_id, subject, body) VALUES (?, ?, ?, ?, ?)");
        $parish = $user['parish_id'];
        $stmt->bind_param('iiiss', $me, $receiver_id, $parish, $subject, $body);

        if ($stmt->execute()) {
            notify($receiver_id, 'New Message', $user['name'] . ' sent you a message', 'message', 'messages.php');
            $messageId = $stmt->insert_id;
            $channels = [];
            if (!empty($input['send_sms'])) $channels[] = 'sms';
            if (!empty($input['send_email'])) $channels[] = 'email';
            $delivery = dispatch_to_user($receiver_id, $subject ?: 'Parish message', $body, $channels, 'message');
            echo json_encode(['ok'=>true, 'id'=>$messageId, 'delivery'=>$delivery,
                'notification_message'=>$channels ? delivery_feedback($delivery) : 'Message saved in-app.']);
        } else {
            echo json_encode(['ok'=>false,'error'=>'Failed to send message.']);
        }
        exit;
    }

    // Mark messages as read
    if ($_GET['ajax'] === 'mark_read' && isset($_GET['user_id'])) {
        $uid = (int)$_GET['user_id'];
        $stmt = $conn->prepare("UPDATE messages SET is_read=1 WHERE parish_id={$scopeParish} AND sender_id=? AND receiver_id=? AND is_read=0");
        $stmt->bind_param('ii', $uid, $me);
        $stmt->execute();
        echo json_encode(['ok'=>true,'affected'=>$stmt->affected_rows]);
        exit;
    }

    // Search parishioners
    if ($_GET['ajax'] === 'search_users' && isset($_GET['q'])) {
        $q = '%' . trim($_GET['q']) . '%';
        $stmt = $conn->prepare("SELECT id, name, email FROM (SELECT * FROM users WHERE {$memberScope} OR id IN (SELECT user_id FROM help_conversations WHERE parish_id = {$scopeParish})) users WHERE role='parishioner' AND status='active' AND (name LIKE ? OR email LIKE ?) ORDER BY name LIMIT 20");
        $stmt->bind_param('ss', $q, $q);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['ok'=>true,'users'=>$rows]);
        exit;
    }

    echo json_encode(['ok'=>false,'error'=>'Unknown action']);
    exit;
}
?>

<style>
/* ── Chat Layout ─────────────────────────────────────────── */
.msg-container{display:flex;height:calc(100vh - var(--header-h) - 100px);gap:0;background:var(--white);border-radius:var(--r);border:1px solid rgba(255,255,255,.8);box-shadow:var(--sh);overflow:hidden}

/* Left panel — conversation list */
.msg-sidebar{width:300px;min-width:300px;border-right:1px solid var(--ink-10);display:flex;flex-direction:column;background:#FAFAF8}
.msg-sb-head{padding:14px 16px;border-bottom:1px solid var(--ink-10);display:flex;align-items:center;justify-content:space-between;gap:8px;flex-shrink:0}
.msg-sb-head h3{font-family:var(--fh);font-size:1rem;font-weight:600}
.msg-search{width:100%;padding:8px 12px;border:1.5px solid var(--ink-10);border-radius:8px;font-family:var(--fb);font-size:.8rem;outline:none;background:#fff;transition:border-color var(--ease)}
.msg-search:focus{border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-dim)}
.msg-sb-search{padding:8px 16px;border-bottom:1px solid var(--ink-10);flex-shrink:0}
.conv-list{flex:1;overflow-y:auto}
.conv-list::-webkit-scrollbar{width:4px}
.conv-list::-webkit-scrollbar-thumb{background:var(--ink-10);border-radius:2px}
.conv-item{display:flex;align-items:center;gap:12px;padding:14px 16px;cursor:pointer;border-bottom:1px solid var(--ink-10);transition:background var(--ease);position:relative}
.conv-item:hover{background:rgba(201,168,76,.06)}
.conv-item.active{background:var(--gold-dim);border-left:3px solid var(--gold)}
.conv-avatar{width:40px;height:40px;border-radius:50%;background:var(--navy);color:var(--gold-lt);display:grid;place-items:center;font-family:var(--fh);font-size:1rem;font-weight:600;flex-shrink:0}
.conv-info{flex:1;min-width:0}
.conv-name{font-size:.82rem;font-weight:500;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.conv-preview{font-size:.72rem;color:var(--ink-60);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
.conv-meta{display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0}
.conv-time{font-size:.65rem;color:var(--ink-30)}
.conv-badge{background:var(--wine);color:var(--white);font-size:.6rem;font-weight:600;padding:2px 7px;border-radius:20px;min-width:18px;text-align:center}

/* Right panel — thread */
.msg-thread{flex:1;display:flex;flex-direction:column;background:#F0EDE8}
.msg-thread-head{padding:14px 20px;border-bottom:1px solid var(--ink-10);background:var(--white);display:flex;align-items:center;gap:12px;flex-shrink:0}
.msg-thread-head .conv-avatar{width:36px;height:36px;font-size:.9rem}
.msg-thread-name{font-size:.9rem;font-weight:500}
.msg-thread-email{font-size:.72rem;color:var(--ink-60)}
.thread-messages{flex:1;overflow-y:auto;padding:20px;display:flex;flex-direction:column;gap:10px}
.thread-messages::-webkit-scrollbar{width:5px}
.thread-messages::-webkit-scrollbar-thumb{background:var(--ink-10);border-radius:3px}
.msg-bubble{max-width:70%;padding:10px 16px;border-radius:14px;font-size:.82rem;line-height:1.55;position:relative;word-wrap:break-word}
.msg-bubble.mine{align-self:flex-end;background:var(--gold);color:var(--ink);border-bottom-right-radius:4px}
.msg-bubble.theirs{align-self:flex-start;background:var(--white);color:var(--ink);border:1px solid var(--ink-10);border-bottom-left-radius:4px}
.msg-bubble .msg-sender{font-size:.68rem;font-weight:600;margin-bottom:3px;color:var(--navy)}
.msg-bubble.mine .msg-sender{color:var(--ink)}
.msg-bubble .msg-time{font-size:.62rem;color:var(--ink-30);margin-top:4px;text-align:right}
.msg-bubble.mine .msg-time{color:rgba(26,21,16,.45)}
.msg-subject{font-size:.72rem;font-weight:600;color:var(--navy);margin-bottom:4px;font-style:italic}
.msg-bubble.mine .msg-subject{color:var(--ink)}

/* Send form */
.msg-send{padding:12px 20px;background:var(--white);border-top:1px solid var(--ink-10);display:flex;gap:10px;align-items:flex-end;flex-shrink:0}
.msg-send textarea{flex:1;resize:none;border:1.5px solid var(--ink-10);border-radius:10px;padding:10px 14px;font-family:var(--fb);font-size:.82rem;outline:none;min-height:42px;max-height:120px;transition:border-color var(--ease)}
.msg-send textarea:focus{border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-dim)}
.msg-send .send-btn{padding:10px 20px;border-radius:10px;background:var(--navy);color:var(--white);border:none;font-family:var(--fb);font-size:.8rem;font-weight:500;cursor:pointer;transition:var(--ease);white-space:nowrap}
.msg-send .send-btn:hover{background:var(--gold);color:var(--ink)}

/* Empty / no selection states */
.msg-empty{flex:1;display:grid;place-items:center;text-align:center;color:var(--ink-30)}
.msg-empty .empty-icon{font-size:3rem;margin-bottom:12px}
.msg-empty p{font-size:.85rem}

/* Compose modal extras */
.compose-results{max-height:180px;overflow-y:auto;border:1px solid var(--ink-10);border-radius:8px;margin-top:4px;background:#FAFAF8}
.compose-results:empty{display:none}
.compose-result-item{padding:10px 14px;cursor:pointer;font-size:.82rem;border-bottom:1px solid var(--ink-10);transition:background var(--ease)}
.compose-result-item:hover{background:var(--gold-dim)}
.compose-result-item:last-child{border-bottom:none}
.compose-result-item small{color:var(--ink-60);margin-left:6px}
.selected-user{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;background:var(--gold-dim);border-radius:20px;font-size:.78rem;font-weight:500;margin-top:6px}
.selected-user .remove{cursor:pointer;color:var(--wine);font-weight:700;margin-left:4px}

/* Back button for mobile */
.msg-back-btn{display:none;padding:6px 12px;border-radius:8px;background:none;border:1px solid var(--ink-10);font-size:.78rem;cursor:pointer;margin-right:8px;color:var(--ink-60)}

@media(max-width:768px){
  .msg-sidebar{width:100%;min-width:100%}
  .msg-container{flex-direction:column;height:auto;min-height:calc(100vh - var(--header-h) - 100px)}
  .msg-thread{display:none}
  .msg-container.thread-open .msg-sidebar{display:none}
  .msg-container.thread-open .msg-thread{display:flex;min-height:calc(100vh - var(--header-h) - 100px)}
  .msg-back-btn{display:inline-block}
}
</style>

<div class="sec-head">
  <div>
    <div class="sec-tag">Communication</div>
    <h1 class="sec-title">Messages</h1>
    <p class="sec-sub">Communicate with parishioners</p>
  </div>
  <button class="btn-sm btn-navy" onclick="openModal('composeModal')">[icon:edit] Compose</button>
</div>

<div class="msg-container" id="msgContainer">
  <!-- Left: Conversation list -->
  <div class="msg-sidebar">
    <div class="msg-sb-head">
      <h3>Conversations</h3>
    </div>
    <div class="msg-sb-search">
      <input type="text" class="msg-search" id="convSearch" placeholder="Filter conversations...">
    </div>
    <div class="conv-list" id="convList">
      <div class="msg-empty"><p>Loading conversations...</p></div>
    </div>
  </div>
  <!-- Right: Thread -->
  <div class="msg-thread" id="msgThread">
    <div class="msg-empty" id="threadEmpty">
      <div>
        <div class="empty-icon">[icon:mail]</div>
        <p>Select a conversation to view messages</p>
      </div>
    </div>
  </div>
</div>

<!-- Compose Modal -->
<div class="modal-wrap" id="composeModal">
  <div class="modal" style="max-width:500px">
    <h2>New Message</h2>
    <p>Search for a parishioner and compose your message.</p>
    <div class="form-group">
      <label>Recipient</label>
      <input type="text" id="composeSearch" class="msg-search" placeholder="Search by name or email..." autocomplete="off">
      <div class="compose-results" id="composeResults"></div>
      <div id="selectedRecipient"></div>
    </div>
    <div class="form-group">
      <label>Subject (optional)</label>
      <input type="text" id="composeSubject" style="width:100%;padding:9px 14px;border:1.5px solid var(--ink-10);border-radius:8px;font-family:var(--fb);font-size:.83rem;outline:none;background:#FAFAF8">
    </div>
    <div class="form-group">
      <label>Message</label>
      <textarea id="composeBody" rows="4" style="width:100%;padding:9px 14px;border:1.5px solid var(--ink-10);border-radius:8px;font-family:var(--fb);font-size:.83rem;outline:none;resize:vertical;background:#FAFAF8"></textarea>
    </div>
    <div class="form-group">
      <label><input type="checkbox" id="composeSms"> Also send SMS</label>
      <label><input type="checkbox" id="composeEmail"> Also send email</label>
      <p>Messages are always saved in-app. External delivery uses the recipient's registered contact details.</p>
    </div>
    <div class="modal-actions">
      <button class="btn-sm btn-outline" onclick="closeModal('composeModal')">Cancel</button>
      <button class="btn-sm btn-navy" id="composeSendBtn" onclick="sendCompose()">Send Message</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>
<div class="loading-overlay" id="loadingOverlay"><div class="spinner"></div></div>

<script>
var currentUserId = <?php echo (int)$user['id']; ?>;
var activeConvUserId = null;
var composeRecipientId = null;
var conversations = [];

// ── Load conversations ──────────────────────────────────────
function loadConversations() {
  fetch('messages.php?ajax=conversations')
    .then(r => r.json())
    .then(data => {
      if (!data.ok) return;
      conversations = data.conversations;
      renderConversations(conversations);
    });
}

function renderConversations(list) {
  var el = document.getElementById('convList');
  if (!list.length) {
    el.innerHTML = '<div class="msg-empty"><div><div class="empty-icon">[icon:message]</div><p>No conversations yet</p></div></div>';
    return;
  }
  el.innerHTML = list.map(function(c) {
    var initial = (c.name || '?').charAt(0).toUpperCase();
    var preview = c.last_message ? (c.last_message.length > 40 ? c.last_message.substring(0,40) + '...' : c.last_message) : '';
    var timeAgo = c.last_time ? formatTimeAgo(c.last_time) : '';
    var badge = parseInt(c.unread_count) > 0 ? '<span class="conv-badge">' + c.unread_count + '</span>' : '';
    var activeClass = (activeConvUserId == c.id) ? ' active' : '';
    return '<div class="conv-item' + activeClass + '" onclick="openThread(' + c.id + ',\'' + escHtml(c.name) + '\',\'' + escHtml(c.email) + '\')">' +
      '<div class="conv-avatar">' + initial + '</div>' +
      '<div class="conv-info"><div class="conv-name">' + escHtml(c.name) + '</div><div class="conv-preview">' + escHtml(preview) + '</div></div>' +
      '<div class="conv-meta">' + (timeAgo ? '<span class="conv-time">' + timeAgo + '</span>' : '') + badge + '</div></div>';
  }).join('');
}

// ── Open thread ─────────────────────────────────────────────
function openThread(userId, name, email) {
  activeConvUserId = userId;
  document.getElementById('msgContainer').classList.add('thread-open');

  // Mark as read
  fetch('messages.php?ajax=mark_read&user_id=' + userId);

  var initial = (name || '?').charAt(0).toUpperCase();
  var threadEl = document.getElementById('msgThread');
  threadEl.innerHTML =
    '<div class="msg-thread-head">' +
      '<button class="msg-back-btn" onclick="closeThread()">← Back</button>' +
      '<div class="conv-avatar">' + initial + '</div>' +
      '<div><div class="msg-thread-name">' + escHtml(name) + '</div><div class="msg-thread-email">' + escHtml(email) + '</div></div>' +
    '</div>' +
    '<button type="button" class="btn-sm btn-outline" onclick="resolveHelp()">Resolve inquiry / enable FAQ assistant</button>' +
    '<div class="thread-messages" id="threadMessages"><div class="msg-empty"><p>Loading...</p></div></div>' +
    '<div class="msg-send">' +
      '<textarea id="threadInput" placeholder="Type a message..." rows="1" onkeydown="handleThreadKey(event)"></textarea>' +
      '<button class="send-btn" onclick="sendThreadMessage()">Send</button>' +
    '</div>';

  loadThread(userId);
  loadConversations(); // refresh badges
}

function closeThread() {
  activeConvUserId = null;
  document.getElementById('msgContainer').classList.remove('thread-open');
  document.getElementById('msgThread').innerHTML =
    '<div class="msg-empty" id="threadEmpty"><div><div class="empty-icon">[icon:mail]</div><p>Select a conversation to view messages</p></div></div>';
}

function loadThread(userId) {
  fetch('messages.php?ajax=thread&user_id=' + userId)
    .then(r => r.json())
    .then(data => {
      if (!data.ok) return;
      var el = document.getElementById('threadMessages');
      if (!data.messages.length) {
        el.innerHTML = '<div class="msg-empty"><div><div class="empty-icon">[icon:message]</div><p>No messages yet. Start the conversation!</p></div></div>';
        return;
      }
      el.innerHTML = data.messages.map(function(m) {
        var mine = (m.sender_id == currentUserId);
        var cls = mine ? 'msg-bubble mine' : 'msg-bubble theirs';
        var subjectHtml = m.subject ? '<div class="msg-subject">' + escHtml(m.subject) + '</div>' : '';
        return '<div class="' + cls + '">' +
          '<div class="msg-sender">' + escHtml(Number(m.is_bot) ? 'FAQ assistant' : m.sender_name) + '</div>' +
          subjectHtml +
          '<div>' + escHtml(m.body) + '</div>' +
          '<div class="msg-time">' + formatTimeAgo(m.created_at) + '</div>' +
        '</div>';
      }).join('');
      el.scrollTop = el.scrollHeight;
    });
}

// ── Send from thread ────────────────────────────────────────
function sendThreadMessage() {
  var input = document.getElementById('threadInput');
  var body = (input.value || '').trim();
  if (!body || !activeConvUserId) return;

  fetch('messages.php?ajax=send', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({receiver_id: activeConvUserId, subject:'', body: body})
  })
  .then(r => r.json())
  .then(data => {
    if (data.ok) {
      input.value = '';
      loadThread(activeConvUserId);
      loadConversations();
    } else {
      showToast(data.error || 'Failed to send', 'error');
    }
  });
}

function handleThreadKey(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendThreadMessage();
  }
}

// ── Compose Modal ───────────────────────────────────────────
var composeTimer = null;
document.getElementById('composeSearch').addEventListener('input', function() {
  clearTimeout(composeTimer);
  var q = this.value.trim();
  if (q.length < 2) { document.getElementById('composeResults').innerHTML = ''; return; }
  composeTimer = setTimeout(function() {
    fetch('messages.php?ajax=search_users&q=' + encodeURIComponent(q))
      .then(r => r.json())
      .then(data => {
        if (!data.ok) return;
        var el = document.getElementById('composeResults');
        el.innerHTML = data.users.map(function(u) {
          return '<div class="compose-result-item" onclick="selectRecipient(' + u.id + ',\'' + escHtml(u.name) + '\',\'' + escHtml(u.email) + '\')">' +
            escHtml(u.name) + '<small>' + escHtml(u.email) + '</small></div>';
        }).join('');
      });
  }, 300);
});

function selectRecipient(id, name, email) {
  composeRecipientId = id;
  document.getElementById('composeResults').innerHTML = '';
  document.getElementById('composeSearch').value = '';
  document.getElementById('selectedRecipient').innerHTML =
    '<div class="selected-user">' + escHtml(name) + ' <small>(' + escHtml(email) + ')</small> <span class="remove" onclick="clearRecipient()">&times;</span></div>';
}

function clearRecipient() {
  composeRecipientId = null;
  document.getElementById('selectedRecipient').innerHTML = '';
}

function sendCompose() {
  if (!composeRecipientId) { showToast('Please select a recipient.', 'error'); return; }
  var body = (document.getElementById('composeBody').value || '').trim();
  if (!body) { showToast('Please enter a message.', 'error'); return; }

  var subject = (document.getElementById('composeSubject').value || '').trim();

  var sendButton = document.getElementById('composeSendBtn');
  if (sendButton.disabled) return;
  sendButton.disabled = true;
  var recipientId = composeRecipientId;
  setLoading(true);
  fetch('messages.php?ajax=send', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({receiver_id: recipientId, subject: subject, body: body, send_sms: document.getElementById('composeSms').checked, send_email: document.getElementById('composeEmail').checked})
  })
  .then(r => r.json())
  .then(data => {
    setLoading(false);
    if (data.ok) {
      showToast('Message sent!', 'success');
      closeModal('composeModal');
      document.getElementById('composeBody').value = '';
      document.getElementById('composeSubject').value = '';
      clearRecipient();
      loadConversations();
      // Open the thread with the new recipient
      document.getElementById('composeSms').checked = false;
      document.getElementById('composeEmail').checked = false;
      openThread(recipientId, '', '');
    } else {
      showToast(data.error || 'Failed to send', 'error');
    }
  }).catch(function () { showToast('Connection interrupted. Check the conversation before retrying.', 'error'); })
    .finally(function () { setLoading(false); sendButton.disabled = false; });
}

// ── Filter conversations ────────────────────────────────────
document.getElementById('convSearch').addEventListener('input', function() {
  var q = this.value.toLowerCase();
  var filtered = conversations.filter(function(c) {
    return c.name.toLowerCase().indexOf(q) !== -1 || c.email.toLowerCase().indexOf(q) !== -1;
  });
  renderConversations(filtered);
});

// ── Helpers ─────────────────────────────────────────────────
function formatTimeAgo(dateStr) {
  if (!dateStr) return '';
  var d = new Date(dateStr.replace(' ', 'T'));
  var now = new Date();
  var diff = Math.floor((now - d) / 1000);
  if (diff < 60) return 'just now';
  if (diff < 3600) return Math.floor(diff/60) + 'm ago';
  if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
  if (diff < 604800) return Math.floor(diff/86400) + 'd ago';
  return d.toLocaleDateString();
}

function escHtml(s) {
  if (!s) return '';
  var d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}

// ── Auto-refresh ────────────────────────────────────────────
setInterval(function() {
  loadConversations();
  if (activeConvUserId) loadThread(activeConvUserId);
}, 15000);

// ── Init ────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  loadConversations();
});
function resolveHelp(){if(!activeConvUserId)return;const fd=new FormData();fd.append('user_id',activeConvUserId);fetch('messages.php?ajax=resolve_help',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.ok)showToast('Inquiry resolved. FAQ assistant enabled.');});}
</script>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>
