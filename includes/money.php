<?php
/**
 * The ONLY place pay terms are read or written.
 *
 * Background, because this file exists as compensation for something lost.
 * docs/DECISIONS.md, 2026-08-12, kept rates out of this database entirely, so
 * that a separate MySQL user with no rights to those tables made a leak
 * impossible rather than merely unlikely. On 2026-08-15 David decided the rate
 * and the contract link should live here after all — his call, his studio's
 * data, made with that consequence stated.
 *
 * So the guarantee is now an application-level one, and the only way an
 * application-level guarantee holds is if there is exactly ONE door. Every read
 * and every write of crew.day_rate / rate_unit / rate_currency / contract_url
 * goes through this file. Nothing else in the codebase names those columns.
 *
 * That is the same shape as the read-only role enforcement in require_login():
 * one check that every screen passes through, including screens written later by
 * someone who never read this comment. A check per page fails the first time
 * somebody forgets one, and fails silently.
 *
 * `crew.department` is deliberately NOT handled here — it is not financial, and
 * routing it through a money guard would only make it look like it was.
 */

/** May the current account see pay terms at all? */
function can_see_money(): bool {
    return is_admin();
}

/** Are the columns actually there? They arrive in migration 018. */
function money_columns_exist(PDO $pdo): bool {
    return column_exists($pdo, 'crew', 'day_rate');
}

/**
 * Pay terms for a set of crew ids, keyed by crew id.
 *
 * Returns an EMPTY ARRAY for anyone who is not an admin — not a partial row, not
 * nulls. A caller that forgets to check simply gets nothing to render, which is
 * the failure mode you want: silence rather than a leak.
 *
 * @param int[] $crewIds
 */
function crew_terms(PDO $pdo, array $crewIds): array {
    if (!can_see_money() || !$crewIds || !money_columns_exist($pdo)) return [];

    // Ids are cast to int and inlined: a variable-length IN list cannot be bound
    // as one parameter, and building the placeholders is the same amount of code
    // with more ways to get it wrong. Every value here has been through (int).
    $ids = implode(',', array_map('intval', $crewIds));
    $rows = $pdo->query(
        "SELECT id, day_rate, rate_unit, rate_currency, contract_url
           FROM crew WHERE id IN ({$ids})"
    )->fetchAll();

    $out = [];
    foreach ($rows as $r) $out[(int)$r['id']] = $r;
    return $out;
}

/** One person's terms, or null. */
function crew_term(PDO $pdo, int $crewId): ?array {
    $all = crew_terms($pdo, [$crewId]);
    return $all[$crewId] ?? null;
}

/**
 * Save one person's terms. Refuses for anyone who is not an admin.
 *
 * @return string a sentence describing what happened, for the flash message
 * @throws RuntimeException when the caller may not do this, or the input is bad
 */
function save_crew_terms(PDO $pdo, int $crewId, array $in): string {
    if (!can_see_money()) {
        throw new RuntimeException('Only an administrator can change pay terms.');
    }
    if (!money_columns_exist($pdo)) {
        throw new RuntimeException('Pay terms need migration 018, which has not been applied yet.');
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
        // http/https only. A javascript: or data: URL here would be a link an
        // admin clicks from a page that already trusts them.
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('A contract link must start with http:// or https:// — nothing was saved.');
        }
        if (mb_strlen($url) > 500) throw new RuntimeException('That link is too long to store.');
    }

    $pdo->prepare(
        'UPDATE crew SET day_rate = ?, rate_unit = ?, rate_currency = ?, contract_url = ? WHERE id = ?'
    )->execute([$rateVal, $unit, $cur, $url !== '' ? $url : null, $crewId]);

    return $rateVal === null
        ? 'Terms saved — no rate recorded.'
        : 'Terms saved: ' . fmt_rate($rateVal, $unit, $cur) . '.';
}

/** "1,200.00 USD / day". Never called for a non-admin, because the data never arrives. */
function fmt_rate(?float $rate, string $unit = 'day', string $currency = 'USD'): string {
    if ($rate === null) return '—';
    $per = $unit === 'flat' ? 'flat fee' : 'per ' . $unit;
    return number_format($rate, 2) . ' ' . $currency . ' · ' . $per;
}
