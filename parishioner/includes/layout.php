<?php
require_once __DIR__.'/../../includes/icons.php';
/**
 * Shared Parishioner Layout — Apostolic Vicariate of San Jose
 * Variables expected: $page_id, $page_title, $page_sub (optional)
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'parishioner') {
    header('Location: ../public/login.php');
    exit;
}
$user = currentUser();

// AJAX requests must not emit HTML — return control to the calling page so its
// JSON handler runs cleanly. $user/$conn are already set above.
if (isset($_GET['ajax'])) return;

if (!isset($page_id))    $page_id    = 'dashboard';
if (!isset($page_title)) $page_title = 'Dashboard';
if (!isset($page_sub))   $page_sub   = 'Overview';

// Notification count
$notif_count = 0;
$msg_count = 0;
if (isset($conn)) {
    $r = $conn->prepare("SELECT COUNT(*) as c FROM notifications WHERE user_id=? AND is_read=0");
    $r->bind_param('i', $user['id']);
    $r->execute();
    $notif_count = (int)($r->get_result()->fetch_assoc()['c'] ?? 0);

    $r2 = $conn->prepare("SELECT COUNT(*) as c FROM messages WHERE receiver_id=? AND is_read=0");
    $r2->bind_param('i', $user['id']);
    $r2->execute();
    $msg_count = (int)($r2->get_result()->fetch_assoc()['c'] ?? 0);
}

// Pending applications count
$pending_apps = 0;
$r3 = $conn->prepare("SELECT COUNT(*) as c FROM applications WHERE user_id=? AND status='pending'");
$r3->bind_param('i', $user['id']);
$r3->execute();
$pending_apps = (int)($r3->get_result()->fetch_assoc()['c'] ?? 0);

$nav = [
    'main' => [
        'section' => 'Main',
        'items' => [
            ['id' => 'dashboard',    'icon' => ui_icon('dashboard'), 'label' => 'Dashboard',       'href' => 'dashboard.php'],
            ['id' => 'apply',        'icon' => ui_icon('plus'), 'label' => 'Apply for Service','href' => 'apply_service.php'],
            ['id' => 'payments',     'icon' => ui_icon('wallet'), 'label' => 'My Payments',     'href' => 'payments.php'],
        ]
    ],
    'communication' => [
        'section' => 'Communication',
        'items' => [
            ['id' => 'messages',      'icon' => ui_icon('mail'), 'label' => 'Help & Messages', 'href' => 'help.php',       'badge' => $msg_count],
            ['id' => 'notifications', 'icon' => ui_icon('bell'), 'label' => 'Notifications',   'href' => 'notifications.php',  'badge' => $notif_count],
        ]
    ],
    'info' => [
        'section' => 'Information',
        'items' => [
            ['id' => 'faq',           'icon' => ui_icon('help'), 'label' => 'Help and FAQ',             'href' => 'faq.php'],
        ]
    ],
    'account' => [
        'section' => 'Account',
        'items' => [
            ['id' => 'settings',      'icon' => ui_icon('settings'), 'label' => 'Settings',        'href' => 'settings.php'],
        ]
    ],
];

$nav['revisions']=['section'=>'Services','items'=>[ ['id'=>'documents','icon'=>ui_icon('file'),'label'=>'Requested Documents','href'=>'documents.php'], ['id'=>'requests','icon'=>ui_icon('swap'),'label'=>'Refund / Reschedule','href'=>'requests.php'], ['id'=>'security','icon'=>ui_icon('lock'),'label'=>'Login Security','href'=>'security.php'] ]];
$nav['revisions']['items'][]=['id'=>'events','icon'=>ui_icon('calendar'),'label'=>'Event Calendar','href'=>'events.php']; $nav['revisions']['items'][]=['id'=>'announcements','icon'=>ui_icon('announcement'),'label'=>'Announcements','href'=>'announcements.php'];
?>
<!DOCTYPE html>
<html lang="<?= h($user['language'] ?? 'en') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($page_title); ?> — Parishioner Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
:root {
  --gold:#C9A84C;--gold-lt:#E8C97A;--gold-dim:rgba(201,168,76,.12);
  --navy:#1B2A4A;--navy-deep:#0D1828;--navy-mid:#243558;
  --cream:#FAF7F2;--ink:#1A1510;--ink-60:rgba(26,21,16,.6);--ink-30:rgba(26,21,16,.3);--ink-10:rgba(26,21,16,.07);
  --white:#FFFFFF;--green:#2A7A52;--green-dim:rgba(42,122,82,.12);
  --wine:#7A2A3A;--wine-dim:rgba(122,42,58,.12);
  --amber:#C97A20;--amber-dim:rgba(201,122,32,.12);
  --blue:#2A52A4;--blue-dim:rgba(42,82,164,.12);
  --sidebar-w:260px;--header-h:64px;
  --fh:"Cormorant Garamond",Georgia,serif;--fb:"DM Sans",sans-serif;
  --ease:.28s cubic-bezier(.4,0,.2,1);--r:12px;
  --sh:0 2px 16px rgba(26,21,16,.07);--sh-md:0 6px 28px rgba(26,21,16,.1);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:15px;scroll-behavior:smooth}
body{font-family:var(--fb);background:#F0EDE8;color:var(--ink);min-height:100vh;overflow-x:hidden}
a{text-decoration:none;color:inherit}img{display:block;max-width:100%}
button{cursor:pointer;font-family:var(--fb)}
.sidebar{position:fixed;top:0;left:0;bottom:0;width:var(--sidebar-w);background:var(--navy-deep);display:flex;flex-direction:column;z-index:200;transition:transform var(--ease);overflow:hidden}
.sb-brand{padding:0 20px;height:var(--header-h);display:flex;align-items:center;gap:10px;border-bottom:1px solid rgba(255,255,255,.06);flex-shrink:0}
.sb-brand-fb{width:36px;height:36px;border-radius:50%;background:var(--navy);border:1.5px solid var(--gold);display:grid;place-items:center;font-family:var(--fh);font-size:.85rem;font-weight:600;color:var(--gold);flex-shrink:0}
.sb-brand-text strong{display:block;font-family:var(--fh);font-size:.9rem;font-weight:600;color:var(--white);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-brand-text small{font-size:.6rem;font-weight:300;letter-spacing:.09em;color:rgba(255,255,255,.4);text-transform:uppercase}
.sb-nav{flex:1;overflow-y:auto;padding:16px 0}
.sb-nav::-webkit-scrollbar{width:4px}.sb-nav::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:2px}
.sb-section{margin-bottom:6px}
.sb-section-label{font-size:.6rem;font-weight:500;letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,.25);padding:12px 20px 6px}
.sb-link{display:flex;align-items:center;gap:10px;padding:9px 20px;font-size:.82rem;font-weight:400;color:rgba(255,255,255,.55);border-left:2px solid transparent;transition:color var(--ease),background var(--ease),border-color var(--ease);position:relative}
.sb-link:hover{color:var(--white);background:rgba(255,255,255,.05)}
.sb-link.active{color:var(--gold-lt);background:rgba(201,168,76,.1);border-left-color:var(--gold)}
.sb-link .icon{width:18px;height:18px;flex-shrink:0;display:grid;place-items:center;font-size:.9rem}
.sb-link .badge{margin-left:auto;background:var(--wine);color:var(--white);font-size:.6rem;font-weight:500;padding:2px 7px;border-radius:20px;min-width:20px;text-align:center}
.sb-footer{padding:16px 20px;border-top:1px solid rgba(255,255,255,.06);flex-shrink:0}
.sb-user{display:flex;align-items:center;gap:10px;padding:10px 12px;background:rgba(255,255,255,.05);border-radius:10px;margin-bottom:10px}
.sb-user-avatar{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--navy-mid),var(--navy));border:1.5px solid var(--gold);display:grid;place-items:center;font-family:var(--fh);font-size:.85rem;color:var(--gold-lt);flex-shrink:0}
.sb-user-info strong{display:block;font-size:.78rem;font-weight:500;color:var(--white);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-user-info small{font-size:.66rem;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.07em}
.sb-logout{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:9px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);border-radius:8px;color:rgba(255,255,255,.45);font-size:.78rem;font-weight:400;letter-spacing:.04em;transition:var(--ease)}
.sb-logout:hover{background:rgba(122,42,58,.2);border-color:rgba(122,42,58,.3);color:#f08080}
.topbar{position:fixed;top:0;left:var(--sidebar-w);right:0;height:var(--header-h);background:rgba(240,237,232,.95);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-bottom:1px solid rgba(26,21,16,.08);display:flex;align-items:center;padding:0 clamp(16px,3vw,36px);gap:16px;z-index:100;transition:left var(--ease)}
.topbar-page{font-family:var(--fh);font-size:1.3rem;font-weight:600;color:var(--ink);line-height:1}
.topbar-breadcrumb{font-size:.72rem;color:var(--ink-30);display:flex;align-items:center;gap:4px}
.topbar-breadcrumb span{color:var(--ink-60)}
.topbar-right{margin-left:auto;display:flex;align-items:center;gap:10px}
.icon-btn{width:36px;height:36px;border-radius:50%;background:var(--white);border:1px solid var(--ink-10);display:grid;place-items:center;font-size:.9rem;transition:var(--ease);position:relative}
.icon-btn:hover{border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-dim)}
.icon-btn .dot{position:absolute;top:6px;right:6px;width:8px;height:8px;border-radius:50%;background:var(--wine);border:2px solid #F0EDE8}
.sb-toggle{display:none;width:36px;height:36px;border-radius:8px;background:none;border:none;flex-direction:column;gap:5px;align-items:center;justify-content:center}
.sb-toggle span{display:block;width:20px;height:2px;background:var(--ink);border-radius:2px}
.main{margin-left:var(--sidebar-w);padding-top:var(--header-h);min-height:100vh;transition:margin-left var(--ease)}
.page-content{padding:clamp(20px,3vw,36px) clamp(16px,3vw,36px);max-width:1400px}
.sec-head{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.sec-tag{font-size:.68rem;letter-spacing:.13em;text-transform:uppercase;color:var(--gold);font-weight:500;margin-bottom:4px}.sec-tag::before{content:'— '}
.sec-title{font-family:var(--fh);font-size:clamp(1.5rem,3vw,2rem);font-weight:600;color:var(--ink);line-height:1.1}
.sec-sub{font-size:.8rem;color:var(--ink-60);font-weight:300;margin-top:3px}
.btn-sm{padding:8px 18px;border-radius:30px;font-size:.78rem;font-weight:500;letter-spacing:.04em;transition:var(--ease);cursor:pointer}
.btn-navy{background:var(--navy);color:var(--white);border:none}.btn-navy:hover{background:var(--gold);color:var(--ink);transform:translateY(-1px);box-shadow:0 4px 14px var(--gold-dim)}
.btn-outline{background:none;color:var(--navy);border:1px solid var(--ink-10)}.btn-outline:hover{border-color:var(--navy);background:var(--navy);color:var(--white)}
.btn-gold{background:var(--gold);color:var(--ink);border:none}.btn-gold:hover{background:var(--gold-lt);transform:translateY(-1px)}
.btn-wine{background:var(--wine);color:var(--white);border:none}.btn-wine:hover{background:#9a3a4a;transform:translateY(-1px)}
.btn-green{background:var(--green);color:var(--white);border:none}.btn-green:hover{background:#1f5c3d;transform:translateY(-1px)}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:28px}
.stat-card{background:var(--white);border-radius:var(--r);padding:22px 24px;border:1px solid rgba(255,255,255,.8);box-shadow:var(--sh);position:relative;overflow:hidden;transition:transform var(--ease),box-shadow var(--ease);animation:slideUp .4s ease both}
.stat-card:nth-child(1){animation-delay:.05s}.stat-card:nth-child(2){animation-delay:.1s}.stat-card:nth-child(3){animation-delay:.15s}.stat-card:nth-child(4){animation-delay:.2s}
@keyframes slideUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.stat-card:hover{transform:translateY(-3px);box-shadow:var(--sh-md)}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.stat-navy::before{background:var(--navy)}.stat-gold::before{background:var(--gold)}.stat-green::before{background:var(--green)}.stat-wine::before{background:var(--wine)}.stat-amber::before{background:var(--amber)}.stat-blue::before{background:var(--blue)}
.stat-icon{width:42px;height:42px;border-radius:10px;display:grid;place-items:center;font-size:1.1rem;margin-bottom:14px}
.stat-navy .stat-icon{background:rgba(27,42,74,.1)}.stat-gold .stat-icon{background:var(--gold-dim)}.stat-green .stat-icon{background:var(--green-dim)}.stat-wine .stat-icon{background:var(--wine-dim)}.stat-amber .stat-icon{background:var(--amber-dim)}.stat-blue .stat-icon{background:var(--blue-dim)}
.stat-label{font-size:.71rem;font-weight:500;letter-spacing:.07em;text-transform:uppercase;color:var(--ink-60);margin-bottom:6px}
.stat-value{font-family:var(--fh);font-size:2rem;font-weight:600;color:var(--ink);line-height:1}
.stat-delta{font-size:.72rem;color:var(--green);font-weight:400;margin-top:6px}.stat-delta.down{color:var(--wine)}
.card{background:var(--white);border-radius:var(--r);border:1px solid rgba(255,255,255,.8);box-shadow:var(--sh);overflow:hidden;margin-bottom:18px}
.card-head{padding:18px 22px 16px;border-bottom:1px solid var(--ink-10);display:flex;align-items:center;justify-content:space-between;gap:12px}
.card-head h3{font-family:var(--fh);font-size:1.05rem;font-weight:600;color:var(--ink)}
.card-head .card-tag{font-size:.68rem;font-weight:500;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-30)}
.card-body{padding:20px 22px}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px}
.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-bottom:18px}
.grid-2-1{display:grid;grid-template-columns:2fr 1fr;gap:18px;margin-bottom:18px}
.col-span-2{grid-column:1/-1}
.tbl-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead th{font-size:.7rem;font-weight:500;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-60);padding:10px 14px;text-align:left;border-bottom:1px solid var(--ink-10);white-space:nowrap}
tbody td{padding:12px 14px;font-size:.82rem;color:var(--ink);border-bottom:1px solid var(--ink-10);vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr{transition:background var(--ease)}tbody tr:hover{background:rgba(201,168,76,.04)}
.pill{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:.68rem;font-weight:500;letter-spacing:.04em;white-space:nowrap}
.pill::before{content:'';width:5px;height:5px;border-radius:50%;flex-shrink:0}
.pill-green{background:var(--green-dim);color:var(--green)}.pill-green::before{background:var(--green)}
.pill-wine{background:var(--wine-dim);color:var(--wine)}.pill-wine::before{background:var(--wine)}
.pill-amber{background:var(--amber-dim);color:var(--amber)}.pill-amber::before{background:var(--amber)}
.pill-navy{background:rgba(27,42,74,.1);color:var(--navy)}.pill-navy::before{background:var(--navy)}
.pill-gold{background:var(--gold-dim);color:#8B6914}.pill-gold::before{background:var(--gold)}
.act-btn{display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:6px;font-size:.72rem;font-weight:500;border:1px solid transparent;transition:var(--ease);margin-right:4px;cursor:pointer}
.act-navy{background:rgba(27,42,74,.08);color:var(--navy);border-color:rgba(27,42,74,.15)}.act-navy:hover{background:var(--navy);color:var(--white)}
.act-gold{background:var(--gold-dim);color:#8B6914;border-color:rgba(201,168,76,.3)}.act-gold:hover{background:var(--gold);color:var(--ink)}
.act-wine{background:var(--wine-dim);color:var(--wine);border-color:rgba(122,42,58,.2)}.act-wine:hover{background:var(--wine);color:var(--white)}
.act-green{background:var(--green-dim);color:var(--green);border-color:rgba(42,122,82,.2)}.act-green:hover{background:var(--green);color:var(--white)}
.notice{display:flex;align-items:flex-start;gap:12px;padding:14px 18px;border-radius:10px;margin-bottom:16px;font-size:.82rem;line-height:1.6}
.notice-amber{background:var(--amber-dim);border-left:3px solid var(--amber);color:#7A4A10}
.notice-green{background:var(--green-dim);border-left:3px solid var(--green);color:#1A4A30}
.notice-wine{background:var(--wine-dim);border-left:3px solid var(--wine);color:#4A1020}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-group{margin-bottom:14px}
.form-group label{display:block;font-size:.72rem;font-weight:500;letter-spacing:.07em;text-transform:uppercase;color:var(--ink-60);margin-bottom:6px}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:9px 14px;border:1.5px solid var(--ink-10);border-radius:8px;font-family:var(--fb);font-size:.83rem;color:var(--ink);background:#FAFAF8;outline:none;transition:border-color var(--ease),box-shadow var(--ease)}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-dim);background:var(--white)}
.form-group textarea{resize:vertical;min-height:80px}
.form-full{grid-column:1/-1}
.modal-wrap{display:none;position:fixed;inset:0;z-index:300;background:rgba(13,24,40,.5);backdrop-filter:blur(4px);place-items:center}
.modal-wrap.open{display:grid}
.modal{background:var(--white);border-radius:16px;padding:32px;max-width:560px;width:90%;box-shadow:0 20px 60px rgba(13,24,40,.2);animation:modalIn .25s ease;max-height:90vh;overflow-y:auto}
@keyframes modalIn{from{opacity:0;transform:translateY(-12px) scale(.97)}to{opacity:1;transform:none}}
.modal h2{font-family:var(--fh);font-size:1.4rem;font-weight:600;margin-bottom:6px}
.modal p{font-size:.83rem;color:var(--ink-60);margin-bottom:20px}
.modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:20px}
.toast{position:fixed;bottom:28px;right:28px;padding:12px 20px;border-radius:10px;font-size:.82rem;font-weight:500;box-shadow:0 8px 24px rgba(0,0,0,.15);z-index:600;transform:translateY(80px);opacity:0;transition:.3s cubic-bezier(.4,0,.2,1);max-width:360px}
.toast.show{transform:none;opacity:1}
.toast-success{background:var(--green);color:white}
.toast-error{background:var(--wine);color:white}
.toast-info{background:var(--navy);color:white}
.loading-overlay{position:fixed;inset:0;background:rgba(13,24,40,.35);z-index:500;display:none;place-items:center;backdrop-filter:blur(2px)}
.loading-overlay.show{display:grid}
.spinner{width:44px;height:44px;border:3px solid rgba(255,255,255,.2);border-top-color:var(--gold);border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.overlay{display:none;position:fixed;inset:0;background:rgba(13,24,40,.4);z-index:150;backdrop-filter:blur(2px)}.overlay.on{display:block}
.empty-state{text-align:center;padding:40px 20px;color:var(--ink-30)}
.empty-state .empty-icon{font-size:2.5rem;margin-bottom:12px}
.empty-state p{font-size:.85rem;margin-bottom:16px}
@media(max-width:1024px){:root{--sidebar-w:240px}.grid-3{grid-template-columns:1fr 1fr}}
@media(max-width:768px){:root{--sidebar-w:260px}.sidebar{transform:translateX(calc(-1 * var(--sidebar-w)))}.sidebar.open{transform:translateX(0)}.topbar{left:0}.main{margin-left:0}.sb-toggle{display:flex}.grid-2,.grid-3,.grid-2-1{grid-template-columns:1fr}.stats-grid{grid-template-columns:1fr 1fr}.form-grid{grid-template-columns:1fr}}
@media(max-width:480px){.stats-grid{grid-template-columns:1fr}}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.page-content{animation:fadeIn .35s ease}
</style>
<?php require APP_ROOT . '/includes/branding_head.php'; ?>
<link rel="stylesheet" href="<?= h(app_url('assets/css/enhancements.css')) ?>">
<script defer src="<?= h(app_url('assets/js/delivery-feedback.js')) ?>"></script>
<?php require_once APP_ROOT.'/includes/password_visibility.php'; ?>
</head>
<body>
<div class="overlay" id="overlay"></div>
<aside class="sidebar" id="sidebar">
  <div class="sb-brand">
    <img src="<?= h(app_url($branding['site_logo'])) ?>" alt="" width="36" height="36" style="object-fit:contain">
    <div class="sb-brand-text">
      <strong><?= h($branding['site_name']) ?></strong>
      <small>Parishioner Portal</small>
    </div>
  </div>
  <nav class="sb-nav">
    <?php foreach($nav as $group): ?>
    <div class="sb-section">
      <div class="sb-section-label"><?= h(t($group['section'])) ?></div>
      <?php foreach($group['items'] as $item): ?>
      <a href="<?php echo $item['href']; ?>" class="sb-link <?php echo $page_id === $item['id'] ? 'active' : ''; ?>">
        <span class="icon"><?php echo $item['icon']; ?></span>
        <?= h(t($item['label'])) ?>
        <?php if(!empty($item['badge']) && $item['badge'] > 0): ?>
          <span class="badge"><?php echo $item['badge']; ?></span>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </nav>
  <div class="sb-footer">
    <div class="sb-user">
      <div class="sb-user-avatar"><?= profile_avatar($user) ?></div>
      <div class="sb-user-info">
        <strong><?php echo htmlspecialchars($user['name']); ?></strong>
        <small>Parishioner</small>
      </div>
    </div>
    <form method="post" action="<?= h(app_url('public/logout.php')) ?>"><?= csrf_field() ?><button class="sb-logout"><?= ui_icon('logout') ?> &nbsp;<?= h(t('Sign Out')) ?></button></form>
  </div>
</aside>
<div class="topbar">
  <button class="sb-toggle" id="sbToggle" aria-label="Toggle navigation"><?= ui_icon('menu') ?></button>
  <div style="display:flex;align-items:center;gap:12px">
    <div>
      <div class="topbar-page"><?php echo htmlspecialchars($page_title); ?></div>
      <div class="topbar-breadcrumb">Portal <span>&rsaquo;</span> <span><?php echo htmlspecialchars($page_sub ?? $page_title); ?></span></div>
    </div>
  </div>
  <div class="topbar-right"><a href="settings.php" aria-label="<?= h(t('Settings')) ?>" style="display:block;width:34px;height:34px"><?= profile_avatar($user) ?></a>
    <a href="notifications.php" class="icon-btn" title="Notifications"><?= ui_icon('bell') ?><?php if($notif_count > 0): ?><span class="dot"></span><?php endif; ?></a>
    <a href="messages.php" class="icon-btn" title="Messages"><?= ui_icon('mail') ?><?php if($msg_count > 0): ?><span class="dot"></span><?php endif; ?></a>
  </div>
</div>
<main class="main">
<div class="page-content">
<?= navigation_controls() ?>
<?php
