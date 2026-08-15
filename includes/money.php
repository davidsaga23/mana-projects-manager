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

    $unit = in_array($in['rate_unit'] ?? '', ['day', 'hour', 'flat'], true) ? $in['rate_unit'] : 'day';

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

    $db->prepare(
        'INSERT INTO crew_terms (crew_id, crew_name, day_rate, rate_unit, rate_currency,
                                 contract_url, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             crew_name = VALUES(crew_name), day_rate = VALUES(day_rate),
             rate_unit = VALUES(rate_unit), rate_currency = VALUES(rate_currency),
             contract_url = VALUES(contract_url), updated_by = VALUES(updated_by)'
    )->execute([$crewId, $crewName, $rateVal, $unit, $cur, $url !== '' ? $url : null, $who]);

    // Append-only history. Who changed a rate and when is the one question this
    // kind of data reliably attracts, and it cannot be reconstructed afterwards.
    $db->prepare(
        'INSERT INTO crew_terms_log (crew_id, crew_name, day_rate, rate_unit, rate_currency,
                                     contract_url, changed_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$crewId, $crewName, $rateVal, $unit, $cur, $url !== '' ? $url : null, $who]);

    return $rateVal === null
        ? 'Terms saved — no rate recorded.'
        : 'Terms saved: ' . fmt_money($rateVal, $unit, $cur) . '.';
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
