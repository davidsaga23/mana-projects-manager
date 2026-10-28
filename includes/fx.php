<?php
/**
 * One exchange rate per project, for the rare contract that is not in US dollars.
 *
 * THE RULE (Tim and Jorik, 2026-10-28, DECISIONS): USD is the default for every
 * rate, bill and total, and almost every contract is in USD. A rare contract in
 * another currency (Lewis in £) is entered in that currency. Anima saves that
 * day's exchange rate when the project is set up / signed off, uses that ONE
 * fixed rate for the whole project, and shows both: "520.00 USD (400.00 GBP)".
 * No daily rates, no Wise fees, no small differences: the bank and the books
 * hold the exact amounts. This is for the budget, which needs one currency.
 *
 * WHAT IT REPLACES. Since 2026-08-18 a second currency was flagged and never
 * converted, because a rate nobody could name and date would be a guess. A rate
 * fixed per project, saved with its date and its source, is not a guess: it is
 * the studio's stated assumption for that show. A currency WITHOUT a fixed rate
 * still gets the old warning, unchanged.
 *
 * WHERE THE RATE COMES FROM. The European Central Bank's daily reference rates,
 * through frankfurter (free, no key). Fetched server side, with a short timeout,
 * only at the two moments that fix a rate: when the project's Finance page first
 * meets a currency it has no rate for (set-up), and when the kick-off is sent for
 * sign-off. Never from includes/header.php, never on an ordinary page. If the
 * fetch fails the page says so and offers a box to type the rate by hand; it
 * never blocks anything, sign-off least of all.
 *
 * FINANCE DATABASE: project_fx_rates and its log (finance_install_11.sql,
 * imported by hand). Until then every function here returns "no rate", and the
 * screens behave exactly as they did before.
 */

require_once __DIR__ . '/money.php';

/** "GBP", or null for anything that is not a three-letter code. */
function fx_code($cur): ?string {
    $c = strtoupper(trim((string)$cur));
    return preg_match('/^[A-Z]{3}$/', $c) ? $c : null;
}

/** Has finance_install_11 been imported? Cached for the request. */
function fx_available(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    $db = finance_db();
    if (!$db) return $ok = false;
    try {
        $db->query('SELECT 1 FROM project_fx_rates LIMIT 0');
        return $ok = true;
    } catch (PDOException $e) {
        return $ok = false;
    }
}

/**
 * A project's fixed rates, keyed by currency. Empty for a non-admin, before
 * finance_install_11, or when the show is all in dollars.
 *
 * Cached per project for the request, because the projection, the cost to date
 * and the statements each ask; fx_forget() clears it after a save.
 */
function project_fx_rates(int $projectId, bool $forget = false): array {
    static $cache = [];
    if ($forget) { unset($cache[$projectId]); return []; }
    if (array_key_exists($projectId, $cache)) return $cache[$projectId];
    if (!fx_available()) return $cache[$projectId] = [];
    $q = finance_db()->prepare('SELECT * FROM project_fx_rates WHERE project_id = ?');
    $q->execute([$projectId]);
    $out = [];
    foreach ($q as $r) $out[$r['currency']] = $r;
    return $cache[$projectId] = $out;
}

function fx_forget(int $projectId): void {
    project_fx_rates($projectId, true);
}

/**
 * The same rates for an artist reading their own statement. A project's
 * exchange rate is nobody's pay, but the connection is opened through the same
 * door as their own terms (money_own_db()), so rule 1 of money.php holds.
 */
function own_project_fx_rates(int $crewId, int $projectId): array {
    if (can_see_money()) return project_fx_rates($projectId);
    $db = money_own_db($crewId);
    if (!$db) return [];
    try {
        $q = $db->prepare('SELECT * FROM project_fx_rates WHERE project_id = ?');
        $q->execute([$projectId]);
    } catch (PDOException $e) {
        return [];      // before finance_install_11
    }
    $out = [];
    foreach ($q as $r) $out[$r['currency']] = $r;
    return $out;
}

/**
 * An amount in US dollars, or null when it cannot be converted (a currency with
 * no fixed rate). USD comes back as it went in. Rounded to cents once, here,
 * the same rule as every cost in includes/finance.php.
 */
function fx_to_usd(?float $amount, $currency, array $rates): ?float {
    if ($amount === null) return null;
    $c = fx_code($currency) ?? 'USD';
    if ($c === 'USD') return $amount;
    if (!isset($rates[$c])) return null;
    return round($amount * (float)$rates[$c]['usd_per_unit'], 2);
}

/**
 * [amount, currency] in dollars when it can be converted, otherwise as it was.
 * For the totals kept per currency: a converted amount joins the USD pile, an
 * unconvertible one keeps its own and the screen still warns about it.
 */
function fx_usd_or_own(float $amount, $currency, array $rates): array {
    $c = fx_code($currency) ?? 'USD';
    $usd = fx_to_usd($amount, $c, $rates);
    return $usd === null ? [$amount, $c] : [$usd, 'USD'];
}

/** "520.00 USD (400.00 GBP)": both amounts, dollars first. */
function fmt_money_both(float $usd, ?float $own, ?string $ownCurrency): string {
    $s = number_format($usd, 2) . ' USD';
    if ($own !== null && $ownCurrency && $ownCurrency !== 'USD') {
        $s .= ' (' . number_format($own, 2) . ' ' . $ownCurrency . ')';
    }
    return $s;
}

/** "1 GBP = 1.3288 USD, rate of 2026-10-28, ECB via frankfurter". */
function fx_describe(array $row): string {
    return '1 ' . $row['currency'] . ' = ' . rtrim(rtrim(number_format((float)$row['usd_per_unit'], 6, '.', ''), '0'), '.')
         . ' USD, rate of ' . $row['rate_date'] . ', ' . $row['source'];
}

/* ---------------------------------------------------------------------------
 * FETCHING
 * ------------------------------------------------------------------------ */

/** A GET with a hard timeout. Null on any failure; never throws. */
function fx_http_get(string $url, int $timeout = 5): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => 'Anima (Mana Animation Studio)',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code === 200) ? (string)$body : null;
    }
    if (!ini_get('allow_url_fopen')) return null;
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => false,
                                             'header' => "User-Agent: Anima (Mana Animation Studio)\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? null : $body;
}

/**
 * Today's reference rate for one currency against the dollar, or null.
 *
 * ['usd_per_unit' => 1.3288, 'rate_date' => '2026-10-28', 'source' => 'ECB via frankfurter']
 *
 * The ECB publishes once a working day, so on a weekend the date is Friday's;
 * that date is what is saved, not today's. Two addresses for the same service
 * (it moved to frankfurter.dev in 2025 and the old one redirects), tried in turn.
 */
function fx_fetch(string $currency): ?array {
    $c = fx_code($currency);
    if ($c === null || $c === 'USD') return null;
    $urls = [
        'https://api.frankfurter.dev/v1/latest?base=' . $c . '&symbols=USD',
        'https://api.frankfurter.app/latest?from=' . $c . '&to=USD',
    ];
    foreach ($urls as $url) {
        $body = fx_http_get($url);
        if ($body === null) continue;
        $j = json_decode($body, true);
        $rate = $j['rates']['USD'] ?? null;
        $date = $j['date'] ?? null;
        if (is_numeric($rate) && (float)$rate > 0
            && is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['usd_per_unit' => (float)$rate, 'rate_date' => $date, 'source' => 'ECB via frankfurter'];
        }
    }
    return null;
}

/* ---------------------------------------------------------------------------
 * SAVING
 * ------------------------------------------------------------------------ */

/**
 * Fix (or replace) a project's rate for one currency. Admin only.
 *
 * ⚠ REPLACING NEEDS A REASON. A fixed rate that can move quietly is not fixed:
 * the old rate, the new one, who, when and why go into project_fx_rates_log,
 * the same record Jorik's budget changes keep (DECISIONS, 2026-10-28).
 */
function fx_save(int $projectId, $currency, float $usdPerUnit, string $rateDate, string $source,
                 string $who, string $reason = ''): string {
    if (!can_see_money()) throw new RuntimeException('Only an administrator can set an exchange rate.');
    if (!fx_available()) {
        throw new RuntimeException('Exchange rates need finance_install_11.sql imported into the finance '
                                 . 'database first. Nothing was saved.');
    }
    $c = fx_code($currency);
    if ($c === null || $c === 'USD') throw new RuntimeException('Choose a three-letter currency other than USD.');
    if (!($usdPerUnit > 0) || $usdPerUnit > 100000) {
        throw new RuntimeException('That rate is not a positive number, nothing was saved.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rateDate)) $rateDate = date('Y-m-d');
    $reason = mb_substr(trim($reason), 0, 255);

    $old = project_fx_rates($projectId)[$c] ?? null;
    if ($old !== null && $reason === '') {
        throw new RuntimeException('This project already has a fixed ' . $c . ' rate (' . fx_describe($old)
                                 . '). Say why it is changing; nothing was saved.');
    }

    $db  = finance_db();
    $now = date('Y-m-d H:i:s');
    $who = mb_substr($who, 0, 100);
    $db->beginTransaction();
    try {
        $db->prepare(
            'INSERT INTO project_fx_rates (project_id, currency, usd_per_unit, rate_date, source, set_at, set_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE usd_per_unit = VALUES(usd_per_unit), rate_date = VALUES(rate_date),
                                     source = VALUES(source), set_at = VALUES(set_at), set_by = VALUES(set_by)'
        )->execute([$projectId, $c, $usdPerUnit, $rateDate, $source, $now, $who]);
        $db->prepare(
            'INSERT INTO project_fx_rates_log (project_id, currency, old_usd_per_unit, new_usd_per_unit,
                                               rate_date, source, reason, changed_at, changed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$projectId, $c, $old['usd_per_unit'] ?? null, $usdPerUnit, $rateDate, $source,
                    $reason !== '' ? $reason : null, $now, $who]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    fx_forget($projectId);
    $row = ['currency' => $c, 'usd_per_unit' => $usdPerUnit, 'rate_date' => $rateDate, 'source' => $source];
    return ($old ? 'Exchange rate changed: ' : 'Exchange rate fixed for this project: ') . fx_describe($row) . '.';
}

/** The change history of a project's rates, newest first. */
function project_fx_log(int $projectId): array {
    if (!fx_available()) return [];
    $q = finance_db()->prepare('SELECT * FROM project_fx_rates_log WHERE project_id = ?
                                 ORDER BY changed_at DESC, id DESC');
    $q->execute([$projectId]);
    return $q->fetchAll();
}

/**
 * Fetch and fix a rate for every foreign currency that has none yet.
 * Returns sentences for the screen. NEVER THROWS: called on the way to a
 * sign-off and on the Finance page, neither of which may fail because a
 * currency service is down.
 *
 * A failed fetch is not retried on every page load: the session remembers it
 * for ten minutes, so an unreachable service costs one timeout, not one per
 * click. $force (the "fetch now" button, and sign-off) ignores that memory.
 */
function fx_fix_missing(int $projectId, array $currencies, string $who, bool $force = false): array {
    $msgs = [];
    try {
        if (!can_see_money() || !fx_available()) return [];
        $have = project_fx_rates($projectId);
        foreach (array_unique(array_filter(array_map('fx_code', $currencies))) as $c) {
            if ($c === 'USD' || isset($have[$c])) continue;
            $key = $projectId . '|' . $c;
            $failedAt = $_SESSION['fx_fetch_failed'][$key] ?? 0;
            if (!$force && $failedAt > time() - 600) continue;

            $got = fx_fetch($c);
            if ($got === null) {
                $_SESSION['fx_fetch_failed'][$key] = time();
                $msgs[] = 'Could not fetch today\'s ' . $c . ' exchange rate, so ' . $c
                        . ' amounts are not converted yet. Type the rate by hand under Exchange rates on the '
                        . 'Finance overview.';
                continue;
            }
            unset($_SESSION['fx_fetch_failed'][$key]);
            $msgs[] = fx_save($projectId, $c, $got['usd_per_unit'], $got['rate_date'], $got['source'], $who);
        }
    } catch (Throwable $e) {
        error_log('[Anima fx] ' . $e->getMessage());
        $msgs[] = 'The exchange rate could not be saved: ' . $e->getMessage();
    }
    return $msgs;
}

/**
 * Every foreign currency a project's costs are in: its rates and other
 * expenses (through the projection) and its invoices. The budget is left out:
 * it is approved in dollars, and a rate fetched for it would convert nothing.
 */
function fx_project_currencies(array $projection, array $invoices = []): array {
    $seen = [];
    foreach ($projection['currencies_seen'] ?? [] as $c => $_) $seen[$c] = true;
    foreach ($invoices as $iv) if ($c = fx_code($iv['currency'] ?? 'USD')) $seen[$c] = true;
    unset($seen['USD']);
    ksort($seen);
    return array_keys($seen);
}
