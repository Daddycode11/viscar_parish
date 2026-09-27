<?php
require_once __DIR__.'/../../includes/icons.php';
/**
 * Shared Admin Layout — Apostolic Vicariate of San Jose
 * Usage: include at the top of each admin page AFTER setting $page_id and $page_title
 *
 * Variables expected before include:
 *   $page_id    — matches sidebar data-page (e.g. 'parishes', 'users')
 *   $page_title — shown in topbar (e.g. 'Parish Management')
 *   $page_sub   — breadcrumb sub label (optional)
 */

// require_once __DIR__ . '/auth.php';
// require_once __DIR__ . '/db.php';
// checkRole('admin');
// $user = currentUser();

// Demo user for standalone preview
if (!isset($user)) {
    $user = ['name' => 'Administrator', 'email' => 'admin@vicariate.ph', 'id' => 1];
}
if (!isset($page_id))    $page_id    = 'overview';
if (!isset($page_title)) $page_title = 'Dashboard';
if (!isset($page_sub))   $page_sub   = 'Overview';

// Pending count for badge — replace with real query
$pending_badge = isset($conn)
    ? ($conn->query("SELECT COUNT(*) as t FROM applications WHERE status='pending'")->fetch_assoc()['t'] ?? 0)
    : 3;

$nav = [
    'overview' => [
        'section' => 'Overview',
        'items' => [
            ['id' => 'overview',      'icon' => ui_icon('dashboard'), 'label' => 'Dashboard',          'href' => 'dashboard.php'],
            ['id' => 'analytics',     'icon' => ui_icon('chart'), 'label' => 'Analytics',           'href' => 'analytics.php'],
        ]
    ],
    'management' => [
        'section' => 'Management',
        'items' => [
            ['id' => 'parishes',      'icon' => ui_icon('church'), 'label' => 'Parish Management',   'href' => 'parishes.php',      'badge' => null, 'badge_class' => 'gold'],
            ['id' => 'users',         'icon' => ui_icon('user'), 'label' => 'User Management',     'href' => 'users.php',         'badge' => null],
            ['id'=>'main_database','icon'=>ui_icon('file'),'label'=>'MAIN DATABASE','href'=>'main_database.php'],
            ['id' => 'applications',  'icon' => ui_icon('clipboard'), 'label' => 'Applications',        'href' => 'applications.php',  'badge' => $pending_badge, 'badge_class' => ''],
        ]
    ],
    'finance' => [
        'section' => 'Finance',
        'items' => [
            ['id' => 'finance',       'icon' => ui_icon('wallet'),  'label' => 'Financial Oversight', 'href' => 'finance.php'],
            ['id' => 'reports',       'icon' => ui_icon('chart'), 'label' => 'Reports & Export',    'href' => 'reports.php'],
        ]
    ],
    'communication' => [
        'section' => 'Communication',
        'items' => [
            ['id' => 'announcements', 'icon' => ui_icon('announcement'), 'label' => 'Announcements',       'href' => 'announcements.php'],
            ['id' => 'notifications', 'icon' => ui_icon('bell'), 'label' => 'Notifications',       'href' => 'notifications.php', 'badge' => 3, 'badge_class' => 'green'],
        ]
    ],
    'system' => [
        'section' => 'System',
        'items' => [
            ['id' => 'backup',        'icon' => ui_icon('save'), 'label' => 'Backup & Restore',    'href' => 'backup.php'],
            ['id' => 'settings',      'icon' => ui_icon('settings'),  'label' => 'Settings',            'href' => 'settings.php'],
        ]
    ],
];

$nav['revisions']=['section'=>'Services','items'=>[ ['id'=>'security','icon'=>ui_icon('lock'),'label'=>'Login Security','href'=>'security.php'] ]];
?>
<!DOCTYPE html>
<html lang="<?= h($user['language'] ?? 'en') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($page_title); ?> — Apostolic Vicariate Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
:root {
  --gold:       #C9A84C;
  --gold-lt:    #E8C97A;
  --gold-dim:   rgba(201,168,76,.12);
  --navy:       #1B2A4A;
  --navy-deep:  #0D1828;
  --navy-mid:   #243558;
  --cream:      #FAF7F2;
  --ink:        #1A1510;
  --ink-60:     rgba(26,21,16,.6);
  --ink-30:     rgba(26,21,16,.3);
  --ink-10:     rgba(26,21,16,.07);
  --white:      #FFFFFF;
  --green:      #2A7A52;
  --green-dim:  rgba(42,122,82,.12);
  --wine:       #7A2A3A;
  --wine-dim:   rgba(122,42,58,.12);
  --amber:      #C97A20;
  --amber-dim:  rgba(201,122,32,.12);
  --blue:       #2A52A4;
  --blue-dim:   rgba(42,82,164,.12);
  --sidebar-w:  260px;
  --header-h:   64px;
  --fh: "Cormorant Garamond", Georgia, serif;
  --fb: "DM Sans", sans-serif;
  --ease: .28s cubic-bezier(.4,0,.2,1);
  --r: 12px;
  --sh: 0 2px 16px rgba(26,21,16,.07);
  --sh-md: 0 6px 28px rgba(26,21,16,.1);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:15px;scroll-behavior:smooth}
body{font-family:var(--fb);background:#F0EDE8;color:var(--ink);min-height:100vh;overflow-x:hidden}
a{text-decoration:none;color:inherit}
img{display:block;max-width:100%}
button{cursor:pointer;font-family:var(--fb)}

/* SIDEBAR */
.sidebar{position:fixed;top:0;left:0;bottom:0;width:var(--sidebar-w);background:var(--navy-deep);display:flex;flex-direction:column;z-index:200;transition:transform var(--ease);overflow:hidden}
.sb-brand{padding:0 20px;height:var(--header-h);display:flex;align-items:center;gap:10px;border-bottom:1px solid rgba(255,255,255,.06);flex-shrink:0}
.sb-brand-fb{width:36px;height:36px;border-radius:50%;background:var(--navy);border:1.5px solid var(--gold);display:grid;place-items:center;font-family:var(--fh);font-size:.85rem;font-weight:600;color:var(--gold);flex-shrink:0}
.sb-brand-text strong{display:block;font-family:var(--fh);font-size:.9rem;font-weight:600;color:var(--white);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-brand-text small{font-size:.6rem;font-weight:300;letter-spacing:.09em;color:rgba(255,255,255,.4);text-transform:uppercase}
.sb-nav{flex:1;overflow-y:auto;padding:16px 0}
.sb-nav::-webkit-scrollbar{width:4px}
.sb-nav::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:2px}
.sb-section{margin-bottom:6px}
.sb-section-label{font-size:.6rem;font-weight:500;letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,.25);padding:12px 20px 6px}
.sb-link{display:flex;align-items:center;gap:10px;padding:9px 20px;font-size:.82rem;font-weight:400;color:rgba(255,255,255,.55);border-left:2px solid transparent;transition:color var(--ease),background var(--ease),border-color var(--ease);position:relative}
.sb-link:hover{color:var(--white);background:rgba(255,255,255,.05)}
.sb-link.active{color:var(--gold-lt);background:rgba(201,168,76,.1);border-left-color:var(--gold)}
.sb-link .icon{width:18px;height:18px;flex-shrink:0;display:grid;place-items:center;font-size:.9rem}
.sb-link .badge{margin-left:auto;background:var(--wine);color:var(--white);font-size:.6rem;font-weight:500;padding:2px 7px;border-radius:20px;min-width:20px;text-align:center}
.sb-link .badge.green{background:var(--green)}
.sb-link .badge.gold{background:var(--gold);color:var(--ink)}
.sb-footer{padding:16px 20px;border-top:1px solid rgba(255,255,255,.06);flex-shrink:0}
.sb-user{display:flex;align-items:center;gap:10px;padding:10px 12px;background:rgba(255,255,255,.05);border-radius:10px;margin-bottom:10px}
.sb-user-avatar{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--navy-mid),var(--navy));border:1.5px solid var(--gold);display:grid;place-items:center;font-family:var(--fh);font-size:.85rem;color:var(--gold-lt);flex-shrink:0}
.sb-user-info strong{display:block;font-size:.78rem;font-weight:500;color:var(--white);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-user-info small{font-size:.66rem;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.07em}
.sb-logout{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:9px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);border-radius:8px;color:rgba(255,255,255,.45);font-size:.78rem;font-weight:400;letter-spacing:.04em;transition:var(--ease)}
.sb-logout:hover{background:rgba(122,42,58,.2);border-color:rgba(122,42,58,.3);color:#f08080}

/* TOPBAR */
.topbar{position:fixed;top:0;left:var(--sidebar-w);right:0;height:var(--header-h);background:rgba(240,237,232,.95);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-bottom:1px solid rgba(26,21,16,.08);display:flex;align-items:center;padding:0 clamp(16px,3vw,36px);gap:16px;z-index:100;transition:left var(--ease)}
.topbar-page{font-family:var(--fh);font-size:1.3rem;font-weight:600;color:var(--ink);line-height:1}
.topbar-breadcrumb{font-size:.72rem;color:var(--ink-30);display:flex;align-items:center;gap:4px}
.topbar-breadcrumb span{color:var(--ink-60)}
.topbar-right{margin-left:auto;display:flex;align-items:center;gap:10px}
.topbar-search{display:flex;align-items:center;gap:8px;background:var(--white);border:1px solid var(--ink-10);border-radius:30px;padding:7px 14px;font-size:.8rem;color:var(--ink-30);transition:border-color var(--ease),box-shadow var(--ease)}
.topbar-search:focus-within{border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-dim)}
.topbar-search input{border:none;outline:none;background:none;font-family:var(--fb);font-size:.8rem;color:var(--ink);width:160px}
.topbar-search input::placeholder{color:var(--ink-30)}
.icon-btn{width:36px;height:36px;border-radius:50%;background:var(--white);border:1px solid var(--ink-10);display:grid;place-items:center;font-size:.9rem;transition:var(--ease);position:relative}
.icon-btn:hover{border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-dim)}
.icon-btn .dot{position:absolute;top:6px;right:6px;width:8px;height:8px;border-radius:50%;background:var(--wine);border:2px solid #F0EDE8}
.sb-toggle{display:none;width:36px;height:36px;border-radius:8px;background:none;border:none;flex-direction:column;gap:5px;align-items:center;justify-content:center}
.sb-toggle span{display:block;width:20px;height:2px;background:var(--ink);border-radius:2px}

/* MAIN */
.main{margin-left:var(--sidebar-w);padding-top:var(--header-h);min-height:100vh;transition:margin-left var(--ease)}
.page-content{padding:clamp(20px,3vw,36px) clamp(16px,3vw,36px);max-width:1400px}

/* SECTION HEADS */
.sec-head{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.sec-tag{font-size:.68rem;letter-spacing:.13em;text-transform:uppercase;color:var(--gold);font-weight:500;margin-bottom:4px}
.sec-tag::before{content:'— '}
.sec-title{font-family:var(--fh);font-size:clamp(1.5rem,3vw,2rem);font-weight:600;color:var(--ink);line-height:1.1}
.sec-sub{font-size:.8rem;color:var(--ink-60);font-weight:300;margin-top:3px}

/* BUTTONS */
.btn-sm{padding:8px 18px;border-radius:30px;font-size:.78rem;font-weight:500;letter-spacing:.04em;transition:var(--ease);cursor:pointer}
.btn-navy{background:var(--navy);color:var(--white);border:none}
.btn-navy:hover{background:var(--gold);color:var(--ink);transform:translateY(-1px);box-shadow:0 4px 14px var(--gold-dim)}
.btn-outline{background:none;color:var(--navy);border:1px solid var(--ink-10)}
.btn-outline:hover{border-color:var(--navy);background:var(--navy);color:var(--white)}
.btn-gold{background:var(--gold);color:var(--ink);border:none}
.btn-gold:hover{background:var(--gold-lt);transform:translateY(-1px)}
.btn-wine{background:var(--wine);color:var(--white);border:none}
.btn-wine:hover{background:#9a3a4a;transform:translateY(-1px)}
.btn-green{background:var(--green);color:var(--white);border:none}
.btn-green:hover{background:#1f5c3d;transform:translateY(-1px)}

/* STAT CARDS */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:28px}
.stat-card{background:var(--white);border-radius:var(--r);padding:22px 24px;border:1px solid rgba(255,255,255,.8);box-shadow:var(--sh);position:relative;overflow:hidden;transition:transform var(--ease),box-shadow var(--ease);animation:slideUp .4s ease both}
.stat-card:nth-child(1){animation-delay:.05s}.stat-card:nth-child(2){animation-delay:.1s}.stat-card:nth-child(3){animation-delay:.15s}.stat-card:nth-child(4){animation-delay:.2s}.stat-card:nth-child(5){animation-delay:.25s}.stat-card:nth-child(6){animation-delay:.3s}
@keyframes slideUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.stat-card:hover{transform:translateY(-3px);box-shadow:var(--sh-md)}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.stat-navy::before{background:var(--navy)}.stat-gold::before{background:var(--gold)}.stat-green::before{background:var(--green)}.stat-wine::before{background:var(--wine)}.stat-amber::before{background:var(--amber)}.stat-blue::before{background:var(--blue)}
.stat-icon{width:42px;height:42px;border-radius:10px;display:grid;place-items:center;font-size:1.1rem;margin-bottom:14px}
.stat-navy .stat-icon{background:rgba(27,42,74,.1)}.stat-gold .stat-icon{background:var(--gold-dim)}.stat-green .stat-icon{background:var(--green-dim)}.stat-wine .stat-icon{background:var(--wine-dim)}.stat-amber .stat-icon{background:var(--amber-dim)}.stat-blue .stat-icon{background:var(--blue-dim)}
.stat-label{font-size:.71rem;font-weight:500;letter-spacing:.07em;text-transform:uppercase;color:var(--ink-60);margin-bottom:6px}
.stat-value{font-family:var(--fh);font-size:2rem;font-weight:600;color:var(--ink);line-height:1}
.stat-delta{font-size:.72rem;color:var(--green);font-weight:400;margin-top:6px}
.stat-delta.down{color:var(--wine)}

/* CARDS */
.card{background:var(--white);border-radius:var(--r);border:1px solid rgba(255,255,255,.8);box-shadow:var(--sh);overflow:hidden;margin-bottom:18px}
.card-head{padding:18px 22px 16px;border-bottom:1px solid var(--ink-10);display:flex;align-items:center;justify-content:space-between;gap:12px}
.card-head h3{font-family:var(--fh);font-size:1.05rem;font-weight:600;color:var(--ink)}
.card-head .card-tag{font-size:.68rem;font-weight:500;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-30)}
.card-body{padding:20px 22px}

/* GRID LAYOUTS */
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px}
.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-bottom:18px}
.grid-1-2{display:grid;grid-template-columns:1fr 2fr;gap:18px;margin-bottom:18px}
.grid-2-1{display:grid;grid-template-columns:2fr 1fr;gap:18px;margin-bottom:18px}
.col-span-2{grid-column:1/-1}

/* TABLE */
.tbl-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead th{font-size:.7rem;font-weight:500;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-60);padding:10px 14px;text-align:left;border-bottom:1px solid var(--ink-10);white-space:nowrap}
tbody td{padding:12px 14px;font-size:.82rem;color:var(--ink);border-bottom:1px solid var(--ink-10);vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr{transition:background var(--ease)}
tbody tr:hover{background:rgba(201,168,76,.04)}

/* PILLS */
.pill{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:.68rem;font-weight:500;letter-spacing:.04em;white-space:nowrap}
.pill::before{content:'';width:5px;height:5px;border-radius:50%;flex-shrink:0}
.pill-green{background:var(--green-dim);color:var(--green)}.pill-green::before{background:var(--green)}
.pill-wine{background:var(--wine-dim);color:var(--wine)}.pill-wine::before{background:var(--wine)}
.pill-amber{background:var(--amber-dim);color:var(--amber)}.pill-amber::before{background:var(--amber)}
.pill-navy{background:rgba(27,42,74,.1);color:var(--navy)}.pill-navy::before{background:var(--navy)}
.pill-gold{background:var(--gold-dim);color:#8B6914}.pill-gold::before{background:var(--gold)}

/* ACTION BUTTONS */
.act-btn{display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:6px;font-size:.72rem;font-weight:500;border:1px solid transparent;transition:var(--ease);margin-right:4px;cursor:pointer}
.act-navy{background:rgba(27,42,74,.08);color:var(--navy);border-color:rgba(27,42,74,.15)}.act-navy:hover{background:var(--navy);color:var(--white)}
.act-gold{background:var(--gold-dim);color:#8B6914;border-color:rgba(201,168,76,.3)}.act-gold:hover{background:var(--gold);color:var(--ink)}
.act-wine{background:var(--wine-dim);color:var(--wine);border-color:rgba(122,42,58,.2)}.act-wine:hover{background:var(--wine);color:var(--white)}
.act-green{background:var(--green-dim);color:var(--green);border-color:rgba(42,122,82,.2)}.act-green:hover{background:var(--green);color:var(--white)}

/* NOTICES */
.notice{display:flex;align-items:flex-start;gap:12px;padding:14px 18px;border-radius:10px;margin-bottom:16px;font-size:.82rem;line-height:1.6}
.notice-amber{background:var(--amber-dim);border-left:3px solid var(--amber);color:#7A4A10}
.notice-navy{background:rgba(27,42,74,.08);border-left:3px solid var(--navy);color:var(--navy)}
.notice-green{background:var(--green-dim);border-left:3px solid var(--green);color:#1A4A30}
.notice-wine{background:var(--wine-dim);border-left:3px solid var(--wine);color:#4A1020}
.notice strong{font-weight:500}

/* FORM ELEMENTS */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-group{margin-bottom:14px}
.form-group label{display:block;font-size:.72rem;font-weight:500;letter-spacing:.07em;text-transform:uppercase;color:var(--ink-60);margin-bottom:6px}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:9px 14px;border:1.5px solid var(--ink-10);border-radius:8px;font-family:var(--fb);font-size:.83rem;color:var(--ink);background:#FAFAF8;outline:none;transition:border-color var(--ease),box-shadow var(--ease)}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-dim);background:var(--white)}
.form-group textarea{resize:vertical;min-height:80px}
.form-full{grid-column:1/-1}

/* BAR LIST */
.bar-list{display:flex;flex-direction:column;gap:12px}
.bar-top{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:5px}
.bar-label{font-size:.78rem;font-weight:400;color:var(--ink)}
.bar-val{font-size:.78rem;font-weight:500;color:var(--ink-60)}
.bar-track{height:6px;background:var(--ink-10);border-radius:3px;overflow:hidden}
.bar-fill{height:100%;border-radius:3px;transition:width .8s cubic-bezier(.4,0,.2,1)}

/* ACTIVITY FEED */
.activity{display:flex;flex-direction:column;gap:0}
.act-item{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid var(--ink-10)}
.act-item:last-child{border-bottom:none}
.act-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-top:5px}
.act-dot-navy{background:var(--navy)}.act-dot-gold{background:var(--gold)}.act-dot-green{background:var(--green)}.act-dot-wine{background:var(--wine)}
.act-text{font-size:.8rem;color:var(--ink);line-height:1.5;flex:1}
.act-text strong{font-weight:500}
.act-time{font-size:.7rem;color:var(--ink-30);white-space:nowrap}

/* OVERLAY */
.overlay{display:none;position:fixed;inset:0;background:rgba(13,24,40,.4);z-index:150;backdrop-filter:blur(2px)}
.overlay.on{display:block}

/* MODAL */
.modal-wrap{display:none;position:fixed;inset:0;z-index:300;background:rgba(13,24,40,.5);backdrop-filter:blur(4px);place-items:center}
.modal-wrap.open{display:grid}
.modal{background:var(--white);border-radius:16px;padding:32px;max-width:520px;width:90%;box-shadow:0 20px 60px rgba(13,24,40,.2);animation:modalIn .25s ease}
@keyframes modalIn{from{opacity:0;transform:translateY(-12px) scale(.97)}to{opacity:1;transform:none}}
.modal h2{font-family:var(--fh);font-size:1.4rem;font-weight:600;margin-bottom:6px}
.modal p{font-size:.83rem;color:var(--ink-60);margin-bottom:20px}
.modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:20px}

/* RESPONSIVE */
@media(max-width:1024px){:root{--sidebar-w:240px}.grid-3{grid-template-columns:1fr 1fr}.grid-1-2,.grid-2-1{grid-template-columns:1fr}}
@media(max-width:768px){:root{--sidebar-w:260px}.sidebar{transform:translateX(calc(-1 * var(--sidebar-w)))}.sidebar.open{transform:translateX(0)}.topbar{left:0}.main{margin-left:0}.sb-toggle{display:flex}.grid-2,.grid-3,.grid-1-2,.grid-2-1{grid-template-columns:1fr}.stats-grid{grid-template-columns:1fr 1fr}.topbar-search{display:none}.form-grid{grid-template-columns:1fr}}
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

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="sb-brand">
    <img src="<?= h(app_url($branding['site_logo'])) ?>" alt="" width="36" height="36" style="object-fit:contain">
    <div class="sb-brand-text">
      <strong><?= h($branding['site_name']) ?></strong>
      <small>Admin Panel</small>
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
          <span class="badge <?php echo $item['badge_class'] ?? ''; ?>"><?php echo $item['badge']; ?></span>
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
        <small>Administrator</small>
      </div>
    </div>
    <form method="post" action="<?= h(app_url('public/logout.php')) ?>"><?= csrf_field() ?><button class="sb-logout"><?= ui_icon('logout') ?> &nbsp;<?= h(t('Sign Out')) ?></button></form>
  </div>
</aside>

<!-- TOPBAR -->
<div class="topbar">
  <button class="sb-toggle" id="sbToggle" aria-label="Toggle navigation"><?= ui_icon('menu') ?></button>
  <div style="display:flex;align-items:center;gap:12px">
    <div>
      <div class="topbar-page"><?php echo htmlspecialchars($page_title); ?></div>
      <div class="topbar-breadcrumb">Admin <span>›</span> <span><?php echo htmlspecialchars($page_sub ?? $page_title); ?></span></div>
    </div>
  </div>
  <div class="topbar-right"><a href="settings.php" aria-label="<?= h(t('Settings')) ?>" style="display:block;width:34px;height:34px"><?= profile_avatar($user) ?></a>
    <div class="topbar-search">
      <span><?= ui_icon('search') ?></span>
      <input type="text" placeholder="Search…">
    </div>
    <a href="notifications.php" class="icon-btn" title="Notifications"><?= ui_icon('bell') ?><span class="dot"></span></a>
    <a href="announcements.php" class="icon-btn" title="Messages"><?= ui_icon('mail') ?></a>
  </div>
</div>

<!-- MAIN -->
<main class="main">
<div class="page-content">
<?= navigation_controls() ?>
<?php
// Page content starts here