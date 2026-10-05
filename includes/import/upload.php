<?php
// Import screen 1 — choose the file and declare what its time column means.
// Variables in scope from import.php: $pdo, $project, $projectId, $selfUrl.
?>
<p class="lead">Reads a Plaky board export and updates this project's shots, assets and
   progress. Nothing is written until you've seen exactly what will change.</p>

<form method="post" enctype="multipart/form-data" class="stack">
  <input type="hidden" name="action" value="upload">

  <label>Board export (.csv)
    <input type="file" name="file" accept=".csv,text/csv" required>
    <small>In Plaky: open the board → menu → Export → CSV. Export the whole board,
           including subitems, the subitems are the pipeline steps.</small>
  </label>

  <?php // THE CHOICE IS GONE, the assumption is not (David, 2026-10-05: "let's
        // delete the time spent part of the import, every single project in
        // plaky will be in hours from now on").
        //
        // It used to be a pair of radio buttons answered on every import. That
        // was right while Plaky boards disagreed with each other; now they all
        // log in hours, and a question with one correct answer is a question
        // somebody eventually gets wrong.
        //
        // ⚠ THE RISK DID NOT GO AWAY, IT WAS DECIDED. Reading hours as minutes
        // is a 60× error landing straight in the figures used to check invoices.
        // So the assumption is still SAID, here and on the next screen, rather
        // than buried in plaky_run(): if a board ever logs in minutes again,
        // 960 would import as 960 hours and the line below is the only thing on
        // screen that would have warned anybody. ?>
  <p class="muted small">
    <strong>Time spent is read as hours.</strong> Every Plaky board logs in hours, so
    <code>16</code> imports as 16 hours. A board still logging in minutes would import
    <code>960</code> as 960 hours — check the board before importing it if you are not sure.
  </p>

  <div class="actions">
    <button type="submit">Read the file →</button>
  </div>
</form>

<h3>What it does</h3>
<ul class="plain-list">
  <li><strong>Parent rows</strong>, items with no parent of their own, become shots or
      assets, depending on how you map their group.</li>
  <li><strong>Subitems</strong> become progress against a pipeline step: status, who is on it,
      and time logged.</li>
  <li>Existing records are <strong>updated, never duplicated</strong>, matched on episode and
      shot code, or on asset name.</li>
  <li>A blank cell never wipes something already recorded, an import adds what it knows.</li>
</ul>
