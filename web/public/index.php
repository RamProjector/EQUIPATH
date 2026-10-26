<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="EquiPath ETEEAP application and accreditation tracking portal for ADFC.">
  <meta name="theme-color" content="#f5f8fa">
  <title>EquiPath | ADFC ETEEAP</title>
  <link rel="stylesheet" href="../assets/css/app.css">
  <script src="../assets/js/app.js" defer></script>
</head>
<body>
  <a class="skip-link" href="#main-content">Skip to content</a>
  <main class="shell" id="main-content">
    <header class="topbar">
      <a class="brand" href="./" aria-label="EquiPath home">
        <span class="brand-mark" aria-hidden="true">E</span>
        <span>EquiPath</span>
      </a>
      <span class="environment">Development environment</span>
    </header>

    <section class="hero">
      <p class="eyebrow">Asian Development Foundation College · ETEEAP</p>
      <h1>Your experience.<br><span>Your next academic step.</span></h1>
      <p class="intro">A clearer path for applications, portfolio evidence, evaluation progress, assessment schedules, and authorized accreditation decisions.</p>
      <div class="actions">
        <a class="button primary" href="#sign-in">Sign in to EquiPath</a>
        <a class="button secondary" href="../api/health.php">Check API</a>
      </div>
    </section>

    <section id="sign-in" class="workspace-card" aria-labelledby="sign-in-title">
      <div class="section-heading">
        <p class="eyebrow">Secure access</p>
        <h2 id="sign-in-title">Sign in</h2>
        <p class="section-intro">Use an account provisioned by your institution. Public account registration is not available in this development build.</p>
      </div>
      <form id="login-form" class="login-form" autocomplete="on">
        <label for="email">Institutional email</label>
        <input id="email" name="email" type="email" autocomplete="username" maxlength="254" required>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" maxlength="4096" required>
        <button class="button primary" id="login-button" type="submit">Sign in</button>
      </form>
      <p id="login-status" class="status-message" role="status" aria-live="polite"></p>
    </section>

    <section id="workspace" class="workspace-card" aria-labelledby="workspace-title" hidden>
      <div class="workspace-heading">
        <div>
          <p class="eyebrow">Your workspace</p>
          <h2 id="workspace-title">Welcome</h2>
          <p id="account-label" class="section-intro"></p>
        </div>
        <button class="button secondary" id="logout-button" type="button">Sign out</button>
      </div>

      <section class="inbox" aria-labelledby="inbox-title">
        <div class="inbox-heading">
          <div>
            <h3 id="inbox-title">Notifications</h3>
            <p class="section-intro">Private updates for your signed-in account.</p>
          </div>
          <button id="refresh-notifications" class="button secondary compact" type="button">Refresh</button>
        </div>
        <p id="notification-status" class="status-message" role="status" aria-live="polite"></p>
        <ul id="notification-list" class="notification-list" aria-label="Your notifications"></ul>
      </section>
    </section>

    <section id="workflow" class="workflow" aria-labelledby="workflow-title">
      <div class="section-heading">
        <p class="eyebrow">The process</p>
        <h2 id="workflow-title">One process, clear progress.</h2>
      </div>
      <div class="cards">
        <article class="card"><span class="step">01</span><h3>Apply</h3><p>Prepare an application and submit supporting evidence.</p></article>
        <article class="card"><span class="step">02</span><h3>Screen</h3><p>Authorized staff check completeness and request corrections.</p></article>
        <article class="card"><span class="step">03</span><h3>Evaluate</h3><p>Assigned evaluators review evidence against approved criteria.</p></article>
        <article class="card"><span class="step">04</span><h3>Decide</h3><p>Authorized personnel record official recommendations and decisions.</p></article>
      </div>
    </section>

    <footer>
      <span>EquiPath · ADFC</span>
      <span>Development build — do not submit real applicant records.</span>
    </footer>
  </main>
</body>
</html>
