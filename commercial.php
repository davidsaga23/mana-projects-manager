<?php
// COMMERCIAL: the studio's CRM, in six tabs (migration 088).
//
// David, 2026-11-12: "can we incorporate this to anima?", about the CRM he
// prototyped as a claude.ai artifact. The prototype put these views in a
// "Commercial" sidebar; Anima has no sidebar, so Commercial is a button in the
// top bar and the six views are tabs here:
//
//   Prospects   every lead at Contacted, its contacts listed under it, with the
//               outreach summary on top
//   Pipeline    Interested and later, grouped by stage; Won and Lost folded
//   Follow-ups  open leads by follow-up date, with +1d / +1w
//   Clients     one row per company
//   Proposals   a board of decks and creative proposals
//   Quotes      budgets with line items, numbered Q-YYYY-NNN
//
// This page is the frame; assets/commercial.js draws the views from
// commercial_api.php and saves through it. The rules live in includes/crm.php.
//
// ⚠ SALES, NOT COST. Nothing here touches a show's budget or the finance
// database. See the top of includes/crm.php.
//
// WHO: the "Commercial" switch on Users → Access, administrators only to begin
// with (David, 2026-11-12). Everyone who can open it can edit.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/crm.php';
require_login();

if (!crm_allowed()) {
    flash('err', "Commercial isn't open to your account. Ask an administrator if you need it.");
    header('Location: index.php');
    exit;
}

$ready = crm_available($pdo);
$pageTitle = 'Commercial';
require __DIR__ . '/includes/header.php';

// The six views. The counts are filled in by the script once the data is in.
$views = [
    'prospects' => 'Prospects', 'pipeline' => 'Pipeline', 'followups' => 'Follow-ups',
    'clients' => 'Clients', 'proposals' => 'Proposals', 'quotes' => 'Quotes',
];
?>
<link rel="stylesheet" href="assets/commercial.css<?= asset_ver('commercial.css') ?>">

<?php if (!$ready): ?>
  <h2 class="wizard-title">Commercial</h2>
  <div class="empty-state">
    <p><strong>Commercial needs a database update before it can open.</strong></p>
    <p class="muted">Update 088 adds the tables for leads, contacts, proposals and quotes.
      <?php if (is_admin()): ?><a href="migrate.php">Open Migrations to apply it →</a><?php else: ?>Ask an administrator to apply it.<?php endif; ?></p>
  </div>
<?php else: ?>
<div class="crm" id="crm" data-me="<?= (int)($_SESSION['user_id'] ?? 0) ?>" data-readonly="<?= preview_blocks_saving() ? '1' : '0' ?>">
  <div class="crm-head">
    <h2 class="crm-title">Commercial <span class="muted">· <span id="view-title">Pipeline</span></span>
      <span class="admin-only-tag" title="Sales figures: kept apart from every show's budget and costs">sales, not show budgets</span></h2>
    <div class="crm-tools">
      <button type="button" class="btn" id="export" hidden>Export CSV</button>
      <button type="button" class="btn" id="import" hidden>Import CSV</button>
      <button type="button" class="btn primary" id="new-lead" hidden>+ New lead</button>
      <button type="button" class="btn primary" id="new-prop" hidden>+ New proposal</button>
      <button type="button" class="btn primary" id="new-quote" hidden>+ New quote</button>
    </div>
  </div>

  <nav class="crm-tabs" aria-label="Commercial views">
    <?php foreach ($views as $k => $label): ?>
      <a href="#<?= e($k) ?>" data-view="<?= e($k) ?>" class="crm-tab"><?= e($label) ?><span class="count" id="c-<?= e($k) ?>"<?= $k === 'followups' ? ' hidden' : '' ?>></span></a>
    <?php endforeach; ?>
  </nav>

  <section class="kpis" id="kpis" aria-label="Summary"></section>
  <p class="kpi-note" id="kpi-note" hidden></p>
  <section class="filters" aria-label="Filters">
    <input id="flt-q" class="q" type="search" placeholder="Client, contact, email or tag" aria-label="Search client or contact">
    <select id="flt-stage" aria-label="Status"></select>
    <select id="flt-type" aria-label="Project type"></select>
    <select id="flt-source" aria-label="Source"></select>
    <select id="flt-owner" aria-label="Owner"></select>
    <span class="range"><span class="lbl">Value</span><input id="flt-min" type="number" min="0" step="1000" inputmode="numeric" placeholder="Min $" aria-label="Minimum value"> – <input id="flt-max" type="number" min="0" step="1000" inputmode="numeric" placeholder="Max $" aria-label="Maximum value"></span>
    <select id="flt-due" aria-label="Follow-up">
      <option value="">Any follow-up</option><option value="overdue">Overdue</option><option value="week">Due in next 7 days</option><option value="none">No follow-up set</option>
    </select>
    <button type="button" class="btn link" id="flt-clear" hidden>Clear filters</button>
  </section>
  <div class="crm-status" id="status" hidden></div>
  <section id="view"></section>

  <datalist id="types-dl"></datalist>
  <div id="drawer-root"></div>
  <div id="modal-root"></div>
  <div class="crm-toast" id="toast" role="status" hidden></div>
</div>
<script src="assets/commercial.js<?= asset_ver('commercial.js') ?>"></script>
<?php endif; ?>
</div>
</body></html>
