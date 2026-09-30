<?php
require_once __DIR__.'/includes/auth.php';
if (!isset($_SESSION['splash_seen'])) {
    $_SESSION['splash_seen'] = true;
    header('Location: '.app_url('loading.php'));
    exit;
}

require_once 'includes/db.php';

require_once __DIR__ . '/includes/site_settings.php';
$ss = site_settings();

// =====================================
// Fetch latest 5 active announcements
// =====================================
$announcements = $conn->query("
    SELECT a.*,u.role publisher_role,p.name publisher_parish
    FROM announcements a LEFT JOIN users u ON u.id=a.sent_by LEFT JOIN parishes p ON p.id=u.parish_id
    WHERE a.status = 'active' AND target='All Parishes' AND NOT EXISTS(SELECT 1 FROM announcement_parishes ap WHERE ap.announcement_id=a.id) 
    ORDER BY a.created_at DESC 
    LIMIT 5
");

// =====================================
// Fetch next 5 upcoming events
// =====================================
$events = $conn->query("
    SELECT e.*,p.name publisher_parish
    FROM events e LEFT JOIN parishes p ON p.id=e.parish_id
    WHERE e.status = 'active' 
      AND event_date >= CURDATE() 
    ORDER BY event_date ASC 
    LIMIT 5
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Apostolic Vicariate of San Jose — Parish Service Platform</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
/* ─── TOKENS ─────────────────────────────────────────────── */
:root {
  --gold:     #C9A84C;
  --gold-lt:  #E8C97A;
  --cream:    #FAF7F2;
  --ink:      #1A1510;
  --ink-60:   rgba(26,21,16,.6);
  --ink-20:   rgba(26,21,16,.1);
  --navy:     #1B2A4A;
  --wine:     #6B2737;
  --white:    #FFFFFF;
  --radius:   14px;
  --shadow:   0 8px 40px rgba(26,21,16,.10);
  --shadow-lg:0 20px 60px rgba(26,21,16,.14);
  --font-head:"Cormorant Garamond", Georgia, serif;
  --font-body:"DM Sans", sans-serif;
  --transition: .35s cubic-bezier(.4,0,.2,1);
}

/* ─── RESET ─────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; font-size: 16px; }
body {
  font-family: var(--font-body);
  background: var(--cream);
  color: var(--ink);
  line-height: 1.7;
  overflow-x: hidden;
}
a { text-decoration: none; color: inherit; }
img { max-width: 100%; display: block; }

/* ─── NOISE OVERLAY ─────────────────────────────────────── */
body::before {
  content: '';
  position: fixed;
  inset: 0;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.03'/%3E%3C/svg%3E");
  pointer-events: none;
  z-index: 9999;
}

/* ─── HEADER ─────────────────────────────────────────────── */
header {
  position: sticky;
  top: 0;
  z-index: 500;
  background: rgba(250,247,242,.95);
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
  border-bottom: 1px solid var(--ink-20);
  padding: 0 5vw;
  height: 72px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.logo {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-shrink: 0;
}
.logo-img {
  width: 46px;
  height: 46px;
  border-radius: 50%;
  object-fit: contain;
  background: white;
  border: 1px solid var(--gold);
  flex-shrink: 0;
  box-shadow: 0 2px 12px rgba(201,168,76,.25);
}
/* fallback ring if img fails */
.logo-ring {
  width: 46px; height: 46px;
  border-radius: 50%;
  border: 1.5px solid var(--gold);
  display: none;
  place-items: center;
  font-family: var(--font-head);
  font-size: 1.1rem;
  color: var(--gold);
  font-weight: 600;
  flex-shrink: 0;
  background: var(--navy);
}
.logo-img.broken { display: none; }
.logo-img.broken + .logo-ring { display: grid; }
.logo-text {
  display: flex;
  flex-direction: column;
  line-height: 1.15;
}
.logo-text span:first-child {
  font-family: var(--font-head);
  font-size: 1.05rem;
  font-weight: 600;
  color: var(--ink);
  white-space: nowrap;
}
.logo-text span:last-child {
  font-size: .68rem;
  font-weight: 300;
  letter-spacing: .08em;
  color: var(--ink-60);
  text-transform: uppercase;
}
/* Desktop nav */
nav { display: flex; align-items: center; gap: 4px; }
nav a {
  font-size: .82rem;
  font-weight: 500;
  letter-spacing: .04em;
  padding: 7px 14px;
  border-radius: 30px;
  color: var(--ink-60);
  transition: var(--transition);
  white-space: nowrap;
}
nav a:hover { color: var(--ink); background: var(--ink-20); }
.nav-btn {
  background: var(--navy) !important;
  color: var(--white) !important;
  padding: 8px 20px !important;
}
.nav-btn:hover { background: var(--gold) !important; color: var(--ink) !important; }

/* Hamburger */
.hamburger {
  display: none;
  flex-direction: column;
  gap: 5px;
  cursor: pointer;
  padding: 6px;
  border: none;
  background: none;
  z-index: 600;
}
.hamburger span {
  display: block;
  width: 24px; height: 2px;
  background: var(--ink);
  border-radius: 2px;
  transition: var(--transition);
}
.hamburger.open span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
.hamburger.open span:nth-child(2) { opacity: 0; }
.hamburger.open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

/* Mobile nav drawer */
.mobile-nav {
  display: none;
  position: fixed;
  top: 72px; left: 0; right: 0;
  background: rgba(250,247,242,.98);
  backdrop-filter: blur(16px);
  border-bottom: 1px solid var(--ink-20);
  padding: 20px 5vw 28px;
  z-index: 490;
  flex-direction: column;
  gap: 4px;
  box-shadow: 0 8px 30px rgba(26,21,16,.1);
}
.mobile-nav.open { display: flex; }
.mobile-nav a {
  font-size: .95rem;
  font-weight: 500;
  color: var(--ink);
  padding: 12px 16px;
  border-radius: 10px;
  transition: background .2s;
}
.mobile-nav a:hover { background: var(--ink-20); }
.mobile-nav .nav-btn {
  background: var(--navy) !important;
  color: var(--white) !important;
  margin-top: 8px;
  text-align: center;
}

/* ─── HERO ───────────────────────────────────────────────── */
.hero {
  min-height: 80vh;
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}
/* Real photo background */
.hero-bg {
  position: absolute;
  inset: 0;
  background-image: url('assets/img/rightimage.png');
  background-size: cover;
  background-position: center top;
  background-repeat: no-repeat;
}
/* Multi-layer overlay: darkens photo and adds brand color tint */
.hero-bg::before {
  content: '';
  position: absolute;
  inset: 0;
  background:
    linear-gradient(to bottom,
      rgba(250,247,242,.96) 0%,
      rgba(250,247,242,.90) 50%,
      rgba(250,247,242,.98) 100%);
}
/* Subtle gold grid pattern on top */
.hero-bg::after {
  content: '';
  position: absolute;
  inset: 0;
  background-image:
    linear-gradient(rgba(201,168,76,.04) 1px, transparent 1px),
    linear-gradient(90deg, rgba(201,168,76,.04) 1px, transparent 1px);
  background-size: 60px 60px;
}
.hero-inner {
  position: relative;
  z-index: 2;
  text-align: center;
  padding: 100px 5vw 40px;
  max-width: 860px;
  width: 100%;
}
.hero-badge {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  border: 1px solid rgba(201,168,76,.5);
  border-radius: 30px;
  padding: 6px 18px;
  font-size: .75rem;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: var(--navy);
  margin-bottom: 1.5rem;
  animation: fadeUp .8s ease both;
  backdrop-filter: blur(8px);
  background: #fff;
}
.hero-badge::before {
  content: ''; display:inline-block; width:1em; height:1em; background:currentColor; -webkit-mask:url(data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22black%22%20stroke-width%3D%221.7%22%3E%3Cpath%20d%3D%22M12%205v14M5%2012h14%22%2F%3E%3C%2Fsvg%3E) center/contain no-repeat; mask:url(data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22black%22%20stroke-width%3D%221.7%22%3E%3Cpath%20d%3D%22M12%205v14M5%2012h14%22%2F%3E%3C%2Fsvg%3E) center/contain no-repeat;
  font-size: .6rem;
}
.hero h1 {
  font-family: var(--font-head);
  font-size: clamp(2.8rem, 7vw, 5.5rem);
  font-weight: 300;
  color: var(--navy);
  line-height: 1.1;
  margin-bottom: 1.5rem;
  animation: fadeUp .8s .15s ease both;
  text-shadow: none;
}
.hero h1 em {
  font-style: italic;
  color: var(--gold-lt);
}
.hero p {
  font-size: 1.05rem;
  font-weight: 300;
  color: #3c4658;
  max-width: 560px;
  margin: 0 auto 3rem;
  animation: fadeUp .8s .3s ease both;
  text-shadow: none;
}
.hero-actions {
  display: flex;
  gap: 16px;
  justify-content: center;
  flex-wrap: wrap;
  animation: fadeUp .8s .45s ease both;
  margin-bottom: 70px;
}
/* Hero church image card (decorative panel below buttons) */
.hero-img-panel {
  position: relative;
  z-index: 2;
  width: 100%;
  max-width: 780px;
  margin: 0 auto;
  padding: 0 5vw 80px;
  animation: fadeUp .8s .6s ease both;
}
.hero-img-panel img {
  width: 100%;
  border-radius: 20px;
  box-shadow: 0 24px 80px rgba(0,0,0,.5), 0 0 0 1px rgba(201,168,76,.2);
  border: 1px solid rgba(201,168,76,.25);
  object-fit: cover;
  max-height: 420px;
}
.btn-primary {
  padding: 14px 36px;
  background: var(--gold);
  color: var(--ink);
  border-radius: 50px;
  font-weight: 500;
  font-size: .9rem;
  letter-spacing: .03em;
  transition: var(--transition);
  box-shadow: 0 4px 20px rgba(201,168,76,.4);
}
.btn-primary:hover { background: var(--gold-lt); transform: translateY(-2px); box-shadow: 0 8px 30px rgba(201,168,76,.55); }
.btn-outline {
  padding: 14px 36px;
  border: 1px solid rgba(255,255,255,.35);
  color: rgba(255,255,255,.85);
  border-radius: 50px;
  font-weight: 400;
  font-size: .9rem;
  transition: var(--transition);
  backdrop-filter: blur(4px);
}
.btn-outline:hover { border-color: var(--gold); color: var(--gold-lt); }

.hero .btn-outline { color: var(--navy); border-color: #66758b; background: #fff; backdrop-filter: none; }
.hero .btn-outline:hover { color: var(--navy); border-color: var(--navy); background: #f1eadb; }
.hero h1 em { color: #80621e; }

/* Scroll cue */
.scroll-cue {
  position: absolute;
  bottom: 36px;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  color: rgba(255,255,255,.35);
  font-size: .7rem;
  letter-spacing: .1em;
  text-transform: uppercase;
  animation: fadeUp .8s .7s ease both;
}
.scroll-cue .line {
  width: 1px;
  height: 40px;
  background: linear-gradient(to bottom, rgba(201,168,76,.6), transparent);
  animation: scrollLine 2s ease infinite;
}
@keyframes scrollLine {
  0%, 100% { transform: scaleY(1); opacity: 1; }
  50% { transform: scaleY(.5); opacity: .4; }
}

/* ─── STATS BAR ─────────────────────────────────────────── */
.stats-bar {
  background: var(--white);
  border-bottom: 1px solid var(--ink-20);
  padding: 0 5vw;
}
.stats-inner {
  max-width: 1100px;
  margin: auto;
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  divide-x: 1px solid var(--ink-20);
}
.stat-item {
  padding: 28px 30px;
  border-right: 1px solid var(--ink-20);
  opacity: 0;
  transform: translateY(20px);
  transition: opacity .5s ease, transform .5s ease;
}
.stat-item:last-child { border-right: none; }
.stat-item.visible { opacity: 1; transform: translateY(0); }
.stat-num {
  font-family: var(--font-head);
  font-size: 2.4rem;
  font-weight: 600;
  color: var(--navy);
  line-height: 1;
  margin-bottom: 4px;
}
.stat-label {
  font-size: .78rem;
  font-weight: 400;
  color: var(--ink-60);
  letter-spacing: .05em;
  text-transform: uppercase;
}

/* ─── SECTION WRAPPER ───────────────────────────────────── */
.section {
  padding: 100px 5vw;
}
.section-inner {
  max-width: 1100px;
  margin: auto;
}
.section-header {
  margin-bottom: 56px;
}
.section-tag {
  display: inline-block;
  font-size: .72rem;
  letter-spacing: .14em;
  text-transform: uppercase;
  color: var(--gold);
  font-weight: 500;
  margin-bottom: 12px;
}
.section-tag::before { content: '— '; }
.section-header h2 {
  font-family: var(--font-head);
  font-size: clamp(2rem, 4vw, 3rem);
  font-weight: 400;
  color: var(--ink);
  line-height: 1.15;
}
.section-header p {
  margin-top: 14px;
  font-size: .95rem;
  color: var(--ink-60);
  max-width: 500px;
  font-weight: 300;
}

/* ─── ANNOUNCEMENTS ─────────────────────────────────────── */
#announcements { background: var(--white); }
.ann-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
}
.ann-card {
  border: 1px solid var(--ink-20);
  border-radius: var(--radius);
  padding: 30px;
  transition: var(--transition);
  position: relative;
  overflow: hidden;
  opacity: 0;
  transform: translateY(24px);
}
.ann-card.visible { opacity: 1; transform: translateY(0); }
.ann-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0;
  width: 3px;
  height: 0;
  background: var(--gold);
  transition: height .4s ease;
}
.ann-card:hover::before { height: 100%; }
.ann-card:hover { border-color: rgba(201,168,76,.35); box-shadow: var(--shadow); transform: translateY(-3px); }
.ann-card h3 {
  font-family: var(--font-head);
  font-size: 1.25rem;
  font-weight: 600;
  color: var(--navy);
  margin-bottom: 10px;
}
.ann-card p {
  font-size: .88rem;
  color: var(--ink-60);
  font-weight: 300;
  line-height: 1.7;
  margin-bottom: 16px;
}
.ann-date {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: .73rem;
  color: var(--gold);
  letter-spacing: .06em;
  text-transform: uppercase;
}

/* ─── EVENTS ─────────────────────────────────────────────── */
#events { background: var(--cream); }
.events-list { display: flex; flex-direction: column; gap: 16px; }
.event-row {
  display: grid;
  grid-template-columns: 100px 1fr auto;
  align-items: center;
  gap: 28px;
  background: var(--white);
  border-radius: var(--radius);
  padding: 24px 30px;
  border: 1px solid var(--ink-20);
  transition: var(--transition);
  opacity: 0;
  transform: translateX(-20px);
}
.event-row.visible { opacity: 1; transform: translateX(0); }
.event-row:hover { box-shadow: var(--shadow); border-color: rgba(201,168,76,.3); transform: translateX(4px); }
.event-date-box {
  text-align: center;
  background: var(--navy);
  border-radius: 10px;
  padding: 12px 8px;
  color: var(--white);
}
.event-date-box .day {
  font-family: var(--font-head);
  font-size: 2rem;
  font-weight: 600;
  line-height: 1;
  color: var(--gold-lt);
}
.event-date-box .month {
  font-size: .65rem;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: rgba(255,255,255,.5);
  margin-top: 2px;
}
.event-info h3 {
  font-family: var(--font-head);
  font-size: 1.2rem;
  font-weight: 600;
  color: var(--navy);
  margin-bottom: 4px;
}
.event-info p {
  font-size: .85rem;
  color: var(--ink-60);
  font-weight: 300;
}
.event-tag {
  font-size: .72rem;
  padding: 5px 14px;
  border-radius: 20px;
  background: rgba(201,168,76,.12);
  color: var(--gold);
  font-weight: 500;
  white-space: nowrap;
}

/* ─── SERVICES ───────────────────────────────────────────── */
#services { background: var(--navy); }
#services .section-tag { color: var(--gold-lt); }
#services .section-header h2 { color: var(--white); }
#services .section-header p { color: rgba(255,255,255,.5); }
.services-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 20px;
}
.service-card {
  background: rgba(255,255,255,.05);
  border: 1px solid rgba(255,255,255,.08);
  border-radius: var(--radius);
  padding: 36px 28px;
  transition: var(--transition);
  opacity: 0;
  transform: translateY(30px);
}
.service-card.visible { opacity: 1; transform: translateY(0); }
.service-card:hover {
  background: rgba(201,168,76,.1);
  border-color: rgba(201,168,76,.3);
  transform: translateY(-4px);
}
.service-icon {
  width: 52px; height: 52px;
  border-radius: 12px;
  background: rgba(201,168,76,.15);
  display: grid;
  place-items: center;
  font-size: 1.5rem;
  margin-bottom: 20px;
}
.service-card h3 {
  font-family: var(--font-head);
  font-size: 1.2rem;
  font-weight: 600;
  color: var(--white);
  margin-bottom: 10px;
}
.service-card p {
  font-size: .84rem;
  color: rgba(255,255,255,.5);
  font-weight: 300;
  line-height: 1.7;
}

/* ─── HOW IT WORKS ───────────────────────────────────────── */
#how-it-works { background: var(--white); }
.steps-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 30px;
}
.step {
  text-align: center;
  padding: 20px;
  opacity: 0;
  transform: translateY(24px);
  transition: opacity .5s ease, transform .5s ease;
}
.step.visible { opacity: 1; transform: translateY(0); }
.step-num {
  width: 56px; height: 56px;
  border-radius: 50%;
  border: 1.5px solid var(--gold);
  display: grid;
  place-items: center;
  font-family: var(--font-head);
  font-size: 1.4rem;
  font-weight: 600;
  color: var(--gold);
  margin: 0 auto 20px;
}
.step h3 {
  font-family: var(--font-head);
  font-size: 1.1rem;
  font-weight: 600;
  color: var(--navy);
  margin-bottom: 8px;
}
.step p {
  font-size: .83rem;
  color: var(--ink-60);
  font-weight: 300;
  line-height: 1.7;
}

/* ─── FEATURES ───────────────────────────────────────────── */
#features { background: var(--cream); }
.features-layout {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 60px;
  align-items: center;
}
.features-visual {
  background: linear-gradient(135deg, var(--navy) 0%, #0D1828 100%);
  border-radius: 20px;
  padding: 48px 40px;
  box-shadow: var(--shadow-lg);
  opacity: 0;
  transform: translateX(-30px);
  transition: opacity .7s ease, transform .7s ease;
}
.features-visual.visible { opacity: 1; transform: translateX(0); }
.feature-pill {
  display: flex;
  align-items: center;
  gap: 14px;
  background: rgba(255,255,255,.06);
  border: 1px solid rgba(255,255,255,.08);
  border-radius: 10px;
  padding: 16px 20px;
  margin-bottom: 12px;
  transition: var(--transition);
}
.feature-pill:hover { background: rgba(201,168,76,.1); border-color: rgba(201,168,76,.25); }
.feature-pill-icon {
  font-size: 1.2rem;
  flex-shrink: 0;
}
.feature-pill span {
  font-size: .85rem;
  color: rgba(255,255,255,.8);
  font-weight: 300;
}
.features-content {
  opacity: 0;
  transform: translateX(30px);
  transition: opacity .7s ease, transform .7s ease;
}
.features-content.visible { opacity: 1; transform: translateX(0); }
.feature-item {
  display: flex;
  gap: 18px;
  margin-bottom: 36px;
}
.feature-dot {
  width: 10px; height: 10px;
  border-radius: 50%;
  background: var(--gold);
  flex-shrink: 0;
  margin-top: 6px;
}
.feature-item h4 {
  font-family: var(--font-head);
  font-size: 1.1rem;
  font-weight: 600;
  color: var(--navy);
  margin-bottom: 6px;
}
.feature-item p {
  font-size: .84rem;
  color: var(--ink-60);
  font-weight: 300;
  line-height: 1.7;
}

/* ─── ABOUT ──────────────────────────────────────────────── */
#about {
  background: var(--cream);
  border-top: 1px solid var(--ink-20);
}
.about-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 80px;
  align-items: center;
}
.about-text h2 {
  font-family: var(--font-head);
  font-size: clamp(2rem, 3.5vw, 2.8rem);
  font-weight: 400;
  line-height: 1.2;
  color: var(--ink);
  margin-bottom: 20px;
}
.about-text h2 em { font-style: italic; color: var(--wine); }
.about-text p {
  font-size: .93rem;
  color: var(--ink-60);
  font-weight: 300;
  line-height: 1.9;
  margin-bottom: 16px;
}
.about-quote {
  border-left: 2px solid var(--gold);
  padding-left: 24px;
  margin-top: 32px;
}
.about-quote p {
  font-family: var(--font-head);
  font-size: 1.2rem;
  font-style: italic;
  color: var(--navy);
  font-weight: 400;
  line-height: 1.5;
}
.about-visual {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}
.about-img-card {
  border-radius: 14px;
  overflow: hidden;
  aspect-ratio: 1;
  background: var(--navy);
  display: grid;
  place-items: center;
  font-size: 3rem;
  opacity: 0;
  transform: scale(.94);
  transition: opacity .6s ease, transform .6s ease;
}
.about-img-card.visible { opacity: 1; transform: scale(1); }
.about-img-card:nth-child(2) { margin-top: 30px; }
.about-img-card:nth-child(3) { margin-top: -30px; }

/* ─── CTA ────────────────────────────────────────────────── */
#cta {
  background: linear-gradient(135deg, var(--navy) 0%, #0D1828 100%);
  padding: 120px 5vw;
  text-align: center;
  position: relative;
  overflow: hidden;
}
#cta::before {
  content: ''; display:inline-block; width:1em; height:1em; background:currentColor; -webkit-mask:url(data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22black%22%20stroke-width%3D%221.7%22%3E%3Cpath%20d%3D%22M3%2021V11l9-6%209%206v10H3M12%201v6M9%203h6M9%2021v-7h6v7%22%2F%3E%3C%2Fsvg%3E) center/contain no-repeat; mask:url(data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22black%22%20stroke-width%3D%221.7%22%3E%3Cpath%20d%3D%22M3%2021V11l9-6%209%206v10H3M12%201v6M9%203h6M9%2021v-7h6v7%22%2F%3E%3C%2Fsvg%3E) center/contain no-repeat;
  position: absolute;
  font-size: 30vw;
  color: rgba(255,255,255,.02);
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  pointer-events: none;
  font-family: var(--font-head);
}
.cta-inner { position: relative; z-index: 1; max-width: 620px; margin: auto; }
.cta-inner h2 {
  font-family: var(--font-head);
  font-size: clamp(2.2rem, 4.5vw, 3.4rem);
  font-weight: 300;
  color: var(--white);
  margin-bottom: 16px;
}
.cta-inner h2 em { font-style: italic; color: var(--gold-lt); }
.cta-inner p {
  font-size: .95rem;
  color: rgba(255,255,255,.5);
  font-weight: 300;
  margin-bottom: 40px;
}
.cta-btns { display: flex; gap: 16px; justify-content: center; flex-wrap: wrap; }

/* ─── FOOTER ─────────────────────────────────────────────── */
footer {
  background: var(--ink);
  color: rgba(255,255,255,.45);
  padding: 60px 5vw 36px;
}
.footer-inner {
  max-width: 1100px;
  margin: auto;
}
.footer-top {
  display: grid;
  grid-template-columns: 2fr 1fr 1fr 1fr;
  gap: 40px;
  padding-bottom: 48px;
  border-bottom: 1px solid rgba(255,255,255,.08);
  margin-bottom: 32px;
}
.footer-brand .logo-ring { border-color: rgba(201,168,76,.4); color: rgba(201,168,76,.7); margin-bottom: 16px; }
.footer-brand p {
  font-size: .82rem;
  font-weight: 300;
  line-height: 1.8;
  max-width: 280px;
}
.footer-col h4 {
  font-size: .75rem;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: rgba(255,255,255,.25);
  margin-bottom: 18px;
}
.footer-col a {
  display: block;
  font-size: .84rem;
  color: rgba(255,255,255,.45);
  font-weight: 300;
  margin-bottom: 10px;
  transition: color .3s;
}
.footer-col a:hover { color: var(--gold-lt); }
.footer-bottom {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: .75rem;
}
.footer-bottom a { color: var(--gold); }

/* ─── ANIMATIONS ─────────────────────────────────────────── */
@keyframes fadeUp {
  from { opacity: 0; transform: translateY(28px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* ─── RESPONSIVE ─────────────────────────────────────────── */
@media (max-width: 1024px) {
  .logo-text span:last-child { display: none; }
}
@media (max-width: 900px) {
  nav .hide-mob { display: none; }
  .hamburger { display: flex; }
  .ann-grid, .services-grid { grid-template-columns: 1fr; }
  .steps-grid { grid-template-columns: 1fr 1fr; }
  .features-layout, .about-grid { grid-template-columns: 1fr; }
  .stats-inner { grid-template-columns: 1fr 1fr; }
  .event-row { grid-template-columns: 70px 1fr; }
  .event-tag { display: none; }
  .footer-top { grid-template-columns: 1fr 1fr; }
  .hero-img-panel { padding-bottom: 60px; }
  .features-visual { order: 2; }
  .features-content { order: 1; }
}
@media (max-width: 640px) {
  .logo { min-width:0;flex-shrink:1;gap:8px; }
  .logo-text { min-width:0; }
  .logo-text span:first-child { white-space:normal; }
  header nav { flex-shrink:0; }
  header nav .nav-btn { padding:8px 10px; }
  .features-content { min-width:0;transform:translateY(20px); }
  .feature-item > div { min-width:0;overflow-wrap:anywhere; }
  header { padding: 0 4vw; height: 64px; }
  .mobile-nav { top: 64px; }
  .logo-text span:first-child { font-size: .9rem; }
  .logo-img, .logo-ring { width: 38px; height: 38px; }
  .hero-inner { padding: 80px 5vw 50px; }
  .hero h1 { font-size: clamp(2rem, 9vw, 3.2rem); }
  .hero p { font-size: .93rem; }
  .hero-actions { flex-direction: column; align-items: center; gap: 12px; margin-bottom: 48px; }
  .hero-actions a { width: 100%; max-width: 280px; text-align: center; }
  .hero-img-panel { padding: 0 4vw 50px; }
  .hero-img-panel img { max-height: 240px; border-radius: 12px; }
  .stats-inner { grid-template-columns: 1fr 1fr; }
  .stat-item { padding: 18px 14px; border-right: none; border-bottom: 1px solid var(--ink-20); }
  .stat-item:nth-child(odd) { border-right: 1px solid var(--ink-20); }
  .stat-num { font-size: 1.8rem; }
  .steps-grid { grid-template-columns: 1fr; }
  .footer-top { grid-template-columns: 1fr; }
  .footer-bottom { flex-direction: column; gap: 8px; text-align: center; }
  .section { padding: 60px 5vw; }
  .about-visual { grid-template-columns: 1fr 1fr; }
  .about-img-card { font-size: 2.2rem; }
  .about-img-card:nth-child(2), .about-img-card:nth-child(3) { margin-top: 0; }
  .cta-btns { flex-direction: column; align-items: center; }
  .cta-btns a { width: 100%; max-width: 280px; text-align: center; }
  #cta { padding: 80px 5vw; }
  .section-header { margin-bottom: 36px; }
}
.about-grid{grid-template-columns:1fr}.stats-inner{grid-template-columns:repeat(3,1fr)}
#services{background:#2d4263}.service-card:nth-child(3n+1) .service-icon{color:#f0cf74}.service-card:nth-child(3n+2) .service-icon{color:#a9dec5}.service-card:nth-child(3n) .service-icon{color:#d7c4ed}
.ann-card,.event-info{min-width:0;overflow-wrap:anywhere}.event-date-box{flex-shrink:0}
@media(max-width:600px){.event-row{align-items:flex-start;flex-wrap:wrap}.event-info{flex-basis:100%}.section{padding-top:40px;padding-bottom:40px}}
</style>
<style>
:root {
  --gold: <?php echo htmlspecialchars($ss['color_gold']); ?>;
  --navy: <?php echo htmlspecialchars($ss['color_navy']); ?>;
  --wine: <?php echo htmlspecialchars($ss['color_wine']); ?>;
}
</style>
<?php require __DIR__ . '/includes/branding_head.php'; ?>
</head>
<body>

<!-- ─── HEADER ──────────────────────────────────────────── -->
<header>
  <div class="logo">
    <img class="logo-img" src="<?php echo htmlspecialchars($ss['site_logo']); ?>" alt="<?php echo htmlspecialchars($ss['site_name']); ?> Logo" onerror="this.classList.add('broken')">
    <div class="logo-ring">AV</div>
    <div class="logo-text">
      <span><?php echo htmlspecialchars($ss['site_name']); ?></span>
      <span>Parish Service Platform</span>
    </div>
  </div>
  <nav>
    <a href="#announcements" class="hide-mob"><?= ui_icon('announcement') ?> Announcements</a>
    <a href="#events" class="hide-mob"><?= ui_icon('calendar') ?> Events</a>
    <a href="#services" class="hide-mob"><?= ui_icon('book') ?> Services</a>
    <a href="#about" class="hide-mob"><?= ui_icon('help') ?> About</a>
    <a href="public/login.php" class="hide-mob"><?= ui_icon('lock') ?> Log In</a>
    <a href="public/register.php" class="nav-btn"><?= ui_icon('user') ?> Register</a>
  </nav>
  <button class="hamburger" id="hamburger" aria-label="Toggle menu">
    <?= ui_icon('menu') ?>
  </button>
</header>

<!-- Mobile Nav Drawer -->
<div class="mobile-nav" id="mobileNav">
  <a href="#announcements"><?= ui_icon('announcement') ?> Announcements</a>
  <a href="#events"><?= ui_icon('calendar') ?> Events</a>
  <a href="#services"><?= ui_icon('book') ?> Services</a>
  <a href="#about"><?= ui_icon('help') ?> About</a>
  <a href="#how-it-works"><?= ui_icon('clipboard') ?> How It Works</a>
  <a href="public/login.php"><?= ui_icon('lock') ?> Log In</a>
  <a href="public/register.php" class="nav-btn"><?= ui_icon('user') ?> Register</a>
</div>

<!-- ─── HERO ────────────────────────────────────────────── -->
<section class="hero" id="hero">
  <div class="hero-bg" style="background-image:url('<?php echo htmlspecialchars($ss['hero_bg_image']); ?>')"></div>
  <div class="hero-inner">
    <div class="hero-badge"><?php echo htmlspecialchars($ss['hero_badge']); ?></div>
    <h1><?php echo h(strip_tags($ss['hero_headline'])); ?></h1>
    <p><?php echo htmlspecialchars($ss['hero_subtitle']); ?></p>
    <div class="hero-actions">
      <a href="public/register.php" class="btn-primary">Create Account</a>
      <a href="#services" class="btn-outline">Explore Services</a>
    </div>
  </div>
  <!-- Church interior image panel -->
  <div class="hero-img-panel">
    <img src="<?php echo htmlspecialchars($ss['hero_bg_image']); ?>" alt="<?php echo htmlspecialchars($ss['site_name']); ?> Church Interior">
  </div>
  <div class="scroll-cue">
    <div class="line"></div>
    <span>Scroll</span>
  </div>
</section>

<!-- ─── STATS BAR ────────────────────────────────────────── -->
<div class="stats-bar">
  <div class="stats-inner">
    <div class="stat-item">
      <div class="stat-num">8+</div>
      <div class="stat-label">Parish Services</div>
    </div>

    <div class="stat-item">
      <div class="stat-num">24/7</div>
      <div class="stat-label">Online Access</div>
    </div>
    <div class="stat-item">
      <div class="stat-num">QR</div>
      <div class="stat-label">Verified Certificates</div>
    </div>
  </div>
</div>

<!-- ─── ANNOUNCEMENTS ─────────────────────────────────────── -->
<section class="section" id="announcements">
  <div class="section-inner">
    <div class="section-header">
      <div class="section-tag">Latest Updates</div>
      <h2>Parish Announcements</h2>
      <p>Stay informed with the latest news and updates from your parish community.</p>
    </div>
    <div class="ann-grid">
      <?php if($announcements->num_rows > 0): ?>
        <?php while($ann = $announcements->fetch_assoc()): ?>
          <div class="ann-card"><span class="ann-date"><?= h(date('F d, Y',strtotime($ann['sent_at']??$ann['created_at']))) ?> &middot; <?= h($ann['publisher_role']==='admin'?'Admin':($ann['publisher_parish']??'Parish')) ?></span>
            <h3><?php echo htmlspecialchars($ann['title']); ?></h3>
            <p><?php echo htmlspecialchars($ann['content']); ?></p>
            <span class="ann-date"><?php echo date('F d, Y', strtotime($ann['sent_at'] ?? $ann['created_at'])); ?></span>
          </div>
        <?php endwhile; ?>
      <?php else: ?>
        <div class="ann-card" style="grid-column:1/-1; text-align:center; padding:60px;">
          <p style="color:var(--ink-60); font-style:italic;">No announcements at this time. Check back soon.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ─── EVENTS ───────────────────────────────────────────── -->
<section class="section" id="events" style="background:var(--cream);">
  <div class="section-inner">
    <div class="section-header">
      <div class="section-tag">Calendar</div>
      <h2>Upcoming Events</h2>
      <p>Mark your calendar for upcoming parish gatherings, Masses, and celebrations.</p>
    </div>
    <div class="events-list">
      <?php if($events->num_rows > 0): ?>
        <?php while($ev = $events->fetch_assoc()): 
          $ts = strtotime($ev['event_date']);
        ?>
          <div class="event-row">
            <div class="event-date-box">
              <div class="day"><?php echo date('d', $ts); ?></div>
              <div class="month"><?php echo date('M', $ts); ?></div>
            </div>
            <div class="event-info"><small><?= h($ev['publisher_parish']??'Parish') ?></small>
              <h3><?php echo htmlspecialchars($ev['title']); ?></h3>
              <p><?php echo htmlspecialchars($ev['description']); ?></p>
            </div>
            <span class="event-tag">Upcoming</span>
          </div>
        <?php endwhile; ?>
      <?php else: ?>
        <div style="text-align:center; padding:60px; color:var(--ink-60); font-style:italic;">
          No upcoming events scheduled. Stay tuned.
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ─── SERVICES ─────────────────────────────────────────── -->
<section class="section" id="services">
  <div class="section-inner">
    <div class="section-header">
      <div class="section-tag">Parish Services</div>
      <h2 style="color:var(--white);">What We Offer</h2>
      <p>Apply online for the sacred milestones of your faith journey.</p>
    </div>
    <div class="services-grid">
      <div class="service-card"><div class="service-icon">[icon:calendar]</div><h3>Mass Request</h3><p>Request a Mass and coordinate the schedule with your parish.</p></div>
      <div class="service-card"><div class="service-icon">[icon:heart]</div><h3>Anointing of the Sick</h3><p>Contact the parish for pastoral care and the sacrament of anointing.</p></div>
      <div class="service-card">
        <div class="service-icon">[icon:church]</div>
        <h3>Baptism</h3>
        <p>Register your child for the first sacrament of initiation. Submit requirements and schedule your baptism date online.</p>
      </div>
      <div class="service-card">
        <div class="service-icon">[icon:rings]</div>
        <h3>Wedding</h3>
        <p>Begin your application for the Sacrament of Matrimony. Coordinate dates, pre-Cana, and documentation all in one place.</p>
      </div>
      <div class="service-card">
        <div class="service-icon">[icon:church]</div>
        <h3>Confirmation</h3>
        <p>Apply for the Sacrament of Confirmation. Coordinate sponsor requirements and preparation schedules with parish staff.</p>
      </div>
      <div class="service-card">
        <div class="service-icon">[icon:church]</div>
        <h3>Funeral Mass</h3>
        <p>Submit funeral arrangements and coordinate with the parish for liturgical services during times of loss.</p>
      </div>
      <div class="service-card">
        <div class="service-icon">[icon:church]</div>
        <h3>Blessings</h3>
        <p>Request house, vehicle, or business blessings from parish priests. Schedule and track your blessing request easily.</p>
      </div>
      <div class="service-card">
        <div class="service-icon">[icon:church]</div>
        <h3>Mass Intentions</h3>
        <p>Offer a Holy Mass for your loved ones. Submit and track your Mass intention request and receive confirmation.</p>
      </div>
    </div>
  </div>
</section>

<!-- ─── HOW IT WORKS ──────────────────────────────────────── -->
<section class="section" id="how-it-works" style="background:var(--white);">
  <div class="section-inner">
    <div class="section-header" style="text-align:center;">
      <div class="section-tag" style="display:block; text-align:center;">Simple Process</div>
      <h2>How It Works</h2>
      <p style="max-width:480px; margin:14px auto 0;">Getting started with parish services is simple and straightforward.</p>
    </div>
    <div class="steps-grid">
      <div class="step">
        <div class="step-num">1</div>
        <h3>Create Account</h3>
        <p>Register as a parishioner in minutes. Your profile is reviewed and activated by parish staff.</p>
      </div>
      <div class="step">
        <div class="step-num">2</div>
        <h3>Choose Service</h3>
        <p>Browse available sacramental services and select the one you need to apply for.</p>
      </div>
      <div class="step">
        <div class="step-num">3</div>
        <h3>Submit Application</h3>
        <p>Fill in your details, upload required documents, and submit your application online.</p>
      </div>
      <div class="step">
        <div class="step-num">4</div>
        <h3>Track & Receive</h3>
        <p>Follow your application status in real time and receive QR-verified certificates upon completion.</p>
      </div>
    </div>
  </div>
</section>

<!-- ─── FEATURES ─────────────────────────────────────────── -->
<section class="section" id="features">
  <div class="section-inner">
    <div class="section-header">
      <div class="section-tag">Platform Highlights</div>
      <h2>Built for Every Member</h2>
    </div>
    <div class="features-layout">
      <div class="features-visual">
        <div class="feature-pill">
          <span class="feature-pill-icon">[icon:chart]</span>
          <span>Real-time dashboard for parishioners and staff</span>
        </div>
        <div class="feature-pill">
          <span class="feature-pill-icon">[icon:lock]</span>
          <span>Role-based access: Parishioner · Staff · Admin</span>
        </div>
        <div class="feature-pill">
          <span class="feature-pill-icon">[icon:phone]</span>
          <span>QR Code verification for certificates & events</span>
        </div>
        <div class="feature-pill">
          <span class="feature-pill-icon">[icon:wallet]</span>
          <span>Payment tracking via Cash & GCash</span>
        </div>
        <div class="feature-pill">
          <span class="feature-pill-icon">[icon:calendar]</span>
          <span>Dynamic event calendar and announcements</span>
        </div>
        <div class="feature-pill">
          <span class="feature-pill-icon">[icon:folder]</span>
          <span>Centralized records management for parish staff</span>
        </div>
      </div>
      <div class="features-content">
        <div class="feature-item">
          <div class="feature-dot"></div>
          <div>
            <h4>Parishioner Portal</h4>
            <p>A personal dashboard to apply for services, monitor application status, receive notifications, and manage your parish profile—all in one organized space.</p>
          </div>
        </div>
        <div class="feature-item">
          <div class="feature-dot"></div>
          <div>
            <h4>Staff Management Tools</h4>
            <p>Parish staff can review applications, manage schedules, issue digital certificates, and communicate directly with parishioners through the platform.</p>
          </div>
        </div>
        <div class="feature-item">
          <div class="feature-dot"></div>
          <div>
            <h4>Admin Control Panel</h4>
            <p>Administrators oversee the entire system—managing users, generating reports, monitoring financial transactions, and configuring platform settings.</p>
          </div>
        </div>
        <div class="feature-item" style="margin-bottom:0;">
          <div class="feature-dot"></div>
          <div>
            <h4>Secure & Reliable</h4>
            <p>Built with security in mind, the platform protects parishioner data with encrypted connections, proper authentication, and role-separated access controls.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ─── ABOUT ─────────────────────────────────────────────── -->
<section class="section" id="about">
  <div class="section-inner">
    <div class="about-grid">
      <div class="about-text">
        <div class="section-tag">Who We Are</div>
        <h2>Serving the <em>Faithful</em> of San Jose</h2>
        <p>The Apostolic Vicariate of San Jose is a Catholic ecclesiastical territory in the Philippines, home to a diverse and growing faith community. This platform was created to bridge the gap between parishioners and parish administration—making sacred services more accessible and processes more transparent.</p>
        <p>Whether you're a long-time parishioner or new to the community, our platform ensures you receive timely, dignified service for every milestone in your faith journey.</p>
        <div class="about-quote">
          <p>"Serve one another humbly in love."<br>— Galatians 5:13</p>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- ─── CTA ───────────────────────────────────────────────── -->
<section id="cta">
  <div class="cta-inner">
    <h2>Begin Your<br><em>Faith Journey</em> Today</h2>
    <p>Join thousands of parishioners already using the platform to connect with their parish community and access sacred services with ease.</p>
    <div class="cta-btns">
      <a href="public/register.php" class="btn-primary">Create Free Account</a>
      <a href="public/login.php" class="btn-outline">Sign In</a>
    </div>
  </div>
</section>

<!-- ─── FOOTER ────────────────────────────────────────────── -->
<footer>
  <div class="footer-inner">
    <div class="footer-top">
      <div class="footer-brand">
        <div class="logo-ring">AV</div>
        <p>The Apostolic Vicariate of San Jose Parish Service Platform — connecting faith communities with the services they need.</p>
      </div>
      <div class="footer-col">
        <h4>Services</h4>
        <a href="#">Baptism</a>
        <a href="#">Wedding</a>
        <a href="#">Confirmation</a>
        <a href="#">Funeral</a>
        <a href="#">Blessings</a>
        <a href="#">Mass Intentions</a>
      </div>
      <div class="footer-col">
        <h4>Platform</h4>
        <a href="#announcements">Announcements</a>
        <a href="#events">Events</a>
        <a href="#features">Features</a>
        <a href="#about">About</a>
        <a href="#how-it-works">How It Works</a>
      </div>
      <div class="footer-col">
        <h4>Account</h4>
        <a href="public/login.php">Log In</a>
        <a href="public/register.php">Register</a>
        <a href="#">Staff Portal</a>
        <a href="#">Admin Panel</a>
      </div>
    </div>
    <div class="footer-bottom">
      <span>&copy; 2026 Apostolic Vicariate of San Jose. All rights reserved.</span>
      <span>Built with faith &amp; care · <a href="#">Privacy Policy</a></span>
    </div>
  </div>
</footer>

<script>
// ─── HAMBURGER MENU ───────────────────────────────────────
const hamburger = document.getElementById('hamburger');
const mobileNav = document.getElementById('mobileNav');
hamburger.addEventListener('click', () => {
  hamburger.classList.toggle('open');
  mobileNav.classList.toggle('open');
});
// Close on link click
mobileNav.querySelectorAll('a').forEach(a => {
  a.addEventListener('click', () => {
    hamburger.classList.remove('open');
    mobileNav.classList.remove('open');
  });
});

// ─── INTERSECTION OBSERVER ANIMATIONS ────────────────────
const targets = document.querySelectorAll(
  '.ann-card, .event-row, .service-card, .step, .stat-item, .features-visual, .features-content, .about-img-card'
);

const io = new IntersectionObserver((entries) => {
  entries.forEach((entry, i) => {
    if (entry.isIntersecting) {
      // Stagger by index within parent
      const siblings = Array.from(entry.target.parentElement.children);
      const idx = siblings.indexOf(entry.target);
      setTimeout(() => {
        entry.target.classList.add('visible');
      }, idx * 80);
      io.unobserve(entry.target);
    }
  });
}, { threshold: 0.1 });

targets.forEach(el => io.observe(el));
</script>

<section style="padding:24px"><p><?= nl2br(h($ss['homepage_text'])) ?></p><p><?= nl2br(h($ss['contact_details'])) ?></p></section>
</body>
</html>
