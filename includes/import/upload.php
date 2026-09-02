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

  <fieldset class="unit-choice">
    <legend>The "Time spent" column is in</legend>
    <label class="inline"><input type="radio" name="time_unit" value="minutes" checked> minutes</label>
    <label class="inline"><input type="radio" name="time_unit" value="hours"> hours</label>
    <small><strong>Check this every time.</strong> Exports seen so far are in minutes, a value
           of 960 meaning 16 hours. If time logging is ever switched to hours, the same 16 hours
           arrives as <code>16</code>, and importing that as minutes would record 16 minutes:
           a 60× error landing exactly in the figures used to check invoices.</small>
  </fieldset>

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
