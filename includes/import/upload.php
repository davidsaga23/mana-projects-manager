<?php
// Import screen 1 — choose the file.
// Plaky's "Time spent" column is not read at all since 2026-10-17 (David: "let's
// delete the time spent column from the system, we're not going to use it
// anymore"); time is logged in Anima itself. So there is nothing about time to
// ask or to state here any more.
// Variables in scope from import.php: $pdo, $project, $projectId, $selfUrl.
?>
<p class="lead">Reads a Plaky board export and updates this project's shots, assets and
   progress. Nothing is written until you've seen exactly what will change.</p>

<form method="post" enctype="multipart/form-data" class="stack">
  <input type="hidden" name="action" value="upload">

  <label>Board export (.csv)
    <?php // The shared drop zone (assets/dropzone.js), loaded at the foot of
          // this screen, David 2026-10-26: "let's add a drop zone here". ?>
    <input type="file" name="file" accept=".csv,text/csv" required
           data-drop="Drop the Plaky export here" data-drop-paste="no">
    <small>In Plaky: open the board → menu → Export → CSV. Export the whole board,
           including subitems, the subitems are the pipeline steps.</small>
  </label>

  <div class="actions">
    <button type="submit">Read the file →</button>
  </div>
</form>

<h3>What it does</h3>
<ul class="plain-list">
  <li><strong>Parent rows</strong>, items with no parent of their own, become shots or
      assets, depending on how you map their group.</li>
  <li><strong>Subitems</strong> become progress against a pipeline step: status and who is
      on it. Plaky's <em>Time spent</em> column is ignored, time is logged here instead.</li>
  <li>Existing records are <strong>updated, never duplicated</strong>, matched on episode and
      shot code, or on asset name.</li>
  <li>A blank cell never wipes something already recorded, an import adds what it knows.</li>
</ul>

<script src="assets/dropzone.js<?= asset_ver('dropzone.js') ?>"></script>
<script>window.animaDropzone.attachAll(document);</script>
