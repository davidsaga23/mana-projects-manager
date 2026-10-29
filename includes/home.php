<?php
// Home: one person's work, contract and invoice information across every show.
//
// David, 2026-10-28: the one screen an artist opens to see "their work, contract
// and invoice info across every show". Until now that meant My tasks for the
// work and statement.php one show at a time for the money. Everything here is
// READ from where it already lives; nothing writes, and the task editing stays
// on My tasks, which this links to rather than copies.
//
// ⚠ ALWAYS THE VIEWER'S OWN. Every function takes the crew id the page forces
// to effective_crew_id(), exactly as statement.php does, and the money is read
// through own_crew_term() / own_invoices_all() / own_project_fx_rates(), which
// return nothing for anybody else's record (includes/money.php). No budget, no
// other person's rate, no forecast and no comparison with other artists is read
// here at all — so there is nothing to leak even if a screen forgot a check.
//
// ⚠ "INVOICE", NEVER "BILL" (David, 2026-10-28), in the UI, the code and the docs.
//
// THE THREE VARIANTS, agreed with David the same day:
//   freelancer  everything, including what goes on this month's invoice
//   supervisor  the same, plus their team's missing log days and notes waiting
//   salaried    David, Tim, Renata, Junior: no invoice information, no amounts
//               and no invoice status. Just shows, logs, tasks and notes.

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/money.php';        // own_crew_term(), own_invoices_all()
require_once __DIR__ . '/timelog.php';      // hours_per_day(), logged_total_for_crew()
require_once __DIR__ . '/hiring.php';       // engagement()
require_once __DIR__ . '/statements.php';   // statement_window(), statement_people()
require_once __DIR__ . '/supervision.php';  // supervision_positions()
require_once __DIR__ . '/absences.php';     // crew_absences()
require_once __DIR__ . '/studio.php';       // studio_exclude_sql()

/** The logins linked to a person. Usually one; a person can have two accounts. */
function home_user_ids(PDO $pdo, int $crewId): array {
    try {
        $q = $pdo->prepare('SELECT id FROM users WHERE crew_id = ?');
        $q->execute([$crewId]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        return [];      // users.crew_id before migration 017
    }
}

/**
 * Is this person salaried staff rather than a freelancer?
 *
 * THE "SALARIED" TICK ON THE USERS PAGE decides it (David, 2026-10-28: "let's
 * add that attribute in the users tab"; users.is_salaried, migration 072). A
 * person is salaried when any login linked to them is ticked. It is explicit on
 * purpose: nobody is salaried because of their role, so an admin who also
 * freelances on a show is simply left unticked.
 *
 * Until migration 072 has run there is no tick to read, and the people who run
 * the studio (admin and Production logins) are taken as salaried, which is
 * what this page did before the tick existed.
 *
 * Getting this wrong in the safe direction matters more than in the other: a
 * salaried person shown invoice lines is confusing, but nothing is leaked,
 * because the figures shown are still only their own.
 */
function home_is_salaried(PDO $pdo, int $crewId): bool {
    if ($crewId <= 0) return false;
    $sql = column_exists($pdo, 'users', 'is_salaried')
        ? 'SELECT COUNT(*) FROM users WHERE crew_id = ? AND is_salaried = 1'
        : "SELECT COUNT(*) FROM users WHERE crew_id = ? AND role IN ('admin','editor')";
    try {
        $q = $pdo->prepare($sql);
        $q->execute([$crewId]);
        return (int)$q->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * The shows a person is on now: a position (slot or supervision), a staffing
 * row, or a task assigned to them, on a show that is set up, active or on hold.
 * Wrapped and archived shows are finished business; their invoices still show
 * in the invoice status list, which reads the finance database, not this.
 * [project id => project row], by name.
 */
function home_projects(PDO $pdo, int $crewId): array {
    $ids = [];
    $add = function (string $sql, array $args) use ($pdo, &$ids) {
        try {
            $q = $pdo->prepare($sql);
            $q->execute($args);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) if ($id) $ids[(int)$id] = true;
        } catch (PDOException $e) {
            // a table whose migration has not run on this install
        }
    };
    $add('SELECT project_id FROM crew_lanes WHERE crew_id = ?', [$crewId]);
    $add('SELECT project_id FROM supervision_positions WHERE crew_id = ?', [$crewId]);   // 068
    $add('SELECT project_id FROM crew_projects WHERE crew_id = ?', [$crewId]);           // 024
    $add('SELECT DISTINCT project_id FROM progress WHERE crew_id = ?', [$crewId]);
    $add('SELECT DISTINCT pr.project_id FROM progress_crew pc JOIN progress pr ON pr.id = pc.progress_id
           WHERE pc.crew_id = ?', [$crewId]);
    if (!$ids) return [];
    $in = implode(',', array_map('intval', array_keys($ids)));
    $out = [];
    try {
        foreach ($pdo->query("SELECT * FROM projects p
                               WHERE p.id IN ($in) AND p.status IN ('setup','active','on_hold')"
                             . studio_exclude_sql($pdo, 'p') . ' ORDER BY p.name') as $p) {
            $out[(int)$p['id']] = $p;
        }
    } catch (PDOException $e) {
        return [];
    }
    return $out;
}

/**
 * What one person has on one show: their dates, days left, the positions they
 * hold, the show's supervisors, and the time they have logged.
 */
function home_show(PDO $pdo, array $project, int $crewId, string $monthFrom, string $today): array {
    $pid = (int)$project['id'];
    $win = statement_window($pdo, $pid, $crewId);

    // Working days left on the engagement, today included. Null when there is
    // no end date, which the page says rather than guessing.
    $daysLeft = null;
    if ($win['to']) {
        $daysLeft = $win['to'] < $today ? 0 : working_days_between($today, $win['to']);
    }

    // The positions they hold: production slots by step, and supervision.
    $positions = [];
    try {
        $q = $pdo->prepare('SELECT DISTINCT ps.id, ps.name, ps.step_order FROM crew_lanes cl
                              JOIN pipeline_steps ps ON ps.id = cl.step_id
                             WHERE cl.project_id = ? AND cl.crew_id = ?
                             ORDER BY ps.step_order, ps.name');
        $q->execute([$pid, $crewId]);
        foreach ($q as $r) $positions[] = (string)$r['name'];
    } catch (PDOException $e) {
        // crew_lanes before migration 012
    }
    $supervisors = [];
    $holdsSupervision = false;
    foreach (supervision_positions($pdo, $pid) as $sp) {
        if ((int)$sp['crew_id'] === $crewId) {
            $positions[] = (string)$sp['title'];
            $holdsSupervision = true;
            continue;
        }
        // An unfilled position is a placeholder named after itself, not a
        // person anybody can ask.
        if (!empty($sp['is_placeholder'])) continue;
        $supervisors[] = ['title' => (string)$sp['title'], 'name' => (string)$sp['name']];
    }

    $hpd = hours_per_day($pdo, $pid);
    $minutesWindow = logged_total_for_crew($pdo, $crewId, $pid, $win['from'], $win['to']);
    $minutesMonth  = logged_total_for_crew($pdo, $crewId, $pid, $monthFrom, $today);

    return [
        'project'     => $project,
        'window'      => $win,
        'days_left'   => $daysLeft,
        'positions'   => $positions,
        'supervises'  => $holdsSupervision,
        'supervisors' => $supervisors,
        'hours_per_day' => $hpd,
        'minutes'     => $minutesWindow,
        'minutes_month' => $minutesMonth,
    ];
}

/**
 * The working days in [$from, $to] on which a person logged nothing, though
 * they were on a show and not away.
 *
 * "Artists log their time every day" (money rules, 2026-10-28). A day counts
 * as one they should have logged when it is a working day (weekends and studio
 * holidays are already out of working_days_list()), they were not on a recorded
 * absence, and it falls inside the window of at least one of their shows. A
 * show with no dates at all covers every day, the same reading statement.php
 * gives it. With no shows at all the person is not nagged: there is nothing to
 * log against but the Studio, and that is not a show anybody is late on.
 *
 * @param array $windows each ['from' => ?Y-m-d, 'to' => ?Y-m-d]
 * @return string[] Y-m-d, oldest first
 */
function home_missing_days(PDO $pdo, int $crewId, array $windows, string $from, string $to): array {
    if (!$windows || $to < $from || !time_logs_available($pdo)) return [];
    $days = working_days_list($from, $to);
    if (!$days) return [];

    $logged = [];
    $q = $pdo->prepare('SELECT DISTINCT log_date FROM time_logs
                         WHERE crew_id = ? AND log_date BETWEEN ? AND ? AND minutes > 0');
    $q->execute([$crewId, $from, $to]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $d) $logged[(string)$d] = true;

    $away = crew_absences($pdo, $crewId);

    $out = [];
    foreach ($days as $d) {
        if (isset($logged[$d])) continue;
        $isAway = false;
        foreach ($away as $a) {
            if ($d >= (string)$a['from_date'] && $d <= (string)$a['to_date']) { $isAway = true; break; }
        }
        if ($isAway) continue;
        $onAShow = false;
        foreach ($windows as $w) {
            if ((!$w['from'] || $d >= $w['from']) && (!$w['to'] || $d <= $w['to'])) { $onAShow = true; break; }
        }
        if ($onAShow) $out[] = $d;
    }
    return $out;
}

/**
 * "Tuesday or Wednesday", "Friday 24 Oct, Monday or Tuesday": the days of this
 * week by name, older ones with their date, joined the way a person would say it.
 */
function home_days_phrase(array $days, string $today): string {
    $monday = date('Y-m-d', strtotime('monday this week', strtotime($today)));
    $words = [];
    foreach ($days as $d) {
        $t = strtotime($d);
        $words[] = $d >= $monday ? date('l', $t) : date('l j M', $t);
    }
    if (count($words) <= 1) return (string)($words[0] ?? '');
    $last = array_pop($words);
    return implode(', ', $words) . ' or ' . $last;
}

/** "$", "£", "€" for the common codes; anything else keeps its code. */
function home_currency_symbol(string $cur): ?string {
    $map = ['USD' => '$', 'GBP' => '£', 'EUR' => '€'];
    return $map[strtoupper($cur)] ?? null;
}

/**
 * "$4,160.00", "£3,200.00", "1,000.00 CAD". Cents always: these are the
 * figures somebody types onto an invoice.
 */
function home_money(?float $amount, string $cur = 'USD'): string {
    if ($amount === null) return '—';
    $sym = home_currency_symbol($cur);
    $n = number_format($amount, 2);
    return $sym !== null ? $sym . $n : $n . ' ' . strtoupper($cur);
}

/**
 * "$520/day (£400)" — David's own example, 2026-10-28: the rate in dollars, and
 * the contract's own currency beside it when it is not dollars and the show has
 * an exchange rate fixed for it. Without a fixed rate it is "£400/day" alone.
 * Whole amounts drop their cents, because a rate is quoted, not totalled.
 */
function home_rate_phrase(?float $rate, string $unit, string $cur, ?float $usdRate = null): string {
    if ($rate === null) return '—';
    $per = ['day' => '/day', 'hour' => '/hour', 'second' => '/second', 'flat' => ' flat fee'][$unit] ?? '/' . $unit;
    $fmt = function (float $v, string $c): string {
        $n = (abs($v - round($v)) < 0.005) ? number_format($v, 0) : number_format($v, 2);
        $sym = home_currency_symbol($c);
        return $sym !== null ? $sym . $n : $n . ' ' . strtoupper($c);
    };
    if ($usdRate !== null && strtoupper($cur) !== 'USD') {
        return $fmt($usdRate, 'USD') . $per . ' (' . $fmt($rate, $cur) . ')';
    }
    return $fmt($rate, $cur) . $per;
}

/**
 * The show's fixed exchange rates, for this person (one rate fixed per
 * project, includes/fx.php). Before finance_install_11 is imported, or for a
 * show with no rate fixed yet, there are none, and a non-dollar rate is shown
 * in its own currency, as statement.php does.
 */
function home_fx(int $crewId, int $projectId): array {
    if (!function_exists('own_project_fx_rates')) {
        $f = __DIR__ . '/fx.php';
        if (is_file($f)) require_once $f;
    }
    return function_exists('own_project_fx_rates') ? own_project_fx_rates($crewId, $projectId) : [];
}

/**
 * WHAT GOES ON THIS MONTH'S INVOICE — the information, not an invoice.
 *
 * David, 2026-10-28: freelancers send ONE invoice a month to
 * accounting@mana.tv with a line per project; Home shows what they need to put
 * on it: one line per project and step (the position the time was logged
 * against), with the unit and rate, the days or units, and the line total, plus
 * the month's total.
 *
 * Counted the way the statement counts: time logged in the month AND inside
 * the person's window on that show; a day is the show's hours per day. Time on
 * a show's ticket with no step goes on an "Other work" line for that show.
 *
 * ⚠ PER-SECOND AND FLAT CONTRACTS ARE NOT TOTALLED HERE. What they are paid on
 * (approved footage, a fee once) is not a monthly count of logged time, and
 * guessing which month a shot's approval belongs to would put a wrong number
 * on an invoice. Their line says so and points to the statement.
 *
 * Returns ['lines' => [...], 'totals' => [currency => amount], 'outside' => minutes]
 */
function home_invoice_lines(PDO $pdo, int $crewId, array $shows, ?array $terms,
                            string $monthFrom, string $monthTo, array $posTerms = []): array {
    $out = ['lines' => [], 'totals' => [], 'outside' => 0];
    if (!$shows || !time_logs_available($pdo)) return $out;

    $in = implode(',', array_map('intval', array_keys($shows)));
    $hasTicketStep = column_exists($pdo, 'tickets', 'step_id');
    $sql = 'SELECT tl.project_id, tl.log_date, '
         . ($hasTicketStep ? 'COALESCE(pr.step_id, t.step_id)' : 'pr.step_id') . ' AS sid,
                   SUM(tl.minutes) AS m
              FROM time_logs tl
              LEFT JOIN progress pr ON pr.id = tl.progress_id
              LEFT JOIN tickets t ON t.id = tl.ticket_id
             WHERE tl.crew_id = ? AND tl.log_date BETWEEN ? AND ? AND tl.project_id IN (' . $in . ')
             GROUP BY tl.project_id, tl.log_date, sid';
    try {
        $q = $pdo->prepare($sql);
        $q->execute([$crewId, $monthFrom, $monthTo]);
        $rows = $q->fetchAll();
    } catch (PDOException $e) {
        return $out;
    }

    // Minutes per show and step, inside each show's window.
    $minutes = [];
    foreach ($rows as $r) {
        $pid = (int)$r['project_id'];
        $w = $shows[$pid]['window'] ?? ['from' => null, 'to' => null];
        $d = (string)$r['log_date'];
        if (($w['from'] && $d < $w['from']) || ($w['to'] && $d > $w['to'])) {
            $out['outside'] += (int)$r['m'];
            continue;
        }
        $sid = (int)($r['sid'] ?? 0);
        $minutes[$pid][$sid] = ($minutes[$pid][$sid] ?? 0) + (int)$r['m'];
    }
    if (!$minutes) return $out;

    $stepNames = [];
    $sids = [];
    foreach ($minutes as $bySid) foreach (array_keys($bySid) as $sid) if ($sid > 0) $sids[$sid] = true;
    if ($sids) {
        foreach ($pdo->query('SELECT id, name FROM pipeline_steps WHERE id IN ('
                             . implode(',', array_map('intval', array_keys($sids))) . ')') as $s) {
            $stepNames[(int)$s['id']] = (string)$s['name'];
        }
    }

    foreach ($shows as $pid => $show) {
        if (empty($minutes[$pid])) continue;
        $fx = home_fx($crewId, (int)$pid);
        $hpd = (float)$show['hours_per_day'];
        // ⚠ THE CONTRACT EACH LINE IS PAID ON (finance_install_13, 2026-10-29):
        // a position's own when it has one, else — as on the statement — the
        // show's supervision contract when they hold one with its own terms,
        // else their default. $posTerms is [project id][step id] => row.
        $showPos = $posTerms[(int)$pid] ?? [];
        $restTerms = $terms;
        foreach ($showPos as $psid => $pt) if ((int)$psid < 0) { $restTerms = $pt; break; }
        foreach ($minutes[$pid] as $sid => $m) {
            $lt = ($sid > 0 && isset($showPos[$sid])) ? $showPos[$sid] : $restTerms;
            $ownContract = $lt !== $terms;
            $rate = ($lt && $lt['day_rate'] !== null) ? (float)$lt['day_rate'] : null;
            $unit = (string)($lt['rate_unit'] ?? 'day');
            if (!in_array($unit, ['day', 'hour', 'second', 'flat'], true)) $unit = 'day';
            $cur  = strtoupper((string)($lt['rate_currency'] ?? 'USD')) ?: 'USD';
            $usdRate = null;
            if ($rate !== null && $cur !== 'USD' && function_exists('fx_to_usd')) {
                $usdRate = fx_to_usd($rate, $cur, $fx);
            }
            $hours = $m / 60;
            $qty = null; $qtyLabel = '';
            if ($unit === 'day') {
                $qty = $hpd > 0 ? $hours / $hpd : 0.0;
                $qtyLabel = fmt_num($qty, 2) . ' days';
            } elseif ($unit === 'hour') {
                $qty = $hours;
                $qtyLabel = fmt_num($qty, 2) . ' hours';
            }
            // The line in the contract's own currency, then in dollars when the
            // show has a fixed rate for it. The dollar figure is the one to use.
            $own = ($rate !== null && $qty !== null) ? round($rate * $qty, 2) : null;
            $lineCur = $cur;
            $lineTotal = $own;
            if ($own !== null && $usdRate !== null) {
                $lineTotal = fx_to_usd($own, $cur, $fx);
                $lineCur = 'USD';
            }
            if ($lineTotal !== null) {
                $out['totals'][$lineCur] = round(($out['totals'][$lineCur] ?? 0) + $lineTotal, 2);
            }
            $out['lines'][] = [
                'project'    => $show['project'],
                'step'       => $sid > 0 ? ($stepNames[$sid] ?? 'Step') : 'Other work on the show',
                'own_contract' => $ownContract,
                'unit'       => $unit,
                'rate'       => $rate,
                'rate_phrase'=> home_rate_phrase($rate, $unit, $cur, $usdRate),
                'hours'      => $hours,
                'qty'        => $qty,
                'qty_label'  => $qty !== null ? $qtyLabel : fmt_num($hours, 1) . ' h logged',
                'total'      => $lineTotal,
                'total_own'  => ($lineCur !== $cur) ? $own : null,
                'currency'   => $lineCur,
                'own_currency' => $cur,
            ];
        }
    }
    return $out;
}

/** "Expected", "Received, not paid yet", "Paid" — finance_install_09's three, in words. */
function home_invoice_status(array $inv): string {
    switch ((string)($inv['status'] ?? '')) {
        case 'expected': return 'Not received yet';
        case 'paid':     return 'Paid';
        case 'pending':
        case 'received':
        case 'approved': return 'Received, not paid yet';
        case 'disputed': return 'Received, being checked';
        default:         return ucfirst((string)($inv['status'] ?? ''));
    }
}

/** The versions a person put up in Spell most recently, newest first. */
function home_versions(PDO $pdo, array $userIds, int $limit = 6): array {
    if (!$userIds || !column_exists($pdo, 'progress_versions', 'version_no')) return [];
    $in = implode(',', array_map('intval', $userIds));
    $limit = max(1, min(50, $limit));
    try {
        return $pdo->query(
            "SELECT v.id, v.version_no, v.original_name, v.created_at,
                    pr.project_id, pr.step_id, pr.item_key,
                    p.name AS project_name, p.code AS project_code,
                    ps.name AS step_name, s.episode, s.shot_code, a.name AS asset_name
               FROM progress_versions v
               JOIN progress pr ON pr.id = v.progress_id
               JOIN projects p ON p.id = pr.project_id
               JOIN pipeline_steps ps ON ps.id = pr.step_id
               LEFT JOIN shots s ON s.id = pr.shot_id
               LEFT JOIN assets a ON a.id = pr.asset_id
              WHERE v.uploaded_by IN ($in)
              ORDER BY v.created_at DESC, v.id DESC
              LIMIT {$limit}"
        )->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/** "EP01 SH050" or the asset's name, for a task row. */
function home_item_label(array $r): string {
    $item = shot_label($r['episode'] ?? '', $r['shot_code'] ?? '');
    return $item !== '' ? $item : (string)($r['asset_name'] ?? '');
}

/**
 * NOTES WAITING ON THEM: what somebody else said on one of their Spell tasks
 * that they have not answered yet.
 *
 * "Not answered" is read the simplest way that is true: a note by somebody
 * else, newer than anything the person has said on that task AND newer than
 * the last version put up on it. A reply or a new version is the answer. A
 * review note (a comment on a version, migration 065) is marked as one, and a
 * task whose status reads like a retake is flagged as sent back even before
 * anybody types a note on it.
 *
 * Only tasks that are still open (not approved) on a live show: a note on
 * finished work is history, not something waiting.
 *
 * @return array one row per task, newest note first, with 'count' notes waiting
 */
function home_notes_waiting(PDO $pdo, int $crewId, array $userIds): array {
    if (!column_exists($pdo, 'ticket_comments', 'progress_id')) return [];
    $mine = $userIds ? implode(',', array_map('intval', $userIds)) : '0';
    $hasVersions = column_exists($pdo, 'progress_versions', 'version_no');
    $hasReview = column_exists($pdo, 'ticket_comments', 'version_id');
    $sql = "SELECT c.id AS comment_id, c.body, c.created_at, u.display_name AS author,
                   " . ($hasReview ? 'c.version_id' : 'NULL AS version_id') . ",
                   pr.id AS progress_id, pr.project_id, pr.step_id, pr.item_key,
                   p.name AS project_name, ps.name AS step_name,
                   s.episode, s.shot_code, a.name AS asset_name, st.name AS status_name
              FROM ticket_comments c
              JOIN progress pr ON pr.id = c.progress_id
              JOIN projects p ON p.id = pr.project_id AND p.status IN ('active','setup','on_hold')
              JOIN pipeline_steps ps ON ps.id = pr.step_id
              LEFT JOIN shots s ON s.id = pr.shot_id
              LEFT JOIN assets a ON a.id = pr.asset_id
              LEFT JOIN statuses st ON st.id = pr.status_id
              LEFT JOIN users u ON u.id = c.user_id
             WHERE c.kind = 'user'
               AND (c.user_id IS NULL OR c.user_id NOT IN ($mine))
               AND (pr.crew_id = ? OR EXISTS (SELECT 1 FROM progress_crew pc
                                               WHERE pc.progress_id = pr.id AND pc.crew_id = ?))
               AND (st.maps_to IS NULL OR st.maps_to <> 'approved')
               AND c.created_at > COALESCE((SELECT MAX(c2.created_at) FROM ticket_comments c2
                                             WHERE c2.progress_id = pr.id AND c2.user_id IN ($mine)), '1970-01-01')"
         . ($hasVersions ? "
               AND c.created_at > COALESCE((SELECT MAX(v.created_at) FROM progress_versions v
                                             WHERE v.progress_id = pr.id), '1970-01-01')" : '') . "
             ORDER BY c.created_at DESC, c.id DESC
             LIMIT 200";
    try {
        $q = $pdo->prepare($sql);
        $q->execute([$crewId, $crewId]);
        $rows = $q->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $k = (int)$r['progress_id'];
        if (!isset($out[$k])) {
            $r['count'] = 0;
            $r['is_review'] = false;
            $r['is_retake'] = home_looks_like_retake((string)($r['status_name'] ?? ''));
            $out[$k] = $r;
        }
        $out[$k]['count']++;
        if (!empty($r['version_id'])) $out[$k]['is_review'] = true;
    }

    // Sent back with no note yet: the status alone is the message.
    try {
        $q = $pdo->prepare(
            "SELECT pr.id AS progress_id, pr.project_id, pr.step_id, pr.item_key,
                    p.name AS project_name, ps.name AS step_name,
                    s.episode, s.shot_code, a.name AS asset_name, st.name AS status_name
               FROM progress pr
               JOIN projects p ON p.id = pr.project_id AND p.status IN ('active','setup','on_hold')
               JOIN pipeline_steps ps ON ps.id = pr.step_id
               JOIN statuses st ON st.id = pr.status_id
               LEFT JOIN shots s ON s.id = pr.shot_id
               LEFT JOIN assets a ON a.id = pr.asset_id
              WHERE (pr.crew_id = ? OR EXISTS (SELECT 1 FROM progress_crew pc
                                                WHERE pc.progress_id = pr.id AND pc.crew_id = ?))
                AND (st.maps_to IS NULL OR st.maps_to <> 'approved')"
        );
        $q->execute([$crewId, $crewId]);
        foreach ($q as $r) {
            $k = (int)$r['progress_id'];
            if (isset($out[$k]) || !home_looks_like_retake((string)$r['status_name'])) continue;
            $r += ['comment_id' => 0, 'body' => '', 'created_at' => null, 'author' => '',
                   'version_id' => null, 'count' => 0, 'is_review' => false, 'is_retake' => true];
            $out[$k] = $r;
        }
    } catch (PDOException $e) {
        // progress_crew or statuses mid-upgrade: the notes above still stand
    }
    return array_values($out);
}

/**
 * Does a status name mean "sent back"? The same reading review.php uses to
 * offer the send-back status first.
 */
function home_looks_like_retake(string $status): bool {
    return $status !== '' && preg_match('/retake|change|revis|fix|redo/i', $status) === 1;
}

/**
 * Their open production tasks on live shows, soonest first — the same rows My
 * tasks lists (and the same query), read here only to sort into overdue, this
 * week and later. Changing a status or logging time stays on My tasks.
 */
function home_tasks(PDO $pdo, int $crewId): array {
    try {
        $t = $pdo->prepare(
            "SELECT pr.id AS progress_id, pr.project_id, pr.step_id, pr.item_key,
                    p.name AS project_name, p.code AS project_code,
                    ps.name AS step_name, ps.deadline,
                    s.episode, s.shot_code, a.name AS asset_name,
                    st.name AS status_name, st.color AS status_color,
                    sc.end_date
               FROM progress pr
               JOIN projects p ON p.id = pr.project_id AND p.status IN ('active','setup')
               JOIN pipeline_steps ps ON ps.id = pr.step_id
               LEFT JOIN shots  s ON s.id = pr.shot_id
               LEFT JOIN assets a ON a.id = pr.asset_id
               LEFT JOIN statuses st ON st.id = pr.status_id
               LEFT JOIN schedule sc ON sc.step_id = pr.step_id AND sc.item_key = pr.item_key
              WHERE (pr.crew_id = ?
                     OR EXISTS (SELECT 1 FROM progress_crew pc
                                 WHERE pc.progress_id = pr.id AND pc.crew_id = ?))
                AND (st.maps_to IS NULL OR st.maps_to <> 'approved')
                AND COALESCE(st.is_excluded, 0) = 0
              ORDER BY sc.end_date IS NULL, sc.end_date, p.code, ps.step_order"
        );
        $t->execute([$crewId, $crewId]);
        return $t->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Overdue, due this week (to Sunday) and lined up after, for tasks and cards
 * together. A task's date is its scheduled end; a card's is its due date.
 * Undated work goes last, under "lined up", because it is still theirs.
 */
function home_whats_next(array $tasks, array $tickets, string $today): array {
    $sunday = date('Y-m-d', strtotime('sunday this week', strtotime($today)));
    $out = ['overdue' => [], 'week' => [], 'later' => []];
    $put = function (array $row) use (&$out, $today, $sunday) {
        $d = $row['due'];
        if ($d && $d < $today)       $out['overdue'][] = $row;
        elseif ($d && $d <= $sunday) $out['week'][] = $row;
        else                         $out['later'][] = $row;
    };
    foreach ($tasks as $t) {
        $put([
            'kind'   => 'task',
            'due'    => $t['end_date'] ?: null,
            'project'=> (string)$t['project_name'],
            'label'  => trim(home_item_label($t) . ' · ' . $t['step_name'], ' ·'),
            'status' => (string)($t['status_name'] ?? ''),
            'href'   => home_task_path((int)$t['project_id'], (int)$t['step_id'], (string)$t['item_key']),
        ]);
    }
    foreach ($tickets as $t) {
        $put([
            'kind'   => 'ticket',
            'due'    => $t['due_date'] ?: null,
            'project'=> (string)($t['project_name'] ?? ''),
            'label'  => (string)$t['title'],
            'status' => (string)($t['status_name'] ?? ''),
            'href'   => 'ticket.php?id=' . (int)$t['id'],
        ]);
    }
    $byDue = fn($a, $b) => strcmp((string)($a['due'] ?? '9999'), (string)($b['due'] ?? '9999'));
    foreach ($out as $k => $rows) { usort($rows, $byDue); $out[$k] = $rows; }
    return $out;
}

/**
 * The Spell address of one task. board_task_path() says it once in
 * includes/board.php; that file is large and drags in the board, so it is used
 * when already loaded and the same address is written here otherwise.
 */
function home_task_path(int $projectId, int $stepId, string $itemKey): string {
    if (function_exists('board_task_path')) return board_task_path($projectId, $stepId, $itemKey);
    return 'spell.php?project=' . $projectId . '&open=' . rawurlencode($stepId . ':' . $itemKey);
}

/**
 * A SUPERVISOR'S TEAM, for the extra panel (agreed with David, 2026-10-28):
 * each team member's missing log days and the notes waiting on them. Nothing
 * about anybody's money — not a rate, not a total, not a comparison.
 *
 * The team is everybody with something on the shows this person supervises —
 * a show where they hold a supervision position, or, for a supervisor login,
 * every show they are on — apart from themselves.
 *
 * @param array $shows home_show() rows keyed by project id
 * @return array rows by name: crew_id, name, shows, missing (Y-m-d[]), notes (int)
 */
function home_team(PDO $pdo, int $crewId, array $shows, bool $roleIsSupervisor,
                   string $from, string $to): array {
    $members = [];
    foreach ($shows as $pid => $show) {
        if (!$roleIsSupervisor && empty($show['supervises'])) continue;
        foreach (statement_people($pdo, (int)$pid) as $id => $name) {
            if ($id === $crewId) continue;
            $members[$id]['name'] = $name;
            $members[$id]['shows'][(int)$pid] = (string)$show['project']['name'];
            $members[$id]['windows'][] = statement_window($pdo, (int)$pid, $id);
        }
    }
    $out = [];
    foreach ($members as $id => $m) {
        $notes = home_notes_waiting($pdo, (int)$id, home_user_ids($pdo, (int)$id));
        $out[] = [
            'crew_id' => (int)$id,
            'name'    => $m['name'],
            'shows'   => array_values($m['shows']),
            'missing' => home_missing_days($pdo, (int)$id, $m['windows'], $from, $to),
            'notes'   => count($notes),
        ];
    }
    usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}
