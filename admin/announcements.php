<?php
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/workflow_routes.php';


require_once '../includes/db.php';
require_once '../includes/notifications.php';
$user = currentUser();
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['_action'] ?? '';
  if ($act === 'send_announcement') {
    require_once __DIR__ . '/../includes/announcement_delivery.php';
    try {
        $result = publish_announcement($user, $_POST);
        $flash = 'success:' . $result['message'];
    } catch (Throwable $error) {
        http_response_code(422);
        $flash = 'amber:' . ($error instanceof DomainException ? $error->getMessage() : 'Unable to publish announcement.');
    }
  }

  if (in_array($act,['delete_announcement','edit_announcement'],true)) {
    try {
      revision_transaction(function() use($conn,$user,$act){
        $id=(int)($_POST['id']??0);
        if (!$conn->execute_query('SELECT id FROM announcements WHERE id=? FOR UPDATE',[$id])->fetch_row()) throw new DomainException('Announcement not found.');
        if($act==='delete_announcement') {
          $conn->execute_query("UPDATE announcements SET status='inactive' WHERE id=?",[$id]);
          $conn->execute_query("UPDATE announcement_deliveries SET status='cancelled' WHERE announcement_id=? AND status='queued'",[$id]);
        } else {
          $title=input_text($_POST,'title');$content=input_text($_POST,'content',10000);
          if($title===''||$content==='')throw new DomainException('Enter a title and message.');
          $conn->execute_query('UPDATE announcements SET title=?,content=?,message=? WHERE id=?',[$title,$content,$content,$id]);
        }
        auditLog($user['id'],$act,'announcement',$id);
      });
      $flash='success:Announcement updated.';
    }catch(Throwable $e){error_log('Announcement update: '.$e->getMessage());$flash='amber:'.($e instanceof DomainException?$e->getMessage():'Unable to update announcement.');http_response_code(422);}
  }

}


// Fetch announcements from DB
$sent_announcements = [];
$res = $conn->query("SELECT a.*, u.name AS sender FROM announcements a LEFT JOIN users u ON a.sent_by = u.id WHERE a.status='active' ORDER BY COALESCE(a.sent_at,a.created_at) DESC, a.id DESC LIMIT 100");
if ($res) {
    while($row = $res->fetch_assoc()) $sent_announcements[] = $row;
}

// Fetch parishes from DB
$parishes_list = [];
$res = $conn->query("SELECT id, name FROM parishes ORDER BY name");
while($row = $res->fetch_assoc()) $parishes_list[] = $row;

$page_id    = 'announcements';
$page_title = 'Announcements';
$page_sub   = 'Communication';
include 'includes/layout.php';
?>

<?php if($flash): [$ftype,$fmsg] = explode(':',$flash,2); ?>
<div class="notice notice-<?php echo $ftype==='success'?'green':'amber'; ?> flash-msg" style="margin-bottom:20px;transition:opacity .5s">
  <span><?php echo $ftype==='success'?'[icon:check]':'[icon:alert]'; ?></span>
  <span><?php echo htmlspecialchars($fmsg); ?></span>
</div>
<?php endif; ?>

<div class="sec-head">
  <div class="sec-head-left">
    <div class="sec-tag">Communication</div>
    <h1 class="sec-title">Announcements</h1>
    <p class="sec-sub">Broadcast messages to all parishes, specific parishes, or staff only.</p>
  </div>
</div>

<?php require APP_ROOT . '/includes/announcement_status.php'; ?>
<div class="grid-1-2">
  <!-- Compose -->
  <div class="card">
    <div class="card-head"><h3>Compose Announcement</h3></div>
    <div class="card-body">
      <form method="POST" action="announcements.php">
        <input type="hidden" name="_action" value="send_announcement">
        <input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(32)) ?>">
        <fieldset><button type="button" class="btn-sm btn-outline" onclick="this.parentElement.querySelectorAll('input[type=checkbox]').forEach(el=>el.checked=true)">Select All</button><legend><?= h(t('Selected parishes')) ?></legend><?php foreach($parishes_list as $parish): ?><label><input type="checkbox" name="parish_ids[]" value="<?= (int)$parish['id'] ?>"> <?= h($parish['name']) ?></label><?php endforeach; ?></fieldset>
        <div class="form-group">
          <label>Send To *</label>
          <select name="target" required><option value="All Parishes">Everyone</option><option value="Staff Only">Staff Only</option><option value="All Parishioners">Parishioners Only</option></select>
        </div>
        <div class="form-group">
          <label>Subject *</label>
          <input type="text" name="subject" required placeholder="Announcement title…">
        </div>
        <div class="form-group">
          <label>Message *</label>
          <textarea name="message" rows="6" required placeholder="Write your announcement here…"></textarea>
        </div>
        <div class="form-group">
          <label>Channel</label>
          <select name="channel">
            <option value="In-App">In-App Notification</option>
            <option value="Email">Email</option>
            <option value="SMS">SMS</option>
            <option value="All Channels">All Channels</option>
          </select>
        </div>
        <button type="submit" class="btn-sm btn-navy" style="width:100%;padding:11px">[icon:announcement] Send Announcement</button>
      </form>
    </div>
  </div>

  <!-- Sent list -->
  <div class="card">
    <div class="card-head">
      <h3>Sent Announcements</h3>
      <span class="card-tag"><?php echo count($sent_announcements); ?> total</span>
    </div>
    <div class="card-body" style="padding:0">
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr><th>Subject</th><th>Target</th><th>Channel</th><th>Date</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach($sent_announcements as $a):
              $target  = $a['target']  ?? '—';
              $channel = $a['channel'] ?? 'In-App';
              $when    = $a['sent_at'] ?? $a['created_at'] ?? null;
              $pill    = $target === 'All Parishes' ? 'pill-navy' : ($target === 'Staff Only' ? 'pill-gold' : 'pill-green');
            ?>
            <tr>
              <td style="font-weight:500"><?php echo htmlspecialchars($a['title']); ?></td>
              <td>
                <span class="pill <?php echo $pill; ?>">
                  <?php echo htmlspecialchars($target); ?>
                </span>
              </td>
              <td style="font-size:.75rem;color:var(--ink-60)"><?php echo htmlspecialchars($channel); ?></td>
              <td style="font-size:.73rem;color:var(--ink-30)"><?php echo $when ? date('M j, Y', strtotime($when)) : '—'; ?></td>
              <td><details><summary>View / Edit</summary><form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="edit_announcement"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><label>Title<input name="title" value="<?= h($a['title']) ?>" required maxlength="255"></label><label>Message<textarea name="content" required maxlength="10000"><?= h($a['content']) ?></textarea></label><button>Save</button></form></details><form method="post" onsubmit="return confirm('Delete this announcement?')"><?= csrf_field() ?><input type="hidden" name="_action" value="delete_announcement"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button>Delete</button></form></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include 'includes/layout_footer.php'; ?>
