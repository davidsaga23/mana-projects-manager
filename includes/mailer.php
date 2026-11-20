<?php
/**
 * Minimal authenticated SMTP client + ticket notifications.
 * Ported from STFC's includes/mailer.php, where it is in daily use.
 *
 * PHP's mail() cannot authenticate (STFC lesson 10), so a message claiming to be
 * from @mana.tv sent off this server's IP fails SPF, carries no DKIM signature
 * for the domain, and gets spam-foldered or rejected under DMARC. This sends
 * through a real mailbox's SMTP instead.
 *
 * Port and encryption MUST match: 465 = 'ssl', 587 = 'tls' (STARTTLS). Mixing
 * them hangs until the socket times out rather than failing with a message.
 *
 * Configure in config.php — see config.example.php. With smtp_host empty,
 * everything below reports "email not configured" rather than pretending to send.
 */

class SmtpMailer
{
    private array $cfg;
    private $sock = null;
    /** Full SMTP transcript, for diagnosing a failure without guesswork. */
    public array $log = [];

    public function __construct(array $cfg) { $this->cfg = $cfg; }

    /** True when SMTP is configured well enough to attempt a send. */
    public static function isConfigured(array $cfg): bool {
        return !empty($cfg['smtp_host']) && !empty($cfg['smtp_user']) && !empty($cfg['smtp_pass']);
    }

    private function put(string $cmd): void {
        // Never log credentials — AUTH payloads are base64, which is not encryption.
        $safe = preg_match('/^[A-Za-z0-9+\/=]{12,}$/', $cmd) ? '[credential]' : $cmd;
        $this->log[] = '> ' . $safe;
        fwrite($this->sock, $cmd . "\r\n");
    }

    /** Read a full (possibly multi-line) SMTP reply and assert its code. */
    private function expect(array $codes, string $context): string {
        $data = '';
        while (($line = fgets($this->sock, 515)) !== false) {
            $data .= $line;
            // The last line of a reply has a space, not '-', in position 4.
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        $this->log[] = '< ' . trim($data);
        $code = (int)substr(trim($data), 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException("SMTP {$context} failed: " . trim($data));
        }
        return $data;
    }

    /**
     * @param array $to  list of ['email' => ..., 'name' => ...]
     * @throws RuntimeException on any transport failure
     */
    public function send(array $to, string $subject, string $htmlBody, string $textBody): void {
        // Talking to the mail server can take seconds (up to smtp_timeout per
        // step). The person's session is let go meanwhile, so their other tabs
        // are not queued behind it (KnownHost, 2026-11-19; includes/auth.php),
        // and taken back after, with anything written to it in between. Not once
        // the page has started printing: then it could not be taken back.
        $released = function_exists('session_release') && session_status() === PHP_SESSION_ACTIVE && !headers_sent();
        if ($released) session_release();
        try {
            $this->deliver($to, $subject, $htmlBody, $textBody);
        } finally {
            if ($released) session_resume();
        }
    }

    private function deliver(array $to, string $subject, string $htmlBody, string $textBody): void {
        $host = $this->cfg['smtp_host'];
        $port = (int)($this->cfg['smtp_port'] ?? 587);
        $secure = strtolower($this->cfg['smtp_secure'] ?? 'tls');
        $timeout = (int)($this->cfg['smtp_timeout'] ?? 20);

        // cPanel mail servers frequently present a self-signed or mismatched
        // certificate. Verification is on by default and can be relaxed with
        // 'smtp_verify_peer' => false — acceptable when the mail server is the
        // same machine the app runs on, so the traffic never leaves the box.
        $verify = $this->cfg['smtp_verify_peer'] ?? true;
        $ctx = stream_context_create(['ssl' => [
            'verify_peer'       => (bool)$verify,
            'verify_peer_name'  => (bool)$verify,
            'allow_self_signed' => !$verify,
        ]]);

        $dsnHost = ($secure === 'ssl' ? 'ssl://' : '') . $host;
        $this->log[] = "· connecting to {$dsnHost}:{$port} (" . ($verify ? 'verified' : 'unverified') . " TLS)";
        $this->sock = @stream_socket_client(
            "{$dsnHost}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx
        );
        if (!$this->sock) {
            throw new RuntimeException(
                "Could not connect to {$host}:{$port}, {$errstr} (error {$errno}). "
                . 'Check the host and port in config.php, and that outbound SMTP is not blocked. '
                . 'Remember 465 needs ssl and 587 needs tls.'
            );
        }
        stream_set_timeout($this->sock, $timeout);

        try {
            $this->expect([220], 'greeting');
            $ehloName = $this->cfg['smtp_ehlo'] ?: 'localhost';

            $this->put("EHLO {$ehloName}");
            $this->expect([250], 'EHLO');

            if ($secure === 'tls') {
                $this->put('STARTTLS');
                $this->expect([220], 'STARTTLS');
                $ok = @stream_socket_enable_crypto(
                    $this->sock, true,
                    STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
                );
                if (!$ok) {
                    $err = error_get_last()['message'] ?? '';
                    throw new RuntimeException(
                        'TLS negotiation failed. ' . ($err ? "({$err}) " : '')
                        . 'If that mentions a certificate problem, the mail server is probably using a '
                        . "self-signed cert, add 'smtp_verify_peer' => false to config.php."
                    );
                }
                // The connection has changed; EHLO has to be repeated.
                $this->put("EHLO {$ehloName}");
                $this->expect([250], 'EHLO (post-TLS)');
            }

            $this->put('AUTH LOGIN');
            $this->expect([334], 'AUTH');
            $this->put(base64_encode($this->cfg['smtp_user']));
            $this->expect([334], 'AUTH user');
            $this->put(base64_encode($this->cfg['smtp_pass']));
            $this->expect([235], 'AUTH password');

            $fromEmail = $this->cfg['mail_from'] ?: $this->cfg['smtp_user'];
            $this->put("MAIL FROM:<{$fromEmail}>");
            $this->expect([250], 'MAIL FROM');

            foreach ($to as $rcpt) {
                $this->put("RCPT TO:<{$rcpt['email']}>");
                $this->expect([250, 251], 'RCPT TO');
            }

            $this->put('DATA');
            $this->expect([354], 'DATA');
            $this->put($this->buildMessage($to, $subject, $htmlBody, $textBody));
            $this->put('.');
            $this->expect([250], 'message body');

            $this->put('QUIT');
        } finally {
            if ($this->sock) { @fclose($this->sock); $this->sock = null; }
        }
    }

    private function buildMessage(array $to, string $subject, string $html, string $text): string {
        $fromEmail = $this->cfg['mail_from'] ?: $this->cfg['smtp_user'];
        $fromName  = $this->cfg['mail_from_name'] ?? 'Anima | Project manager';
        $boundary  = 'b' . bin2hex(random_bytes(12));

        $toHeader = implode(', ', array_map(
            fn($r) => $r['name'] ? $this->encodeHeader($r['name']) . " <{$r['email']}>" : $r['email'],
            $to
        ));

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->encodeHeader($fromName) . " <{$fromEmail}>",
            'To: ' . $toHeader,
            'Subject: ' . $this->encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@'
                . ($this->cfg['smtp_ehlo'] ?: (parse_url(site_url(), PHP_URL_HOST) ?: 'localhost')) . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        // ONE OR SEVERAL REPLY-TO ADDRESSES (David, 2026-11-11: "can we add multiple
        // emails to the mail reply to?"). config.php may give a list, ['a@x', 'b@y'],
        // or one string with commas; the old single address still works. Anything
        // that is not a plain address is dropped rather than written into a header,
        // so a stray line break can never add a header of its own.
        $replyTo = $this->cfg['mail_reply_to'] ?? '';
        $replyTo = is_array($replyTo) ? $replyTo : explode(',', (string)$replyTo);
        $replyTo = array_filter(array_map('trim', $replyTo),
            fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL) !== false);
        if ($replyTo) {
            $headers[] = 'Reply-To: ' . implode(', ', array_map(fn($a) => "<{$a}>", $replyTo));
        }

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($text) . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($html) . "\r\n";
        $body .= "--{$boundary}--";

        // Dot-stuffing: a line holding a single '.' would end DATA early.
        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        return preg_replace('/^\./m', '..', $message);
    }

    private function encodeHeader(string $s): string {
        return preg_match('/[^\x20-\x7E]/', $s)
            ? '=?UTF-8?B?' . base64_encode($s) . '?='
            : $s;
    }
}

// ---------------------------------------------------------------------------
// Ticket notifications
// ---------------------------------------------------------------------------

/**
 * The site's own address, with $path on the end: every link an email carries.
 *
 * ⚠ ONE PLACE, AND IT CAN BE SET. Seven functions used to build this each for
 * themselves from the request, falling back to "mana.davidsaga.com" when there
 * was no request to read. The move to anima-mana.com (David, 2026-11-09) is the
 * reason it now reads `site_url` from config.php first: an email written during
 * the move, or by a page reached through the old address, still links to the
 * new one. With site_url unset it behaves exactly as before: this request's
 * scheme, host and folder.
 */
function site_url(string $path = ''): string {
    global $config;
    $base = trim((string)($config['site_url'] ?? ''));
    if ($base === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
        $base   = "{$scheme}://{$host}{$dir}";
    }
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

/** Absolute URL to a ticket, for links inside notification emails. */
function ticket_url(int $ticketId): string {
    return site_url("ticket.php?id={$ticketId}");
}

/**
 * Formerly: email the people a ticket comment named. Formerly also, below:
 * email whoever a ticket was assigned to. Since 2026-11-17 both ring the bell
 * instead, and the twice-a-day summary is the email; see includes/digest.php.
 */
function notify_mentioned(PDO $pdo, int $ticketId, array $crewIds, string $body, string $byWho): array {
    // ⚠ NO EMAIL ANY MORE (David, 2026-11-17: "instead of sending an email for
    // each mention, new task or comment, let's send 2 instead"). This now rings
    // the bell, and the 9 am / 5 pm Pacific summary (includes/digest.php) is the
    // email. Kept under its old name so a caller written before the change
    // still works; new code calls the digest.php function directly.
    require_once __DIR__ . '/digest.php';
    // The comment is not known here; the bell line still links to the ticket.
    return ticket_ring_mentioned($pdo, $ticketId, 0, $crewIds, $byWho);
}

function notify_assignee(PDO $pdo, int $ticketId, string $reason = 'assigned'): array {
    // ⚠ NO EMAIL ANY MORE (David, 2026-11-17: "instead of sending an email for
    // each mention, new task or comment, let's send 2 instead"). This now rings
    // the bell, and the 9 am / 5 pm Pacific summary (includes/digest.php) is the
    // email. Kept under its old name so a caller written before the change
    // still works; new code calls the digest.php function directly.
    require_once __DIR__ . '/digest.php';
    return ticket_ring_assignee($pdo, $ticketId, $reason);
}

/**
 * Tell the administrators that somebody was hired, and by whom.
 *
 * David, 2026-10-21: once supervisors can press Hire, a hire "should trigger an
 * email for the admins stating who and whom hired the slot". Sent on EVERY hire,
 * whoever presses it, so the admins hear about all of them in one shape. The
 * person who did it is not emailed about their own click.
 *
 * Addresses: the login's own email (Users, migration 049) first, else the linked
 * crew member's, the same rule the kick-off sign-off uses.
 *
 * Never throws; returns [sent?, a sentence] like the other notifiers, and says
 * when there was nobody to tell.
 *
 * @param array $h  project_id, project_code, project_name, candidate, slot, step,
 *                  by (display name), rate_note (or '')
 */
function notify_admins_of_hire(PDO $pdo, array $h): array {
    global $config;
    if (!SmtpMailer::isConfigured($config)) {
        return [false, 'Email is not set up on this server, so the administrators were not told about this hire.'];
    }

    $ownEmail = column_exists($pdo, 'users', 'email')
        ? "NULLIF(TRIM(COALESCE(u.email, '')), '')" : 'NULL';
    $me = (int)($_SESSION['user_id'] ?? 0);
    $q = $pdo->prepare(
        "SELECT COALESCE(NULLIF(u.display_name, ''), u.username) AS name,
                COALESCE({$ownEmail}, NULLIF(TRIM(COALESCE(c.email, '')), '')) AS email
           FROM users u LEFT JOIN crew c ON c.id = u.crew_id
          WHERE u.role = 'admin' AND u.active = 1 AND u.id <> ?"
    );
    $q->execute([$me]);
    $recipients = [];
    $noEmail = [];
    foreach ($q->fetchAll() as $a) {
        if ($a['email']) $recipients[] = ['email' => $a['email'], 'name' => $a['name']];
        else             $noEmail[]    = (string)$a['name'];
    }
    if (!$recipients) {
        return [false, $noEmail
            ? 'The administrators were not emailed about this hire: ' . implode(', ', $noEmail)
              . ' ' . (count($noEmail) === 1 ? 'has' : 'have') . ' no email address on Users.'
            : ''];
    }

    $url    = site_url('crew.php?project=' . (int)$h['project_id']);
    $esc    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    $where = $h['slot'] !== '' ? $h['slot'] : ($h['step'] !== '' ? $h['step'] : 'no position yet');
    $code  = $h['project_code'] !== '' ? $h['project_code'] : 'Anima';
    $subject = sprintf('[%s] %s hired %s', $code, $h['by'], $h['candidate']);
    $line = sprintf('%s hired %s into %s on %s.', $h['by'], $h['candidate'], $where, $h['project_name']);

    $html = '<div style="font-family:system-ui,Segoe UI,Arial,sans-serif;font-size:14px;color:#1c2733;">'
        . '<p><strong>' . $esc($h['by']) . '</strong> hired <strong>' . $esc($h['candidate']) . '</strong> into <strong>'
        . $esc($where) . '</strong> on ' . $esc($h['project_name']) . '.</p>'
        . ($h['step'] !== '' ? '<p style="color:#5a6a7a;margin:0 0 12px;">Department: ' . $esc($h['step']) . '</p>' : '')
        . ($h['rate_note'] !== '' ? '<p>' . $esc($h['rate_note']) . '</p>' : '')
        . '<p style="margin-top:16px;"><a href="' . $esc($url) . '" '
        . 'style="background:#2f6fb2;color:#fff;padding:9px 16px;border-radius:6px;text-decoration:none;display:inline-block;">'
        . 'Open Staffing</a></p>'
        . '<p style="color:#8a97a3;font-size:12px;margin-top:18px;">Anima | Project manager</p>'
        . '</div>';
    $text = $line . "\n"
        . ($h['step'] !== '' ? 'Department: ' . $h['step'] . "\n" : '')
        . ($h['rate_note'] !== '' ? "\n" . $h['rate_note'] . "\n" : '')
        . "\nOpen Staffing: {$url}\n";

    try {
        (new SmtpMailer($config))->send($recipients, $subject, $html, $text);
        $said = 'Emailed the administrators (' . implode(', ', array_column($recipients, 'name')) . ') about this hire.';
        if ($noEmail) $said .= ' ' . implode(', ', $noEmail) . (count($noEmail) === 1
            ? ' has no email address on Users and was not reached.'
            : ' have no email address on Users and were not reached.');
        return [true, $said];
    } catch (Throwable $e) {
        error_log('[Anima hire mail] ' . $e->getMessage());
        return [false, 'Hired, but the email to the administrators failed: ' . $e->getMessage()];
    }
}

/**
 * Formerly: one email per person for the tasks a meeting transcript gave them.
 * Since 2026-11-17 each card rings that person's bell, and the twice-a-day
 * summary is the one email; see includes/digest.php.
 */
function notify_transcript_tasks(PDO $pdo, array $byCrew): array {
    // ⚠ NO EMAIL ANY MORE (David, 2026-11-17: "instead of sending an email for
    // each mention, new task or comment, let's send 2 instead"). This now rings
    // the bell, and the 9 am / 5 pm Pacific summary (includes/digest.php) is the
    // email. Kept under its old name so a caller written before the change
    // still works; new code calls the digest.php function directly.
    require_once __DIR__ . '/digest.php';
    return ticket_ring_transcript($pdo, $byCrew);
}

/**
 * The address that opens one task's panel on the Board.
 *
 * spell.php reads `open` and clicks that task's button for you, so a mention
 * email lands on the conversation rather than on a screen of four hundred rows
 * with "somewhere in here" as the instruction.
 */
function board_task_url(int $projectId, int $stepId, string $itemKey): string {
    return site_url("spell.php?project={$projectId}"
         . '&open=' . rawurlencode($stepId . ':' . $itemKey));
}

/**
 * Formerly: email the people a comment on a BOARD TASK named. Since 2026-11-17
 * the bell (rung by the caller) and the twice-a-day summary do it; see
 * includes/digest.php.
 */
function notify_mentioned_task(PDO $pdo, array $crewIds, string $body,
                               string $byWho, array $ctx): array {
    // ⚠ NO EMAIL ANY MORE (David, 2026-11-17: "instead of sending an email for
    // each mention, new task or comment, let's send 2 instead"). Every caller
    // already rang the bell with notify_add() before calling this, so there
    // is nothing left to do: the 9 am / 5 pm Pacific summary
    // (includes/digest.php) is the email. Kept under its old name so a caller written before the change
    // still works; new code calls the digest.php function directly.
    return [false, ''];
}
