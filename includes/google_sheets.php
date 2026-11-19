<?php
// TALKING TO GOOGLE SHEETS — as a "service account", with plain cURL.
//
// Used by the outage backup (includes/sheet_backup.php): Anima copies Spell
// into a Google Sheet every hour, so the studio has something to work from on a
// day Anima will not open (David, 2026-11-19). docs/SHEET_BACKUP.md is the
// set-up guide.
//
// A SERVICE ACCOUNT is a Google identity for a program rather than a person:
// it has an email address (…@….iam.gserviceaccount.com) and a private key, and
// it can open only what somebody has shared with that address. Mana shares ONE
// Drive folder with it, so the key opens that folder and nothing else of Mana's.
//
// ⚠ NO GOOGLE LIBRARY, ON PURPOSE. Google's PHP client is installed with
// Composer, and this project has no build step and no vendor folder. What the
// library does for this job is two things, both short:
//
//   1. Sign in: build a "JWT" (a small JSON note saying who we are and what we
//      want, signed with the private key using RS256, which PHP's openssl does
//      natively) and trade it at Google's token address for an access token
//      that lasts an hour.
//   2. Call the Sheets API with that token: plain HTTPS and JSON.
//
// The cURL style is includes/ai.php's, the one other call this app makes to
// the outside world.
//
// ⚠ THE KEY FILE IS A SECRET. It lives on the server, OUTSIDE the website
// folder, and config.php says where. It is never in git: anybody holding it
// can edit the backup sheet.

/** The Sheets API's one permission we ask for: read and edit spreadsheets. */
const GSHEET_SCOPE = 'https://www.googleapis.com/auth/spreadsheets';
const GSHEET_API   = 'https://sheets.googleapis.com/v4/spreadsheets/';

/**
 * What is missing before the backup can run, as a sentence for David, or null
 * when everything is in place. Reads config.php; touches nothing remote.
 */
function gsheet_setup_problem(): ?string {
    global $config;
    $path = trim((string)($config['google_service_account_json'] ?? ''));
    $sheet = trim((string)($config['google_backup_sheet_id'] ?? ''));
    if ($path === '' && $sheet === '') {
        return 'The Google Sheet backup is not set up yet. docs/SHEET_BACKUP.md walks through it.';
    }
    if ($path === '') return "config.php has no 'google_service_account_json' (where the key file is).";
    if ($sheet === '') return "config.php has no 'google_backup_sheet_id' (which sheet to write).";
    if (!is_file($path) || !is_readable($path)) {
        return 'The Google key file named in config.php is not there, or the website cannot read it: ' . $path;
    }
    if (!function_exists('curl_init')) return 'This server has no cURL, which the backup needs. Ask the host to enable it.';
    if (!function_exists('openssl_sign')) return 'This server has no OpenSSL in PHP, which the backup needs to sign in to Google.';
    $key = json_decode((string)@file_get_contents($path), true);
    if (!is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
        return 'The Google key file is not a service-account key (it should be the .json file Google downloads).';
    }
    return null;
}

/** The spreadsheet the backup writes, from config.php. */
function gsheet_backup_id(): string {
    global $config;
    $id = trim((string)($config['google_backup_sheet_id'] ?? ''));
    // People paste the whole address; take the id out of it so both work.
    if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $id, $m)) $id = $m[1];
    return $id;
}

/** The service account's email address, the one the Drive folder is shared with. */
function gsheet_service_email(): string {
    global $config;
    $path = trim((string)($config['google_service_account_json'] ?? ''));
    $key = $path !== '' ? json_decode((string)@file_get_contents($path), true) : null;
    return is_array($key) ? (string)($key['client_email'] ?? '') : '';
}

/** base64url, the flavour a JWT uses: no padding, - and _ for + and /. */
function gsheet_b64url(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/**
 * An access token for the Sheets API, fetched once per run and reused (it
 * lasts an hour; a run takes seconds).
 *
 * @throws RuntimeException with Google's own reason when sign-in fails
 */
function gsheet_token(): string {
    static $token = null, $until = 0;
    if ($token !== null && time() < $until - 60) return $token;

    if (($problem = gsheet_setup_problem()) !== null) throw new RuntimeException($problem);
    global $config;
    $key = json_decode((string)file_get_contents((string)$config['google_service_account_json']), true);
    $aud = (string)($key['token_uri'] ?? 'https://oauth2.googleapis.com/token');

    $now = time();
    $head  = gsheet_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim = gsheet_b64url(json_encode([
        'iss'   => $key['client_email'],
        'scope' => GSHEET_SCOPE,
        'aud'   => $aud,
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));
    $pkey = openssl_pkey_get_private((string)$key['private_key']);
    if ($pkey === false) throw new RuntimeException('The private key in the Google key file could not be read.');
    $sig = '';
    if (!openssl_sign($head . '.' . $claim, $sig, $pkey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Signing in to Google failed: the key could not sign.');
    }
    $jwt = $head . '.' . $claim . '.' . gsheet_b64url($sig);

    $ch = curl_init($aud);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) throw new RuntimeException('Could not reach Google to sign in: ' . $err);
    $out = json_decode((string)$raw, true);
    if ($code !== 200 || empty($out['access_token'])) {
        $why = $out['error_description'] ?? $out['error'] ?? ('HTTP ' . $code);
        throw new RuntimeException('Google refused the sign-in: ' . $why
            . '. Check the key file is the current one and the Sheets API is switched on.');
    }
    $token = (string)$out['access_token'];
    $until = $now + (int)($out['expires_in'] ?? 3600);
    return $token;
}

/**
 * One call to the Sheets API. $path follows ".../spreadsheets/" (e.g.
 * "{id}:batchUpdate"). Returns the decoded answer.
 *
 * @throws RuntimeException with Google's own message, plus a hint for the two
 *         mistakes a set-up actually makes (not shared, API not switched on)
 */
function gsheet_call(string $method, string $path, ?array $body = null): array {
    $ch = curl_init(GSHEET_API . $path);
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . gsheet_token(),
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, $opts);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) throw new RuntimeException('Could not reach Google Sheets: ' . $err);
    $out = json_decode((string)$raw, true);
    if ($code < 200 || $code >= 300) {
        $why = is_array($out) ? (string)($out['error']['message'] ?? '') : '';
        $hint = '';
        if ($code === 403) {
            $hint = ' Is the Drive folder (or the sheet) shared with ' . (gsheet_service_email() ?: 'the service account')
                  . ' as an Editor, and is the Google Sheets API switched on for its project?';
        } elseif ($code === 404) {
            $hint = " Check 'google_backup_sheet_id' in config.php is the sheet's id.";
        }
        throw new RuntimeException('Google Sheets said no (HTTP ' . $code . ')'
            . ($why !== '' ? ': ' . $why : '') . '.' . $hint);
    }
    return is_array($out) ? $out : [];
}

/**
 * The spreadsheet's tabs, keyed by Google's id for each:
 * ['title' => name, 'rows' => how many rows it has, 'protected' => [protected range ids]].
 */
function gsheet_tabs(string $spreadsheetId): array {
    $meta = gsheet_call('GET', rawurlencode($spreadsheetId) . '?fields='
        . rawurlencode('sheets(properties(sheetId,title,gridProperties(rowCount)),protectedRanges(protectedRangeId))'));
    $tabs = [];
    foreach ($meta['sheets'] ?? [] as $s) {
        $tabs[(int)$s['properties']['sheetId']] = [
            'title'     => (string)$s['properties']['title'],
            'rows'      => (int)($s['properties']['gridProperties']['rowCount'] ?? 0),
            'protected' => array_map(fn($p) => (int)$p['protectedRangeId'], $s['protectedRanges'] ?? []),
        ];
    }
    return $tabs;
}

/** Several edits to the spreadsheet at once, applied in order. */
function gsheet_batch_update(string $spreadsheetId, array $requests): array {
    if (!$requests) return [];
    return gsheet_call('POST', rawurlencode($spreadsheetId) . ':batchUpdate', ['requests' => $requests]);
}

/** A tab name quoted for an A1 range: 'It''s here'!A1:M. */
function gsheet_range(string $tabTitle, string $cells): string {
    return "'" . str_replace("'", "''", $tabTitle) . "'!" . $cells;
}

/**
 * The text of several ranges at once, exactly as the sheet shows it.
 *
 * @return array one list of rows (each a list of strings) per range, in order
 */
function gsheet_read(string $spreadsheetId, array $ranges): array {
    if (!$ranges) return [];
    $q = 'valueRenderOption=FORMATTED_VALUE&majorDimension=ROWS';
    foreach ($ranges as $r) $q .= '&ranges=' . rawurlencode($r);
    $out = gsheet_call('GET', rawurlencode($spreadsheetId) . '/values:batchGet?' . $q);
    $res = [];
    foreach ($out['valueRanges'] ?? [] as $vr) $res[] = $vr['values'] ?? [];
    return $res;
}
