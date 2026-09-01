<?php
/**
 * The ONE door to pay data — and it opens onto a different database.
 *
 * HOW THIS ENDED UP HERE, because the file only makes sense with the history.
 *
 *   2026-08-12  Money was kept out of this codebase entirely: separate app,
 *               separate database, separate credentials. The production app
 *               would hold no finance credential at all.
 *   2026-08-15  Migration 018 put a rate on `crew` — one screen, application
 *               level guard. David's call, with the consequence stated.
 *   2026-08-15  Reconsidered the same day and settled on the middle path, which
 *               is what this file implements. Migration 019 removed those
 *               columns again.
 *
 * WHAT THE MIDDLE PATH ACTUALLY BUYS, stated precisely so nobody over-trusts it:
 *
 *   The production MySQL user has NO RIGHTS to the finance database. So a bug in
 *   any ordinary query, on any ordinary screen — a bad WHERE clause, an
 *   injection, a join written at midnight — cannot reach a rate, because the
 *   connection it runs on is not permitted to see one. That is a property of the
 *   database server, not of anyone's diligence, and it holds even for screens
 *   written years from now by someone who never read this comment.
 *
 *   It is NOT the same as the 2026-08-12 design. This application does hold a
 *   finance credential, in the same config.php. Someone who can read that file,
 *   or run arbitrary code as this app, can reach the rates. What is defended
 *   against is the far likelier thing: an ordinary mistake in ordinary code.
 *
 * THE RULES THAT KEEP IT TRUE:
 *   1. Nothing outside this file ever opens the finance connection.
 *   2. Every function here returns nothing at all for a non-admin — not a
 *      partial row, not nulls. A caller that forgets to check renders an empty
 *      box, which is the right way to fail.
 *   3. The link between the two databases is `crew.id`. It must stay stable and
 *      must never be reused (DECISIONS, 2026-08-12) — a recycled id would attach
 *      one person's hours to another's rate.
 */

/** May the current account see pay data at all? */
function can_see_money(): bool {
    return is_admin();
}

/**
 * The finance connection, opened lazily and only for an admin.
 *
 * Returns null when finance is not configured, which is the normal state until
 * somebody follows finance_install.sql — the screens say so rather than
 * pretending the data is simply absent.
 *
 * A failure to connect is reported, never swallowed: "no rate on file" and "the
 * finance database is unreachable" look identical on screen otherwise, and this
 * codebase has paid for that confusion before (START_HERE, lesson 11).
 */
function finance_db(): ?PDO {
    static $pdo = null;
    static $tried = false;
    global $config;

    if (!can_see_money()) return null;
    if ($tried) return $pdo;
    $tried = true;

    $f = $config['finance_db'] ?? null;
    if (!is_array($f) || empty($f['db_name']) || empty($f['db_user'])) return null;

    try {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s',
            $f['db_host'] ?? 'localhost', $f['db_name'], $f['db_charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, $f['db_user'], $f['db_pass'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('[Anima finance db] ' . $e->getMessage());
        throw new RuntimeException(
            'The finance database is configured but could not be opened. Pay terms are '
            . 'unavailable until that is fixed — this is not the same as nobody having a rate. '
            . 'Check the finance_db block in config.php.'
        );
    }
    return $pdo;
}

/**
 * Does a column exist in the FINANCE database?
 *
 * column_exists() asks the production connection and knows nothing about this
 * one, so schema added by a later finance_install file had no guard — and a
 * query naming a column that is not there is a fatal, not a warning. That took
 * the Finance hub down: the page rendered its table header, hit the missing
 * `invoices.step_id` on the first row, and stopped mid-output.
 *
 * Same shape as column_exists(): one SHOW per table, cached for the request,
 * and NO placeholder — db.php disables emulated prepares, so a parameter in a
 * SHOW statement is rejected by MySQL's protocol (START_HERE, lesson 11).
 */
function finance_column_exists(string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];

    $db = finance_db();
    if (!$db) return $cache[$key] = false;

    $safe = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    try {
        $cols = $db->query('SHOW COLUMNS FROM `' . $safe . '`')->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return $cache[$key] = false;
    }
    foreach ($cols as $c) $cache[$table . '.' . $c] = true;
    return $cache[$key] = in_array($column, $cols, true);
}

/** Is finance set up at all? For telling "not configured" from "no rate recorded". */
function finance_configured(): bool {
    global $config;
    $f = $config['finance_db'] ?? null;
    return is_array($f) && !empty($f['db_name']) && !empty($f['db_user']);
}

/**
 * Pay terms for a set of crew ids, keyed by crew id. Empty for a non-admin.
 *
 * @param int[] $crewIds
 */
function crew_terms(array $crewIds): array {
    if (!can_see_money() || !$crewIds) return [];
    $db = finance_db();
    if (!$db) return [];

    // Ids are cast to int and inlined: a variable-length IN list cannot be bound
    // as a single parameter, and every value here has been through (int).
    $ids = implode(',', array_map('intval', $crewIds));
    $rows = $db->query("SELECT * FROM crew_terms WHERE crew_id IN ({$ids})")->fetchAll();

    $out = [];
    foreach ($rows as $r) $out[(int)$r['crew_id']] = $r;
    return $out;
}

/** One person's terms, or null. */
function crew_term(int $crewId): ?array {
    return crew_terms([$crewId])[$crewId] ?? null;
}

/**
 * Save one person's terms into the finance database.
 *
 * @return string a sentence for the flash message
 * @throws RuntimeException when the caller may not do this, or the input is bad
 */
function save_crew_terms(int $crewId, string $crewName, array $in, string $who = ''): string {
    if (!can_see_money()) {
        throw new RuntimeException('Only an administrator can change pay terms.');
    }
    $db = finance_db();
    if (!$db) {
        throw new RuntimeException(
            'The finance database is not set up yet, so there is nowhere to save this. '
            . 'See finance_install.sql.'
        );
    }

    $rate = trim((string)($in['day_rate'] ?? ''));
    if ($rate === '') {
        $rateVal = null;
    } else {
        // Accept "1,200.00" and "$1200" — people paste from a spreadsheet.
        $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $rate));
        if ($clean === '' || !is_numeric($clean)) {
            throw new RuntimeException('"' . $rate . '" is not a number — nothing was saved.');
        }
        $rateVal = round((float)$clean, 2);
        if ($rateVal < 0) throw new RuntimeException('A rate cannot be negative.');
    }

    $unit = in_array($in['rate_unit'] ?? '', RATE_UNITS, true) ? $in['rate_unit'] : 'day';

    $cur = strtoupper(trim((string)($in['rate_currency'] ?? 'USD')));
    if (!preg_match('/^[A-Z]{3}$/', $cur)) $cur = 'USD';

    $url = trim((string)($in['contract_url'] ?? ''));
    if ($url !== '') {
        // http/https only. A javascript: or data: URL here is a link an admin
        // clicks from a page that already trusts them.
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('A contract link must start with http:// or https:// — nothing was saved.');
        }
        if (mb_strlen($url) > 500) throw new RuntimeException('That link is too long to store.');
    }

    // Contract state, added by finance_install_04.sql. Guarded rather than
    // assumed: this app has to keep working against a finance database one file
    // behind the code, and a query naming a column that is not there is a fatal
    // that takes the whole page down — which is exactly how the Finance hub fell
    // over on invoices.step_id.
    $hasContract = finance_column_exists('crew_terms', 'contract_status');
    $cStatus = $cSent = $cSigned = null;
    if ($hasContract) {
        $cStatus = in_array($in['contract_status'] ?? '', CONTRACT_STATES, true)
            ? $in['contract_status'] : 'none';
        $asDate = function ($v) {
            $v = trim((string)$v);
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        };
        $cSent   = $asDate($in['contract_sent_date'] ?? '');
        $cSigned = $asDate($in['contract_signed_date'] ?? '');

        // "Signed" with no date is a fact nobody can check later, and a date
        // with a contradicting status is worse than either alone. The same rule
        // save_invoice() applies to paid_date, for the same reason.
        if ($cStatus === 'signed' && $cSigned === null) $cSigned = date('Y-m-d');
        if ($cSigned !== null && $cStatus !== 'signed') $cStatus = 'signed';
        if ($cStatus === 'sent' && $cSent === null)     $cSent = date('Y-m-d');
    }

    // The column list is built rather than written out twice, because the
    // alternative is splicing placeholders into a SQL string by offset — which
    // is how a mismatched quote took includes/finance.php down on 18 August.
    $cols = ['crew_id', 'crew_name', 'day_rate', 'rate_unit', 'rate_currency', 'contract_url'];
    $args = [$crewId, $crewName, $rateVal, $unit, $cur, $url !== '' ? $url : null];
    if ($hasContract) {
        $cols = array_merge($cols, ['contract_status', 'contract_sent_date', 'contract_signed_date']);
        $args = array_merge($args, [$cStatus, $cSent, $cSigned]);
    }

    $update = [];
    foreach ($cols as $c) {
        if ($c !== 'crew_id') $update[] = "{$c} = VALUES({$c})";
    }
    $update[] = 'updated_by = VALUES(updated_by)';

    $db->prepare(
        'INSERT INTO crew_terms (' . implode(', ', $cols) . ', updated_by) VALUES ('
        . implode(', ', array_fill(0, count($cols) + 1, '?'))
        . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $update)
    )->execute(array_merge($args, [$who]));

    // Append-only history. Who changed a rate and when is the one question this
    // kind of data reliably attracts, and it cannot be reconstructed afterwards.
    // The log mirrors the columns above, so history covers the contract too — a
    // trail that recorded a change and lost what it changed to would be worse
    // than none, because it would look complete.
    $logCols = ['crew_id', 'crew_name', 'day_rate', 'rate_unit', 'rate_currency', 'contract_url'];
    $logArgs = [$crewId, $crewName, $rateVal, $unit, $cur, $url !== '' ? $url : null];
    if (finance_column_exists('crew_terms_log', 'contract_status')) {
        $logCols = array_merge($logCols, ['contract_status', 'contract_sent_date', 'contract_signed_date']);
        $logArgs = array_merge($logArgs, [$cStatus, $cSent, $cSigned]);
    }
    $db->prepare(
        'INSERT INTO crew_terms_log (' . implode(', ', $logCols) . ', changed_by) VALUES ('
        . implode(', ', array_fill(0, count($logCols) + 1, '?')) . ')'
    )->execute(array_merge($logArgs, [$who]));

    $said = $rateVal === null
        ? 'Terms saved — no rate recorded.'
        : 'Terms saved: ' . fmt_money($rateVal, $unit, $cur) . '.';
    if ($hasContract && $cStatus !== 'none') {
        $said .= ' Contract ' . contract_state_phrase($cStatus, $cSent, $cSigned) . '.';
    }
    return $said;
}

/**
 * The contract states, in the order a contract passes through them.
 *
 * `declined` is deliberately not the same as `none`: one is a hole in the plan
 * somebody has to fill, the other is the ordinary state of most of a project.
 */
/**
 * The units a rate can be quoted in.
 *
 * 'second' is for footage-based steps — rough animation, tie-down, cleanup — where
 * the deal is per second of screen time rather than per day. Needs
 * finance_install_06.sql; a per-second rate saved without it is rejected by the
 * ENUM rather than stored wrong.
 */
const RATE_UNITS = ['day', 'hour', 'flat', 'second'];

const CONTRACT_STATES = ['none', 'draft', 'sent', 'signed', 'declined'];

/** "signed on 2026-09-03", "out for signature since 2026-08-20", "not drafted yet". */
function contract_state_phrase(?string $status, ?string $sent = null, ?string $signed = null): string {
    switch ($status) {
        case 'signed':   return $signed ? 'signed on ' . $signed : 'signed';
        case 'sent':     return $sent ? 'out for signature since ' . $sent : 'out for signature';
        case 'draft':    return 'drafted, not sent';
        case 'declined': return 'declined';
        default:         return 'not drafted yet';
    }
}

/**
 * How long a contract has been sitting with somebody, in days. Null when it is
 * not out, or when nobody recorded the date it went.
 *
 * This is the number that actually chases a contract, and it is why the sent
 * date is a DATE and not a boolean.
 */
function contract_days_waiting(array $terms): ?int {
    if (($terms['contract_status'] ?? '') !== 'sent') return null;
    $sent = $terms['contract_sent_date'] ?? null;
    if (!$sent) return null;
    $days = (int)floor((strtotime('today') - strtotime($sent)) / 86400);
    return $days >= 0 ? $days : null;
}

/** Has finance_install_04.sql been imported? Contract state is unavailable without it. */
function contracts_need_upgrade(): bool {
    if (!can_see_money() || !finance_configured()) return false;
    return !finance_column_exists('crew_terms', 'contract_status');
}

/**
 * Move a contract along — drafted, sent, signed — WITHOUT touching the rate.
 *
 * ⚠ THIS EXISTS BECAUSE save_crew_terms() WOULD DESTROY THE RATE.
 * That function writes the whole row: it takes a rate out of the form, and a
 * form that carries only contract fields hands it an empty one, which it
 * faithfully stores as NULL. Chasing a signature would silently unprice
 * somebody, and the expense projection would start reporting their days as
 * unpriced with nothing on screen to explain why. A partial write needs its own
 * function; reusing the full one and hoping every caller sends every field is
 * the shape of bug this codebase keeps writing rules against.
 *
 * @return string a sentence for the flash message
 */
function set_contract_state(int $crewId, string $crewName, string $status,
                            ?string $sent = null, ?string $signed = null, string $who = ''): string {
    if (!can_see_money()) throw new RuntimeException('Only an administrator can change a contract.');
    $db = finance_db();
    if (!$db) throw new RuntimeException('The finance database is not set up yet — see finance_install.sql.');
    if (!finance_column_exists('crew_terms', 'contract_status')) {
        throw new RuntimeException(
            'Contract tracking needs finance_install_04.sql, which has not been imported into the '
            . 'finance database yet.'
        );
    }
    if (!in_array($status, CONTRACT_STATES, true)) {
        throw new RuntimeException('"' . $status . '" is not a contract state.');
    }

    $asDate = function ($v) {
        $v = trim((string)$v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    };
    $sent   = $asDate($sent);
    $signed = $asDate($signed);

    // The existing row is read first for two reasons: the dates already on file
    // are kept when this call does not carry them, and the log line needs the
    // CURRENT rate so the history stays a complete picture of the row rather
    // than a column of nulls beside a status.
    $existing = crew_term($crewId) ?? [];
    if ($sent === null)   $sent   = $existing['contract_sent_date'] ?? null;
    if ($signed === null) $signed = $existing['contract_signed_date'] ?? null;

    // Same two corrections save_crew_terms() applies, kept identical on purpose:
    // a state and its date must never contradict each other.
    if ($status === 'signed' && $signed === null) $signed = date('Y-m-d');
    if ($status === 'sent'   && $sent === null)   $sent   = date('Y-m-d');
    if ($status === 'none')  { $sent = null; $signed = null; }

    $db->prepare(
        'INSERT INTO crew_terms (crew_id, crew_name, contract_status, contract_sent_date,
                                 contract_signed_date, updated_by)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             crew_name = VALUES(crew_name),
             contract_status = VALUES(contract_status),
             contract_sent_date = VALUES(contract_sent_date),
             contract_signed_date = VALUES(contract_signed_date),
             updated_by = VALUES(updated_by)'
    )->execute([$crewId, $crewName, $status, $sent, $signed, $who]);

    if (finance_column_exists('crew_terms_log', 'contract_status')) {
        $db->prepare(
            'INSERT INTO crew_terms_log (crew_id, crew_name, day_rate, rate_unit, rate_currency,
                                         contract_url, contract_status, contract_sent_date,
                                         contract_signed_date, changed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $crewId, $crewName,
            $existing['day_rate'] ?? null, $existing['rate_unit'] ?? null,
            $existing['rate_currency'] ?? null, $existing['contract_url'] ?? null,
            $status, $sent, $signed, $who,
        ]);
    }

    return $crewName . ': contract ' . contract_state_phrase($status, $sent, $signed) . '.';
}

/**
 * Contract state for everyone on a project, for the "who hasn't signed" list.
 *
 * Returns rows keyed by crew id. A person with NO terms row at all is absent
 * from the result rather than present with a null — the caller knows who is on
 * the project and treats a missing key as "nothing on file", which is a
 * different and more honest statement than "status: none".
 *
 * @param int[] $crewIds
 */
function contract_states(array $crewIds): array {
    if (!can_see_money() || !$crewIds) return [];
    $db = finance_db();
    if (!$db || !finance_column_exists('crew_terms', 'contract_status')) return [];
    $ids = implode(',', array_map('intval', $crewIds));
    $out = [];
    foreach ($db->query(
        "SELECT crew_id, crew_name, day_rate, rate_unit, rate_currency, contract_url,
                contract_status, contract_sent_date, contract_signed_date
           FROM crew_terms WHERE crew_id IN ({$ids})"
    ) as $r) {
        $out[(int)$r['crew_id']] = $r;
    }
    return $out;
}

/* ---------------------------------------------------------------------------
 * HIRING QUOTES — what a candidate asked for, before they are anybody's crew.
 *
 * The conversation itself lives in the PRODUCTION database (`hires`, migration
 * 024) where any editor can see that a slot has somebody lined up. Only the
 * number is here. That is the crew / crew_terms split applied one stage earlier,
 * and it is David's call of 2026-08-17.
 *
 * Keyed on `hires.id`, with no foreign key — different database, different user.
 * The id-stability rule that protects crew_terms.crew_id protects this too.
 *
 * ⚠ THE RULE CHANGED ON 2026-08-31, and this comment used to state the old one:
 * that the application never deletes a `hires` row at all. It does now —
 * hiring.php can remove a candidate entered by mistake — so the rule is the
 * narrower and more honest version: a `hires` row is never deleted WITHOUT
 * delete_hire_quote() removing its quote in the same operation. Left behind, the
 * quote outlives the person it was for, keyed to an id a later candidate could
 * be handed. That is why the delete is admin-only: can_see_money() IS is_admin(),
 * so an editor could not clear the half they cannot see.
 * ------------------------------------------------------------------------- */

/** Is the hiring-quote table there at all? */
function hire_quotes_available(): bool {
    return finance_column_exists('hire_quotes', 'hire_id');
}

/**
 * Quotes for a set of hire ids, keyed by hire id. Empty for a non-admin.
 *
 * @param int[] $hireIds
 */
function hire_quotes(array $hireIds): array {
    if (!can_see_money() || !$hireIds) return [];
    $db = finance_db();
    if (!$db || !hire_quotes_available()) return [];

    // Inlined after (int), for the reason crew_terms() inlines its ids: a
    // variable-length IN list cannot be bound as a single parameter.
    $ids = implode(',', array_map('intval', $hireIds));
    $out = [];
    foreach ($db->query("SELECT * FROM hire_quotes WHERE hire_id IN ({$ids})") as $r) {
        $out[(int)$r['hire_id']] = $r;
    }
    return $out;
}

/** One candidate's quote, or null. */
function hire_quote(int $hireId): ?array {
    return hire_quotes([$hireId])[$hireId] ?? null;
}

/**
 * Remove a candidate's quote, for when the candidate record itself is going.
 *
 * hire_quotes.hire_id points at hires.id in the PRODUCTION database with no
 * foreign key — the two live on separate connections and neither can cascade
 * into the other. So deleting a candidate has to come through here as well, or
 * the quote outlives the person it was for, keyed to an id that a later
 * candidate could be handed.
 *
 * Returns the number of rows removed: 0 is a perfectly good answer, meaning
 * nobody ever recorded a rate for them.
 */
function delete_hire_quote(int $hireId): int {
    if (!can_see_money()) {
        throw new RuntimeException('Only an administrator can change what a candidate quoted.');
    }
    $db = finance_db();
    if (!$db || !hire_quotes_available()) return 0;
    $q = $db->prepare('DELETE FROM hire_quotes WHERE hire_id = ?');
    $q->execute([$hireId]);
    return $q->rowCount();
}

/**
 * Save what a candidate quoted. Returns a sentence for the flash message.
 *
 * @throws RuntimeException when the caller may not do this, or the input is bad
 */
function save_hire_quote(int $hireId, string $candidateName, ?int $projectId, array $in, string $who = ''): string {
    if (!can_see_money()) {
        throw new RuntimeException('Only an administrator can record what somebody quoted.');
    }
    $db = finance_db();
    if (!$db) {
        throw new RuntimeException(
            'The finance database is not set up yet, so there is nowhere to save this. '
            . 'See finance_install.sql.'
        );
    }
    if (!hire_quotes_available()) {
        throw new RuntimeException(
            'Quotes need finance_install_04.sql, which has not been imported into the finance '
            . 'database yet. The hiring record itself saved fine — only the money did not.'
        );
    }

    $num = function ($raw, string $label) {
        $raw = trim((string)$raw);
        if ($raw === '') return null;
        // Accept "1,200.00" and "$1200" — people paste from an email.
        $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $raw));
        if ($clean === '' || !is_numeric($clean)) {
            throw new RuntimeException('"' . $raw . '" is not a number — nothing was saved.');
        }
        $v = round((float)$clean, 2);
        if ($v < 0) throw new RuntimeException('A ' . $label . ' cannot be negative.');
        return $v;
    };

    $quoted  = $num($in['quoted_rate'] ?? '', 'rate');
    $offered = $num($in['offered_rate'] ?? '', 'rate');

    $unit = in_array($in['rate_unit'] ?? '', RATE_UNITS, true) ? $in['rate_unit'] : 'day';
    $cur  = strtoupper(trim((string)($in['rate_currency'] ?? 'USD')));
    if (!preg_match('/^[A-Z]{3}$/', $cur)) $cur = 'USD';

    $db->prepare(
        'INSERT INTO hire_quotes
           (hire_id, candidate_name, project_id, quoted_rate, rate_unit, rate_currency,
            offered_rate, notes, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            candidate_name = VALUES(candidate_name), project_id = VALUES(project_id),
            quoted_rate = VALUES(quoted_rate), rate_unit = VALUES(rate_unit),
            rate_currency = VALUES(rate_currency), offered_rate = VALUES(offered_rate),
            notes = VALUES(notes), updated_by = VALUES(updated_by)'
    )->execute([
        $hireId, $candidateName, $projectId, $quoted, $unit, $cur, $offered,
        trim((string)($in['notes'] ?? '')) ?: null, $who,
    ]);

    return $quoted === null
        ? 'Saved — no rate quoted yet.'
        : 'Quote saved: ' . fmt_money($quoted, $unit, $cur) . '.';
}

/**
 * On hiring somebody, carry their quoted rate across into their pay terms.
 *
 * THIS IS THE LOOP THE HIRING PHASE EXISTS TO CLOSE. The expense projection
 * reports unfilled slots as "unpriced days" rather than as free; agreeing a rate
 * during hiring is precisely the fact that prices them. Making somebody retype
 * it on the person page would guarantee the two numbers eventually disagree.
 *
 * IT NEVER OVERWRITES A RATE ALREADY ON FILE. Somebody who has worked on three
 * shows has terms that were negotiated, and a quote from one job must not
 * silently replace them. When there is already a rate, this does nothing and
 * says so — the caller shows that sentence.
 *
 * @return string|null a sentence for the flash message, or null when nothing was carried
 */
function seed_terms_from_quote(int $hireId, int $crewId, string $crewName, string $who = ''): ?string {
    if (!can_see_money()) return null;
    $db = finance_db();
    if (!$db || !hire_quotes_available()) return null;

    $quote = hire_quote($hireId);
    if (!$quote || $quote['quoted_rate'] === null) return null;

    $existing = crew_term($crewId);
    if ($existing && $existing['day_rate'] !== null) {
        return sprintf(
            '%s already has a rate on file (%s), so the quoted %s was left alone rather than '
            . 'overwriting it. Change it on their person page if the new job is at a different rate.',
            $crewName,
            fmt_money((float)$existing['day_rate'], $existing['rate_unit'] ?: 'day',
                      $existing['rate_currency'] ?: 'USD'),
            fmt_money((float)$quote['quoted_rate'], $quote['rate_unit'] ?: 'day',
                      $quote['rate_currency'] ?: 'USD')
        );
    }

    // Everything else on the terms row is preserved: this fills in a rate, it
    // does not reset a contract that somebody has already sent.
    save_crew_terms($crewId, $crewName, [
        'day_rate'             => (string)$quote['quoted_rate'],
        'rate_unit'            => $quote['rate_unit'] ?: 'day',
        'rate_currency'        => $quote['rate_currency'] ?: 'USD',
        'contract_url'         => $existing['contract_url'] ?? '',
        'contract_status'      => $existing['contract_status'] ?? 'none',
        'contract_sent_date'   => $existing['contract_sent_date'] ?? '',
        'contract_signed_date' => $existing['contract_signed_date'] ?? '',
    ], $who);

    return sprintf(
        'The rate agreed during hiring (%s) is now on file, so the positions they hold stop '
        . 'counting as unpriced on the expense projection.',
        fmt_money((float)$quote['quoted_rate'], $quote['rate_unit'] ?: 'day',
                  $quote['rate_currency'] ?: 'USD')
    );
}

/** Recent changes to one person's terms. Empty for a non-admin. */
function crew_terms_history(int $crewId, int $limit = 10): array {
    if (!can_see_money()) return [];
    $db = finance_db();
    if (!$db) return [];
    $limit = max(1, min(50, $limit));
    $q = $db->prepare(
        "SELECT * FROM crew_terms_log WHERE crew_id = ? ORDER BY changed_at DESC LIMIT {$limit}"
    );
    $q->execute([$crewId]);
    return $q->fetchAll();
}

/**
 * "1,200.00 USD · per day".
 *
 * NOT fmt_rate() — quota.php already has one of those for a WORK rate (items per
 * day), and defining a second fataled every page that loaded both. Different
 * meanings deserve different names even when the English word is the same.
 * (START_HERE, lesson 13.)
 */
function fmt_money(?float $rate, string $unit = 'day', string $currency = 'USD'): string {
    if ($rate === null) return '—';
    $per = $unit === 'flat' ? 'flat fee' : 'per ' . $unit;
    return number_format($rate, 2) . ' ' . $currency . ' · ' . $per;
}
