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
    if (!can_see_money()) return null;
    return finance_connection();
}

/**
 * The connection itself, with NO permission check. Called from exactly two
 * places: finance_db() above, after its admin check, and money_own_db() below,
 * after checking the asker is the person whose record it reads. Nothing else
 * may call it — that is what rules 1 and 2 at the top of this file mean now.
 */
function finance_connection(): ?PDO {
    static $pdo = null;
    static $tried = false;
    global $config;

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
            . 'unavailable until that is fixed, this is not the same as nobody having a rate. '
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
    foreach ($rows as $r) $out[(int)$r['crew_id']] = terms_from_salary($r);
    return $out;
}

/* ---------------------------------------------------------------------------
 * A SALARY, AND THE DAY RATE WORKED OUT FROM IT (David, 2026-11-11, for
 * Junior: "a place to put his monthly salary so we can calculate his daily
 * rate here ... Daily rate cost should be calculated on 21 days per month").
 *
 * crew_terms.monthly_salary (finance_install_17). When it is set, the day rate
 * IS the salary ÷ 21, per day, in the terms' currency — whatever day_rate and
 * rate_unit say. Applied here, on every read of the terms, so the expense
 * projection, the statements and the invoices all price the day the same way
 * without knowing a salary exists; and on every save (save_crew_terms()), so
 * the stored day_rate agrees for anything that reads the column raw.
 *
 * He chose to store the person's own salary over one shared figure for all
 * salaried staff (2026-11-11), replacing the 2026-10-28 rule that no personal
 * salary is stored. Admin-only, like every rate: it is in the finance
 * database, read through the same doors.
 * ------------------------------------------------------------------------ */

/** Working days a monthly salary is spread over to make a day rate. */
const SALARY_DAYS_PER_MONTH = 21;

/**
 * A terms row with the salary's day rate applied: day_rate = salary ÷ 21,
 * rate_unit 'day', and 'from_salary' => true so a screen can say so and not
 * offer the rate for editing. Unchanged when there is no salary.
 */
function terms_from_salary(array $r): array {
    $s = $r['monthly_salary'] ?? null;
    if ($s !== null && $s !== '' && (float)$s > 0) {
        $r['day_rate'] = round((float)$s / SALARY_DAYS_PER_MONTH, 2);
        $r['rate_unit'] = 'day';
        $r['from_salary'] = true;
    }
    return $r;
}

/** Has finance_install_17 been imported? Salaries cannot be stored without it. */
function salary_available(): bool {
    return finance_column_exists('crew_terms', 'monthly_salary');
}

/** One person's terms, or null. */
function crew_term(int $crewId): ?array {
    return crew_terms([$crewId])[$crewId] ?? null;
}

/* ---------------------------------------------------------------------------
 * YOUR OWN, AND NOBODY ELSE'S (David, 2026-10-26).
 *
 * The artist statement: "Artists see it". An artist sees what Anima says they
 * should invoice, which needs THEIR rate and THEIR invoices — and nothing about
 * anybody else. So these are the one exception to rule 2, and it is narrow: an
 * administrator gets any record (as everywhere in this file); anybody else gets
 * a record only when its crew id is the one their own login is linked to. A
 * caller passing somebody else's id gets nothing, exactly as a non-admin gets
 * nothing from crew_terms().
 * ------------------------------------------------------------------------ */

/** The finance connection, for an admin or for the person the record belongs to. */
function money_own_db(int $crewId): ?PDO {
    if (can_see_money()) return finance_db();
    // ⚠ NOT THROUGH SOMEBODY ELSE'S EYES. While Production views the app as an
    // artist (2026-11-05, migration 077), effective_crew_id() IS that artist, and
    // without this their rate and invoices would show on Home and the statement.
    // Only an administrator may see money through a preview. Admins already had
    // this door through effective_crew_id(), so nothing changes for them.
    if (function_exists('in_preview') && in_preview() && real_role() !== 'admin') return null;
    $pdo = $GLOBALS['pdo'] ?? null;
    if ($crewId <= 0 || !($pdo instanceof PDO)) return null;
    if (!function_exists('effective_crew_id') || effective_crew_id($pdo) !== $crewId) return null;
    return finance_connection();
}

/** A person's own pay terms; null for anybody else's (unless admin). */
function own_crew_term(int $crewId): ?array {
    $db = money_own_db($crewId);
    if (!$db) return null;
    $q = $db->prepare('SELECT * FROM crew_terms WHERE crew_id = ?');
    $q->execute([$crewId]);
    $r = $q->fetch();
    return $r ? terms_from_salary($r) : null;
}

/** A person's own invoices on one project, newest first; empty for anybody else's. */
function own_invoices(int $crewId, int $projectId): array {
    $db = money_own_db($crewId);
    if (!$db) return [];
    try {
        $q = $db->prepare('SELECT * FROM invoices WHERE crew_id = ? AND project_id = ?
                            ORDER BY issued_date DESC, id DESC');
        $q->execute([$crewId, $projectId]);
        return $q->fetchAll();
    } catch (PDOException $e) {
        return [];      // before finance_install_02
    }
}

/**
 * A person's own invoices on EVERY project, newest first; empty for anybody
 * else's. For the artist Home page (David, 2026-10-28: "which past invoices
 * were received and which paid"), which lists them across the shows instead of
 * one show at a time. Same door as own_invoices(), so the same narrow rule.
 */
function own_invoices_all(int $crewId, int $limit = 24): array {
    $db = money_own_db($crewId);
    if (!$db) return [];
    $limit = max(1, min(200, $limit));
    try {
        $q = $db->prepare("SELECT * FROM invoices WHERE crew_id = ?
                            ORDER BY COALESCE(issued_date, period_end, period_start) DESC, id DESC
                            LIMIT {$limit}");
        $q->execute([$crewId]);
        return $q->fetchAll();
    } catch (PDOException $e) {
        return [];      // before finance_install_02
    }
}

/* ---------------------------------------------------------------------------
 * A CONTRACT PER POSITION (David, 2026-10-29; finance_install_13).
 *
 * "For Angelys she should be able to see two different contracts one for CH
 * layout and other for the rest." crew_terms stays the person's DEFAULT
 * contract; a position_terms row is an exception for ONE position on ONE show,
 * keyed (crew_id, project_id, step_id), step_id being a pipeline step or MINUS
 * a supervision position (the invoices convention, DECISIONS 2026-10-26).
 *
 * Every reader asks term_for_position(): the position's own row when there is
 * one, otherwise the person's default. So a person with no exceptions is priced
 * exactly as before, and nothing has to know the table exists to keep working.
 * ------------------------------------------------------------------------ */

/* --------------------------------------------------------------------------
 * FINISHED POSITIONS (finance_install_14, David 2026-11-04): "a check mark to
 * state if the artist or account has already finished worked and so we can say
 * the difference between actual vs projected is final". A row means finished.
 * ------------------------------------------------------------------------ */

/** Has finance_install_14 been imported? */
function position_done_available(): bool {
    return finance_column_exists('position_done', 'step_id');
}

/** "crewId|stepId" => ['done_by', 'done_at'] for one project; empty before 14. */
function position_done_for(int $projectId): array {
    if (!can_see_money()) return [];
    $db = finance_db();
    if (!$db) return [];
    try {
        $q = $db->prepare('SELECT crew_id, step_id, done_by, done_at FROM position_done WHERE project_id = ?');
        $q->execute([$projectId]);
    } catch (PDOException $e) {
        return [];      // before finance_install_14
    }
    $out = [];
    foreach ($q as $r) $out[(int)$r['crew_id'] . '|' . (int)$r['step_id']] = $r;
    return $out;
}

/** Tick or untick one position, with a line in the log. Returns what to tell the user. */
function set_position_done(int $crewId, string $crewName, int $projectId, int $stepId,
                           string $posName, bool $done, string $who): string {
    if (!can_see_money()) throw new RuntimeException('Only admins can mark a position finished.');
    $db = finance_db();
    if (!$db || !position_done_available()) {
        throw new RuntimeException('Marking a position finished needs finance_install_14.sql, which has not '
            . 'been imported into the finance database yet. Nothing was saved.');
    }
    $db->beginTransaction();
    try {
        if ($done) {
            $db->prepare('INSERT INTO position_done (crew_id, crew_name, project_id, step_id, position_name, done_by)
                          VALUES (?, ?, ?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE crew_name = VALUES(crew_name), position_name = VALUES(position_name)')
               ->execute([$crewId, $crewName, $projectId, $stepId, $posName, $who]);
        } else {
            $db->prepare('DELETE FROM position_done WHERE crew_id = ? AND project_id = ? AND step_id = ?')
               ->execute([$crewId, $projectId, $stepId]);
        }
        $db->prepare('INSERT INTO position_done_log (crew_id, crew_name, project_id, step_id, position_name, done, changed_by)
                      VALUES (?, ?, ?, ?, ?, ?, ?)')
           ->execute([$crewId, $crewName, $projectId, $stepId, $posName, $done ? 1 : 0, $who]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return $crewName . ', ' . $posName . ($done ? ' marked finished: its difference is final.' : ' is no longer marked finished.');
}

/** Has finance_install_13 been imported? Admin view only (finance_column_exists() is). */
function position_terms_available(): bool {
    return finance_column_exists('position_terms', 'step_id');
}

/**
 * Every position contract on one project for a set of people, keyed
 * "crewId|stepId". Empty for a non-admin, and before finance_install_13.
 *
 * @param int[] $crewIds
 */
function position_terms_for(array $crewIds, int $projectId): array {
    if (!can_see_money() || !$crewIds) return [];
    $db = finance_db();
    if (!$db) return [];
    $ids = implode(',', array_map('intval', $crewIds));
    try {
        $q = $db->prepare("SELECT * FROM position_terms WHERE project_id = ? AND crew_id IN ({$ids})");
        $q->execute([$projectId]);
    } catch (PDOException $e) {
        return [];      // before finance_install_13
    }
    $out = [];
    foreach ($q as $r) $out[(int)$r['crew_id'] . '|' . (int)$r['step_id']] = $r;
    return $out;
}

/**
 * A person's OWN position contracts, [project id][step id] => row; empty for
 * anybody else's (unless admin). Same door as own_crew_term(). Optionally one
 * project only.
 */
function own_position_terms(int $crewId, ?int $projectId = null): array {
    $db = money_own_db($crewId);
    if (!$db) return [];
    try {
        $sql = 'SELECT * FROM position_terms WHERE crew_id = ?' . ($projectId ? ' AND project_id = ?' : '');
        $q = $db->prepare($sql);
        $q->execute($projectId ? [$crewId, $projectId] : [$crewId]);
    } catch (PDOException $e) {
        return [];      // before finance_install_13
    }
    $out = [];
    foreach ($q as $r) $out[(int)$r['project_id']][(int)$r['step_id']] = $r;
    return $out;
}

/**
 * The terms one position is paid on: its own contract when it has one,
 * otherwise the person's default. The row that comes back carries
 * `own_position` (true / false) so a screen can say which it is showing.
 *
 * @param array $byStep [step id => position_terms row] for this person and show
 */
function term_for_position(?array $default, array $byStep, int $stepId): ?array {
    if (isset($byStep[$stepId])) return $byStep[$stepId] + ['own_position' => true];
    return $default ? $default + ['own_position' => false] : null;
}

/**
 * Save one position's own contract. Admin only, like every write in this file.
 * The caller checks the position is this person's on this show; this checks
 * who is asking and what is being stored.
 */
function save_position_terms(int $crewId, string $crewName, int $projectId, int $stepId,
                             string $positionName, array $in, string $who = ''): string {
    if (!can_see_money()) {
        throw new RuntimeException('Only an administrator can change pay terms.');
    }
    $db = finance_db();
    if (!$db) throw new RuntimeException('The finance database is not set up yet, so there is nowhere to save this.');
    if (!position_terms_available()) {
        throw new RuntimeException('A contract per position needs finance_install_13.sql, imported by hand into '
            . 'the finance database. Nothing was saved.');
    }
    if ($crewId <= 0 || $projectId <= 0 || $stepId === 0) {
        throw new RuntimeException('That position could not be found, nothing was saved.');
    }

    [$rateVal, $unit, $cur, $url] = terms_input($in);
    [$cStatus, $cSent, $cSigned] = contract_input($in);
    $args = [$crewId, $crewName, $projectId, $stepId, mb_substr($positionName, 0, 150), $rateVal, $unit, $cur,
             $url !== '' ? $url : null, $cStatus, $cSent, $cSigned];

    $db->prepare(
        'INSERT INTO position_terms (crew_id, crew_name, project_id, step_id, position_name, day_rate,
                rate_unit, rate_currency, contract_url, contract_status, contract_sent_date,
                contract_signed_date, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE crew_name = VALUES(crew_name), position_name = VALUES(position_name),
                day_rate = VALUES(day_rate), rate_unit = VALUES(rate_unit),
                rate_currency = VALUES(rate_currency), contract_url = VALUES(contract_url),
                contract_status = VALUES(contract_status), contract_sent_date = VALUES(contract_sent_date),
                contract_signed_date = VALUES(contract_signed_date), updated_by = VALUES(updated_by)'
    )->execute(array_merge($args, [$who]));

    $db->prepare(
        'INSERT INTO position_terms_log (crew_id, crew_name, project_id, step_id, position_name, day_rate,
                rate_unit, rate_currency, contract_url, contract_status, contract_sent_date,
                contract_signed_date, removed, changed_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
    )->execute(array_merge($args, [$who]));

    $said = $positionName . ' has its own contract: '
          . ($rateVal === null ? 'no rate recorded' : fmt_money($rateVal, $unit, $cur)) . '.';
    if ($cStatus !== 'none') $said .= ' Contract ' . contract_state_phrase($cStatus, $cSent, $cSigned) . '.';
    return $said;
}

/** Take a position's own contract away, so it is paid on the person's default again. */
function remove_position_terms(int $crewId, string $crewName, int $projectId, int $stepId,
                               string $positionName, string $who = ''): string {
    if (!can_see_money()) {
        throw new RuntimeException('Only an administrator can change pay terms.');
    }
    $db = finance_db();
    if (!$db || !position_terms_available()) throw new RuntimeException('There is no position contract to remove.');
    $db->prepare('DELETE FROM position_terms WHERE crew_id = ? AND project_id = ? AND step_id = ?')
       ->execute([$crewId, $projectId, $stepId]);
    $db->prepare(
        'INSERT INTO position_terms_log (crew_id, crew_name, project_id, step_id, position_name, removed, changed_by)
         VALUES (?, ?, ?, ?, ?, 1, ?)'
    )->execute([$crewId, $crewName, $projectId, $stepId, mb_substr($positionName, 0, 150), $who]);
    return $positionName . ' is paid on ' . $crewName . "'s default contract again.";
}

/**
 * A person's own monthly invoices (IN4, finance_install_12), keyed by id; empty
 * for anybody else's. Home groups the invoice rows that point at one of these
 * (invoices.header_id) into a single invoice with its projects listed. Same
 * door as own_invoices(), so the same narrow rule, and the crew id is checked
 * again in the query so an id list can never reach somebody else's.
 */
function own_invoice_headers(int $crewId, array $ids): array {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    $db = money_own_db($crewId);
    if (!$db) return [];
    try {
        $q = $db->prepare('SELECT * FROM invoice_headers WHERE crew_id = ? AND id IN ('
                          . implode(',', $ids) . ')');
        $q->execute([$crewId]);
    } catch (PDOException $e) {
        return [];      // before finance_install_12
    }
    $out = [];
    foreach ($q as $h) $out[(int)$h['id']] = $h;
    return $out;
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

    // The rate and the contract are read the same way for a person's default
    // terms and for one position's own (position_terms, finance_install_13):
    // terms_input() and contract_input() below, so the two cannot drift.
    [$rateVal, $unit, $cur, $url] = terms_input($in);

    // Contract state, added by finance_install_04.sql. Guarded rather than
    // assumed: this app has to keep working against a finance database one file
    // behind the code, and a query naming a column that is not there is a fatal
    // that takes the whole page down — which is exactly how the Finance hub fell
    // over on invoices.step_id.
    $hasContract = finance_column_exists('crew_terms', 'contract_status');
    [$cStatus, $cSent, $cSigned] = $hasContract ? contract_input($in) : [null, null, null];

    // A MONTHLY SALARY (finance_install_17, 2026-11-11). Only when the form
    // sent the box: a caller that did not (the hiring quote, say) leaves the
    // stored salary as it is, because the column is then not written at all.
    // With a salary, the rate is the salary ÷ 21 per day, whatever was typed
    // or merged into day_rate (terms_from_salary() above).
    $salaryGiven = array_key_exists('monthly_salary', $in);
    $salary = null;
    if ($salaryGiven) {
        $raw = trim((string)$in['monthly_salary']);
        if ($raw !== '') {
            if (!salary_available()) {
                throw new RuntimeException('A monthly salary needs finance_install_17.sql imported into the finance '
                    . 'database (by hand, in phpMyAdmin). Nothing was saved.');
            }
            $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $raw));
            if ($clean === '' || !is_numeric($clean) || (float)$clean < 0) {
                throw new RuntimeException('"' . $raw . '" is not a salary, nothing was saved.');
            }
            $salary = round((float)$clean, 2);
            if ($salary <= 0) $salary = null;
        }
        if (!salary_available()) $salaryGiven = false;
    }
    if ($salary !== null) {
        $rateVal = round($salary / SALARY_DAYS_PER_MONTH, 2);
        $unit = 'day';
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
    if ($salaryGiven) { $cols[] = 'monthly_salary'; $args[] = $salary; }

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
    if ($salaryGiven && finance_column_exists('crew_terms_log', 'monthly_salary')) {
        $logCols[] = 'monthly_salary'; $logArgs[] = $salary;
    }
    $db->prepare(
        'INSERT INTO crew_terms_log (' . implode(', ', $logCols) . ', changed_by) VALUES ('
        . implode(', ', array_fill(0, count($logCols) + 1, '?')) . ')'
    )->execute(array_merge($logArgs, [$who]));

    $said = $rateVal === null
        ? 'Terms saved, no rate recorded.'
        : ($salary !== null
            ? 'Terms saved: a monthly salary of ' . number_format($salary, 2) . ' ' . $cur . ', so '
              . fmt_money($rateVal, 'day', $cur) . ' (÷ ' . SALARY_DAYS_PER_MONTH . ' days).'
            : 'Terms saved: ' . fmt_money($rateVal, $unit, $cur) . '.');
    if ($hasContract && $cStatus !== 'none') {
        $said .= ' Contract ' . contract_state_phrase($cStatus, $cSent, $cSigned) . '.';
    }
    return $said;
}

/**
 * The rate, unit, currency and contract link from a terms form, checked.
 * [rate or null, unit, currency, link ('' for none)]. Throws on anything that
 * would store a number nobody meant.
 */
function terms_input(array $in): array {
    $rate = trim((string)($in['day_rate'] ?? ''));
    if ($rate === '') {
        $rateVal = null;
    } else {
        // Accept "1,200.00" and "$1200" — people paste from a spreadsheet.
        $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $rate));
        if ($clean === '' || !is_numeric($clean)) {
            throw new RuntimeException('"' . $rate . '" is not a number, nothing was saved.');
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
            throw new RuntimeException('A contract link must start with http:// or https://, nothing was saved.');
        }
        if (mb_strlen($url) > 500) throw new RuntimeException('That link is too long to store.');
    }
    return [$rateVal, $unit, $cur, $url];
}

/** The contract status and its two dates from a terms form. [status, sent, signed] */
function contract_input(array $in): array {
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
    return [$cStatus, $cSent, $cSigned];
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
    if (!$db) throw new RuntimeException('The finance database is not set up yet, see finance_install.sql.');
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
            . 'database yet. The hiring record itself saved fine, only the money did not.'
        );
    }

    $num = function ($raw, string $label) {
        $raw = trim((string)$raw);
        if ($raw === '') return null;
        // Accept "1,200.00" and "$1200" — people paste from an email.
        $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $raw));
        if ($clean === '' || !is_numeric($clean)) {
            throw new RuntimeException('"' . $raw . '" is not a number, nothing was saved.');
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
        ? 'Saved, no rate quoted yet.'
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

/* ===========================================================================
 * FINANCE DATABASE UPDATES: finance_install_NN.sql through migrate.php
 * ===========================================================================
 *
 * David, 2026-11-11: "why do I need to paste so many codes to the DB when we
 * used to do it through the migration system?" Until then every change to the
 * finance database was a finance_install_NN.sql file David pasted into
 * phpMyAdmin by hand, because migrate.php only knows the production
 * connection. That connection has no rights here (the top of this file), and
 * rule 1 says nothing outside this file opens this one, so the runner lives
 * HERE and migrate.php only asks it questions.
 *
 * WHAT IS RUN: finance_install_02.sql upward, in number order. The bare
 * finance_install.sql is the baseline that creates the database's first
 * tables; it belongs to setting finance up at all (a new database, a new
 * user, the config block) and stays a by-hand step.
 *
 * HOW IT KNOWS WHAT IS DONE: a small `finance_migrations` table in the finance
 * database, one row per file, like schema_migrations on the production side.
 * It did not exist while 02 to 17 were being pasted in, so every file also has
 * a SCHEMA CHECK below: the tables and columns it adds. A file whose every
 * check is already true was imported by hand; it is recorded as "found already
 * in place" and NEVER run again. That matters: most of these files ADD COLUMN,
 * which fails a second time, and 09 rewrites invoice statuses.
 *
 * A file where SOME checks pass and others do not was half-imported. It is not
 * run (it might fail, or run its data steps twice) and nothing after it runs
 * either, because the files build on each other. The page shows what is
 * missing and offers "mark as done" for when David knows better.
 *
 * A NEW FILE (19 onward) needs an entry in finance_migration_checks() too. A
 * file without one is still run, but only the tracking table protects it from
 * running twice, and a copy pasted in by hand could never be recognised. Write
 * new files so a second run is harmless where you can (CREATE TABLE IF NOT
 * EXISTS), and add the check anyway.
 *
 * RIGHTS: the finance user may have been given only SELECT/INSERT/UPDATE/
 * DELETE. These files need CREATE, ALTER and INDEX. The rights are read BEFORE
 * anything runs (finance_schema_rights()), so a missing grant is a plain
 * message saying what to tick in cPanel, never a file left half-applied.
 */

/**
 * What each file adds, so a hand import can be recognised.
 *
 * 'table.column' means that column exists. 'table.column~word' means the
 * column's type lists word (an ENUM gaining a value); 'table.column!word'
 * means it must NOT (09 takes 'received' out of the invoice statuses).
 */
function finance_migration_checks(): array {
    return [
        'finance_install_02.sql' => ['project_budgets.project_id', 'invoices.id'],
        // 02 was later rewritten to include these two columns, so on a database
        // built from the newer 02 this file is "already in place" and never
        // runs, which is right: it would fail with "duplicate column".
        'finance_install_03.sql' => ['invoices.step_id', 'invoices.step_name'],
        'finance_install_04.sql' => ['hire_quotes.hire_id', 'crew_terms.contract_status',
                                     'crew_terms.contract_signed_date', 'crew_terms_log.contract_signed_date'],
        'finance_install_05.sql' => ['project_expenses.id'],
        'finance_install_06.sql' => ['crew_terms.rate_unit~second', 'hire_quotes.rate_unit~second'],
        'finance_install_07.sql' => ['project_budgets.revenue_amount'],
        'finance_install_08.sql' => ['kickoff_finance_snapshots.kickoff_id'],
        'finance_install_09.sql' => ['invoices.status~expected', 'invoices.status!received'],
        'finance_install_10.sql' => ['budget_changes.id'],
        'finance_install_11.sql' => ['project_fx_rates.project_id', 'project_fx_rates_log.id'],
        'finance_install_12.sql' => ['invoice_headers.id', 'invoices.header_id'],
        'finance_install_13.sql' => ['position_terms.id', 'position_terms_log.id'],
        'finance_install_14.sql' => ['position_done.id', 'position_done_log.id'],
        'finance_install_15.sql' => ['invoices.position_crew_id'],
        'finance_install_16.sql' => ['budget_requests.id'],
        'finance_install_17.sql' => ['crew_terms.monthly_salary', 'crew_terms_log.monthly_salary'],
        // The invoice template (PR #200), claimed while this was being built.
        'finance_install_18.sql' => ['invoice_details.crew_id'],
    ];
}

/** finance_install_02.sql … the highest, in number order. Names only. */
function finance_migration_files(): array {
    $names = [];
    foreach (glob(__DIR__ . '/../finance_install_*.sql') ?: [] as $f) {
        $n = basename($f);
        if (preg_match('/^finance_install_(\d+)\.sql$/', $n, $m) && (int)$m[1] >= 2) {
            $names[(int)$m[1]] = $n;
        }
    }
    ksort($names);
    return array_values($names);
}

/**
 * The files recorded in finance_migrations, as [filename => row]. Empty while
 * the table does not exist, which is the state before the first run.
 */
function finance_migrations_recorded(PDO $db): array {
    try {
        $rows = $db->query('SELECT filename, applied_at, applied_by, how FROM finance_migrations')->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) $out[$r['filename']] = $r;
    return $out;
}

/**
 * Every column of every table in the finance database, as
 * ['table.column' => lower-case column type]. ONE query rather than a SHOW
 * per table; DATABASE() is the finance database on this connection.
 */
function finance_schema_columns(PDO $db): array {
    $out = [];
    $q = $db->query(
        'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()'
    );
    foreach ($q->fetchAll() as $r) {
        $out[$r['TABLE_NAME'] . '.' . $r['COLUMN_NAME']] = strtolower((string)$r['COLUMN_TYPE']);
    }
    return $out;
}

/** Is one check true against the schema? See finance_migration_checks(). */
function finance_check_passes(string $check, array $cols): bool {
    if (preg_match('/^([A-Za-z0-9_]+\.[A-Za-z0-9_]+)([~!])(.+)$/', $check, $m)) {
        if (!isset($cols[$m[1]])) return false;
        $has = strpos($cols[$m[1]], "'" . strtolower($m[3]) . "'") !== false;
        return $m[2] === '~' ? $has : !$has;
    }
    return isset($cols[$check]);
}

/**
 * May the finance user change tables? true, false, or null when MySQL would
 * not say (then we just try, and a refusal is still reported in plain words).
 *
 * Read from SHOW GRANTS, which every user may run about themself. Lines look
 * like "GRANT SELECT, INSERT, ... ON `db`.* TO ..." or "GRANT ALL PRIVILEGES
 * ON ...". Asked BEFORE running anything: with CREATE but not ALTER, a file
 * like 04 would make its new table and then be refused on its first ALTER,
 * leaving it half-applied. Better not to start.
 */
function finance_schema_rights(PDO $db): ?bool {
    try {
        $lines = $db->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return null;
    }
    $have = [];
    foreach ($lines as $l) {
        if (!preg_match('/^GRANT\s+(.+?)\s+ON\s+/i', (string)$l, $m)) continue;
        if (stripos($m[1], 'ALL PRIVILEGES') !== false) return true;
        foreach (explode(',', $m[1]) as $p) $have[strtoupper(trim($p))] = true;
    }
    return isset($have['CREATE'], $have['ALTER'], $have['INDEX']);
}

/**
 * Where every finance update stands. Admin only: an empty array otherwise,
 * like everything in this file.
 *
 * Returns ['ok' => bool, 'error' => ?string, 'baseline' => bool,
 *          'rights' => ?bool, 'files' => [[name, state, detail], ...]].
 * state is one of:
 *   applied   recorded in finance_migrations
 *   found     not recorded, but everything it adds is already there
 *             (imported by hand): will be recorded, never run
 *   pending   nothing it adds is there yet: will be run
 *   partial   some of it is there and some not: blocked, needs a look
 */
function finance_migration_status(bool $withRights = true): array {
    if (!can_see_money()) return [];
    $res = ['ok' => false, 'error' => null, 'baseline' => false, 'rights' => null, 'files' => []];
    if (!finance_configured()) {
        $res['error'] = 'Finance is not set up on this site (no finance_db block in config.php), '
                      . 'so there is nothing to update.';
        return $res;
    }
    try {
        $db = finance_db();
        if (!$db) { $res['error'] = 'The finance database could not be opened.'; return $res; }
        $cols = finance_schema_columns($db);
        $recorded = finance_migrations_recorded($db);
        if ($withRights) $res['rights'] = finance_schema_rights($db);
    } catch (Throwable $e) {
        $res['error'] = $e->getMessage();
        return $res;
    }
    $res['ok'] = true;
    $res['baseline'] = isset($cols['crew_terms.crew_id']);

    $checks = finance_migration_checks();
    foreach (finance_migration_files() as $name) {
        if (isset($recorded[$name])) {
            $r = $recorded[$name];
            $how = ['ran' => 'Applied here', 'found' => 'Imported by hand earlier, found in place',
                    'marked' => 'Marked as done by hand'][$r['how']] ?? 'Applied';
            $res['files'][] = [$name, 'applied',
                $how . ' · ' . $r['applied_at'] . ($r['applied_by'] ? ' · ' . $r['applied_by'] : '')];
            continue;
        }
        $list = $checks[$name] ?? [];
        $missing = [];
        foreach ($list as $c) if (!finance_check_passes($c, $cols)) $missing[] = $c;
        if ($list && !$missing) {
            $res['files'][] = [$name, 'found', 'Already in the database (imported by hand). Will be recorded, not run'];
        } elseif (count($missing) === count($list)) {
            $res['files'][] = [$name, 'pending', 'Will be applied'];
        } else {
            $res['files'][] = [$name, 'partial',
                'Only partly in the database, missing ' . implode(', ', $missing)
                . '. Not run, so nothing is added twice. Ask Claude to look, or mark it as done if you know it is'];
        }
    }
    return $res;
}

/**
 * How many finance updates are waiting, for the banner in includes/header.php.
 *
 * That file runs on EVERY page, so this must be cheap and must never throw.
 * Cheap: 0 at once for anyone who is not an admin or when finance is not set
 * up, and the answer is kept in the session for ten minutes, so an admin
 * clicking around opens the finance database for this once every ten minutes,
 * not on every page; migrate.php forgets it after any change. Never throws:
 * any failure counts as 0. A banner is not the place to report a broken
 * finance connection; the finance screens already do that, loudly.
 */
function finance_pending_count(): int {
    try {
        if (!can_see_money() || !finance_configured()) return 0;
        $now = time();
        $c = $_SESSION['finance_pending'] ?? null;
        if (is_array($c) && isset($c['n'], $c['at']) && $now - (int)$c['at'] < 600) return (int)$c['n'];

        $n = 0;
        $st = finance_migration_status(false);
        if (!empty($st['ok'])) {
            foreach ($st['files'] as $f) if ($f[1] !== 'applied') $n++;
        }
        $_SESSION['finance_pending'] = ['n' => $n, 'at' => $now];
        return $n;
    } catch (Throwable $e) {
        return 0;
    }
}

/** Forget the banner's cached count (after migrate.php has changed things). */
function finance_pending_forget(): void {
    unset($_SESSION['finance_pending']);
}

/**
 * Was this MySQL saying "you are not allowed to"? 1142 table command denied,
 * 1044 access denied to the database, 1227 needs a privilege, 1143 column
 * command denied.
 */
function finance_is_rights_error(Throwable $e): bool {
    if ($e instanceof PDOException && isset($e->errorInfo[1])) {
        return in_array((int)$e->errorInfo[1], [1044, 1142, 1143, 1227], true);
    }
    return (bool)preg_match('/command denied|access denied/i', $e->getMessage());
}

/** The fix for missing rights, in plain words: which grant to add, and where. */
function finance_rights_message(): string {
    global $config;
    $f = $config['finance_db'] ?? [];
    $user = (string)($f['db_user'] ?? 'the finance user');
    $name = (string)($f['db_name'] ?? 'the finance database');
    return 'The finance database user (' . $user . ') may read and write data but not change tables, '
         . 'so Anima cannot apply these updates yet. To allow it: cPanel → MySQL Databases → '
         . '"Add User To Database", choose ' . $user . ' and ' . $name . ', tick CREATE, ALTER and INDEX '
         . '(or ALL PRIVILEGES), then Make Changes, and come back here.';
}

/** The tracking table, created on the first run. */
function finance_migrations_ensure_table(PDO $db): void {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS finance_migrations (
           filename VARCHAR(100) NOT NULL PRIMARY KEY,
           applied_at DATETIME NOT NULL,
           applied_by VARCHAR(100) DEFAULT NULL,
           how VARCHAR(10) NOT NULL DEFAULT 'ran'
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/** how: 'ran' (applied here), 'found' (hand import detected), 'marked' (David said so). */
function finance_migration_record(PDO $db, string $name, string $how, string $who): void {
    $db->prepare(
        'INSERT INTO finance_migrations (filename, applied_at, applied_by, how) VALUES (?, NOW(), ?, ?)
         ON DUPLICATE KEY UPDATE filename = filename'
    )->execute([$name, $who !== '' ? $who : null, $how]);
}

/**
 * Apply every waiting finance update, in order. Admin only.
 *
 *   found    recorded as 'found', not run
 *   pending  each statement run; recorded as 'ran' once ALL succeed
 *   partial  stops the run there: nothing after it is attempted
 *   a failed statement stops the run too, and says which statement
 *
 * Returns ['error' => ?string, 'files' => [[name, state, detail], ...]] with
 * state applied / recorded / skipped / failed / not attempted, the same words
 * as the production table on migrate.php. MySQL commits table changes at once,
 * so like the production runner a failed file can be left half-done; the
 * detail says which statement failed.
 *
 * $split is migrate.php's split_sql_statements(): one splitter for both
 * databases, so a file means the same thing to each.
 */
function finance_apply_migrations(callable $split, string $who): array {
    $out = ['error' => null, 'files' => []];
    if (!can_see_money()) { $out['error'] = 'Administrators only.'; return $out; }

    $st = finance_migration_status();
    if (empty($st['ok'])) { $out['error'] = $st['error'] ?? 'The finance database could not be read.'; return $out; }
    if (!$st['baseline']) {
        $out['error'] = 'The finance database does not have its first tables yet (finance_install.sql). '
                      . 'That one is part of setting finance up and is still imported by hand.';
        return $out;
    }
    // Anything to do at all needs the rights: even recording a hand import
    // needs the tracking table, and creating that needs CREATE.
    $todo = false;
    foreach ($st['files'] as $f) if ($f[1] !== 'applied') $todo = true;
    if ($todo && $st['rights'] === false) {
        $out['error'] = finance_rights_message();
        return $out;
    }
    $db = finance_db();

    try {
        finance_migrations_ensure_table($db);
    } catch (Throwable $e) {
        $out['error'] = finance_is_rights_error($e) ? finance_rights_message()
                      : 'Could not create the finance_migrations table: ' . $e->getMessage();
        return $out;
    }

    $stopped = false;
    foreach ($st['files'] as [$name, $state, $detail]) {
        if ($state === 'applied') { $out['files'][] = [$name, 'skipped', $detail]; continue; }
        if ($stopped) {
            $out['files'][] = [$name, 'not attempted', 'Skipped because an earlier update stopped the run'];
            continue;
        }
        if ($state === 'partial') {
            $stopped = true;
            $out['files'][] = [$name, 'failed', $detail];
            continue;
        }
        try {
            if ($state === 'found') {
                finance_migration_record($db, $name, 'found', $who);
                $out['files'][] = [$name, 'recorded', 'Already in the database (imported by hand). Recorded, not run'];
                continue;
            }
            $sql = @file_get_contents(__DIR__ . '/../' . $name);
            if ($sql === false) throw new RuntimeException('Could not read the file.');
            $stmts = $split($sql);
            foreach ($stmts as $i => $stmt) {
                try {
                    $db->exec($stmt);
                } catch (Throwable $e) {
                    if (finance_is_rights_error($e)) $out['error'] = finance_rights_message();
                    throw new RuntimeException('Statement ' . ($i + 1) . ' failed: ' . $e->getMessage(), 0, $e);
                }
            }
            finance_migration_record($db, $name, 'ran', $who);
            $out['files'][] = [$name, 'applied', count($stmts) . ' statement(s) executed'];
        } catch (Throwable $e) {
            $stopped = true;
            $out['files'][] = [$name, 'failed', $e->getMessage()];
        }
    }
    return $out;
}

/**
 * "This one is done, stop offering it": for a file the schema checks cannot
 * vouch for (a half-imported one David has since finished by hand, or a new
 * file with no check). Records it without running it.
 */
function finance_mark_migration_done(string $name, string $who): ?string {
    if (!can_see_money()) return 'Administrators only.';
    if (!in_array($name, finance_migration_files(), true)) return 'There is no such finance update file.';
    try {
        $db = finance_db();
        if (!$db) return 'The finance database could not be opened.';
        finance_migrations_ensure_table($db);
        finance_migration_record($db, $name, 'marked', $who);
    } catch (Throwable $e) {
        return finance_is_rights_error($e) ? finance_rights_message() : $e->getMessage();
    }
    return null;
}

/* --------------------------------------------------------------------------
 * WHO IS INVOICING, AND WHERE THE MONEY GOES (finance_install_18).
 *
 * David, 2026-11-11, the invoice template: Anima fills in each freelancer's
 * monthly invoice from their logged days (invoice.php). What it cannot know is
 * who they are on paper and their bank account, and asked whether to store
 * those or have the artist type them every month, he chose to store them.
 * So they are here, in the finance database, behind the same doors as a rate:
 * the person themselves (money_own_db) or an administrator, nobody else, and
 * never through somebody else's preview.
 *
 * The keys below are the table's columns AND the only fields a form may set:
 * save_own_invoice_details() writes nothing that is not in this list.
 * ------------------------------------------------------------------------ */

const INVOICE_DETAIL_FIELDS = [
    'legal_name'     => 'Legal name',
    'job_title'      => 'Job title',
    'email'          => 'Email',
    'address'        => 'Address',
    'tax_id'         => 'Tax ID',
    'bank_name'      => 'Bank name',
    'account_holder' => 'Account Holder Name',
    'account_number' => 'Account Number',
    'routing_number' => 'Routing Number',
    'swift_iban'     => 'SWIFT/IBAN Number',
    'clabe'          => 'CLABE (Mexico)',
    'cpf'            => 'CPF (Brazil)',
    'holder_address' => 'Address of Account Holder',
    'payment_notes'  => 'Payment notes',
];

/** The free-text fields, which keep their line breaks and may run longer. */
const INVOICE_DETAIL_LONG = ['address', 'holder_address', 'payment_notes'];

/**
 * A person's own invoice details. ['ready' => bool, 'row' => array].
 * ready is false when the door is shut (not theirs, not an admin, finance not
 * set up) or finance_install_18 is not imported yet; row is [] when nothing has
 * been saved. Never throws for a missing table: the invoice still prints.
 */
function own_invoice_details(int $crewId): array {
    $db = money_own_db($crewId);
    if (!$db) return ['ready' => false, 'row' => []];
    try {
        $q = $db->prepare('SELECT * FROM invoice_details WHERE crew_id = ?');
        $q->execute([$crewId]);
        return ['ready' => true, 'row' => $q->fetch() ?: []];
    } catch (PDOException $e) {
        return ['ready' => false, 'row' => []];     // before finance_install_18
    }
}

/**
 * Save a person's own invoice details (or anybody's, for an administrator).
 * The crew id is decided by the caller from the login, never from the form.
 */
function save_own_invoice_details(int $crewId, string $crewName, array $in, string $who = ''): string {
    $db = money_own_db($crewId);
    if (!$db) {
        throw new RuntimeException('These details can only be saved by the person themselves or an administrator, '
                                 . 'once the finance database is set up.');
    }
    $vals = [];
    foreach (INVOICE_DETAIL_FIELDS as $k => $label) {
        $v = trim(str_replace("\r\n", "\n", (string)($in[$k] ?? '')));
        if (!in_array($k, INVOICE_DETAIL_LONG, true)) $v = preg_replace('/\s+/', ' ', $v);
        $max = in_array($k, INVOICE_DETAIL_LONG, true) ? 1000 : ($k === 'tax_id' || $k === 'account_number'
               || $k === 'routing_number' || $k === 'swift_iban' || $k === 'clabe' || $k === 'cpf' ? 100 : 190);
        if (mb_strlen($v) > $max) throw new RuntimeException($label . ' is too long (' . $max . ' characters at most).');
        $vals[$k] = $v === '' ? null : $v;
    }
    if ($vals['email'] !== null && filter_var($vals['email'], FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('The email does not look like an email address.');
    }
    $cols = array_keys(INVOICE_DETAIL_FIELDS);
    $sql = 'INSERT INTO invoice_details (crew_id, crew_name, ' . implode(', ', $cols) . ', updated_by)
            VALUES (?, ?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', ?)
            ON DUPLICATE KEY UPDATE crew_name = VALUES(crew_name), '
         . implode(', ', array_map(fn($c) => "$c = VALUES($c)", $cols)) . ', updated_by = VALUES(updated_by)';
    try {
        $db->prepare($sql)->execute(array_merge([$crewId, mb_substr($crewName, 0, 100)], array_values($vals),
                                                [mb_substr($who, 0, 100)]));
    } catch (PDOException $e) {
        error_log('[Anima invoice details] ' . $e->getMessage());
        throw new RuntimeException('Your details could not be saved: the finance database needs update '
                                 . 'finance_install_18 first (migrate.php → Finance database updates). Ask David.');
    }
    return 'Invoice details saved.';
}
