<?php
require_once __DIR__ . '/includes/icons.php';

if (!isset($_COOKIE['splash_seen'])) {
    setcookie('splash_seen', '1', 0, '/');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Apostolic Vicariate of San Jose</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">

<style>
*, *::before, *::after {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

html,
body {
  height: 100%;
  overflow: hidden;
  font-family: 'DM Sans', sans-serif;
  background: #FFFFFF;
}

.bg-grid {
  position: fixed;
  inset: 0;
  pointer-events: none;
  background-image:
    linear-gradient(rgba(201,168,76,.10) 1px, transparent 1px),
    linear-gradient(90deg, rgba(201,168,76,.10) 1px, transparent 1px);
  background-size: 52px 52px;
}

.bg-glow {
  position: fixed;
  top: 50%;
  left: 50%;
  width: 500px;
  height: 500px;
  border-radius: 50%;
  pointer-events: none;
  background: radial-gradient(
    circle,
    rgba(201,168,76,.18) 0%,
    transparent 70%
  );
  transform: translate(-50%, -50%);
  animation: glowPulse 4s ease-in-out infinite;
}

@keyframes glowPulse {
  0%,
  100% {
    opacity: .5;
    transform: translate(-50%, -50%) scale(1);
  }

  50% {
    opacity: 1;
    transform: translate(-50%, -50%) scale(1.2);
  }
}

.stage {
  position: relative;
  z-index: 10;
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 24px;
  text-align: center;
}

/* Actual Logo Image */
.brand-logo {
  width: 115px;
  height: 115px;
  margin-bottom: 22px;
  display: flex;
  align-items: center;
  justify-content: center;
  opacity: 0;
  animation: fadeUp .9s .2s ease forwards;
  filter: drop-shadow(0 0 24px rgba(201,168,76,.28));
}

.brand-logo img {
  width: 100%;
  height: 100%;
  display: block;
  object-fit: contain;
}

.org-tag {
  margin-bottom: 12px;
  color: #A8862F;
  font-family: 'Cormorant Garamond', serif;
  font-size: .72rem;
  font-weight: 400;
  letter-spacing: .2em;
  text-transform: uppercase;
  opacity: 0;
  animation: fadeUp .9s .4s ease forwards;
}

.title {
  margin-bottom: 8px;
  color: #0D1828;
  font-family: 'Cormorant Garamond', serif;
  font-size: clamp(2rem, 6vw, 3rem);
  font-weight: 400;
  line-height: 1.15;
  opacity: 0;
  animation: fadeUp .9s .55s ease forwards;
}

.title em {
  color: #B8912F;
  font-style: italic;
}

.subtitle {
  margin-bottom: 52px;
  color: rgba(13,24,40,.55);
  font-size: .82rem;
  font-weight: 300;
  letter-spacing: .07em;
  opacity: 0;
  animation: fadeUp .9s .7s ease forwards;
}

.bar-track {
  width: 240px;
  height: 2px;
  margin-bottom: 18px;
  overflow: hidden;
  border-radius: 2px;
  background: rgba(13,24,40,.10);
  opacity: 0;
  animation: fadeUp .6s .85s ease forwards;
}

.bar-fill {
  width: 0;
  height: 100%;
  border-radius: 2px;
  background: linear-gradient(90deg, #C9A84C, #E8C97A);
  animation: barLoad 2.8s .9s cubic-bezier(.4,0,.2,1) forwards;
}

@keyframes barLoad {
  0%   { width: 0%; }
  25%  { width: 30%; }
  55%  { width: 62%; }
  80%  { width: 86%; }
  100% { width: 100%; }
}

.status {
  height: 18px;
  margin-bottom: 18px;
  color: rgba(13,24,40,.5);
  font-size: .72rem;
  letter-spacing: .14em;
  text-transform: uppercase;
  opacity: 0;
  transition: opacity .3s ease;
  animation: fadeUp .6s 1s ease forwards;
}

.dots {
  display: flex;
  gap: 6px;
  opacity: 0;
  animation: fadeUp .5s 1.1s ease forwards;
}

.dot {
  width: 4px;
  height: 4px;
  border-radius: 50%;
  background: rgba(201,168,76,.5);
}

.dot:nth-child(1) {
  animation: dotBounce 1.4s infinite;
}

.dot:nth-child(2) {
  animation: dotBounce 1.4s .22s infinite;
}

.dot:nth-child(3) {
  animation: dotBounce 1.4s .44s infinite;
}

@keyframes dotBounce {
  0%,
  100% {
    opacity: .35;
    transform: scale(1);
  }

  50% {
    opacity: 1;
    background: #C9A84C;
    transform: scale(1.55);
  }
}

.btn-start {
  display: none;
  align-items: center;
  gap: 10px;
  padding: 14px 42px;
  border: none;
  border-radius: 50px;
  color: #1A1510;
  background: #C9A84C;
  font-size: .9rem;
  font-weight: 500;
  letter-spacing: .03em;
  text-decoration: none;
  cursor: pointer;
  opacity: 0;
  transform: translateY(10px);
  transition: .3s ease;
}

.btn-start.show {
  display: inline-flex;
  animation: fadeUp .7s ease forwards;
}

.btn-start:hover {
  background: #E8C97A;
  box-shadow: 0 10px 30px rgba(201,168,76,.4);
  transform: translateY(-2px);
}

.btn-start svg {
  width: 16px;
  height: 16px;
}

@keyframes fadeUp {
  from {
    opacity: 0;
    transform: translateY(18px);
  }

  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@media (max-width: 480px) {
  .brand-logo {
    width: 95px;
    height: 95px;
  }

  .bar-track {
    width: 210px;
  }

  .subtitle {
    margin-bottom: 42px;
  }
}
</style>
</head>

<body>

<div class="bg-grid"></div>
<div class="bg-glow"></div>

<main class="stage">

  <div class="brand-logo">
    <img
      src="assets/img/logo-homepage.png"
      alt="Apostolic Vicariate of San Jose Logo"
      loading="eager"
    >
  </div>

  <div class="org-tag">
    Apostolic Vicariate of San Jose
  </div>

  <h1 class="title">
    Parish <em>Service</em><br>
    Platform
  </h1>

  <p class="subtitle">
    Est. 2026 &middot; Diocese of San Jose
  </p>

  <div class="bar-track">
    <div class="bar-fill" id="barFill"></div>
  </div>

  <p class="status" id="statusTxt">
    Loading parish services&hellip;
  </p>

  <div class="dots" id="dots">
    <div class="dot"></div>
    <div class="dot"></div>
    <div class="dot"></div>
  </div>

  <a href="index.php" class="btn-start" id="btnStart">
    Get Started

    <svg
      viewBox="0 0 16 16"
      fill="none"
      stroke="currentColor"
      stroke-width="1.6"
      stroke-linecap="round"
      stroke-linejoin="round"
    >
      <path d="M3 8h10M9 4l4 4-4 4"/>
    </svg>
  </a>

</main>

<script>
(function () {
  const statusTxt = document.getElementById('statusTxt');
  const dots = document.getElementById('dots');
  const btnStart = document.getElementById('btnStart');

  const messages = [
    'Loading parish services…',
    'Preparing your experience…',
    'Almost ready…'
  ];

  let index = 0;

  const messageTimer = setInterval(function () {
    index++;

    if (index < messages.length) {
      statusTxt.style.opacity = '0';

      setTimeout(function () {
        statusTxt.textContent = messages[index];
        statusTxt.style.opacity = '1';
      }, 220);
    }
  }, 1100);

  setTimeout(function () {
    clearInterval(messageTimer);

    statusTxt.style.opacity = '0';
    dots.style.opacity = '0';

    setTimeout(function () {
      statusTxt.style.display = 'none';
      dots.style.display = 'none';
      btnStart.classList.add('show');
    }, 320);

  }, 3700);
})();
</script>

</body>
</html>