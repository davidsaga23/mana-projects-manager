<?php
// The Board was renamed Spell on 2026-10-22 (David: "that's how we're going to
// call it"), and its page moved to spell.php. This stays so the links already
// out there keep working: mention emails sent before the rename point here with
// ?project=…&open=…, and a bookmark is a bookmark. The query string goes along.
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: spell.php' . ($qs !== '' ? '?' . $qs : ''), true, 301);
exit;
