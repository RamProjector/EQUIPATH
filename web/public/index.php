<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="EquiPath ETEEAP application and accreditation tracking portal for ADFC.">
  <title>EquiPath | ADFC ETEEAP</title>
  <link rel="stylesheet" href="../assets/css/app.css">
  <script src="../assets/js/app.js" defer></script>
</head>
<body>
  <main class="shell">
    <header class="topbar">
      <a class="brand" href="./" aria-label="EquiPath home">
        <span class="brand-mark" aria-hidden="true">E</span>
        <span>EquiPath</span>
      </a>
      <span class="environment">Development scaffold</span>
    </header>

    <section class="hero">
      <p class="eyebrow">Asian Development Foundation College · ETEEAP</p>
      <h1>Your experience.<br><span>Your next academic step.</span></h1>
      <p class="intro">A clearer path for applications, portfolio evidence, evaluation progress, assessment schedules, and authorized accreditation decisions.</p>
      <div class="actions">
        <a class="button primary" href="#workflow">Explore workflow</a>
        <a class="button secondary" href="../api/health.php">Check API</a>
      </div>
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
      <span>Development scaffold — do not submit real applicant records yet.</span>
    </footer>
  </main>
</body>
</html>
