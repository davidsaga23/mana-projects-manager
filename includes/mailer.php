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
                . ($this->cfg['smtp_ehlo'] ?: 'mana.davidsaga.com') . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        if (!empty($this->cfg['mail_reply_to'])) {
            $headers[] = 'Reply-To: <' . $this->cfg['mail_reply_to'] . '>';
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

/** Absolute URL to a ticket, for links inside notification emails. */
function ticket_url(int $ticketId): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'mana.davidsaga.com';
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    return "{$scheme}://{$host}{$dir}/ticket.php?id={$ticketId}";
}

/**
 * Notify whoever a ticket is assigned to.
 *
 * NEVER THROWS, and never returns silently: a mail outage must not stop somebody
 * filing a ticket, but "we didn't email anyone" has to reach the screen. Every
 * return path carries a sentence the caller shows as a flash message.
 *
 * The two cases this platform has and STFC did not:
 *   - a crew SLOT ("Animation 02") has no email because nobody is in the job yet
 *   - a TEAM ("Animation") is not a person; the mail fans out to active crew
 *     whose role names that team
 * Both are legitimate owners of a ticket, so neither is an error — but both are
 * reported, because "assigned and notified" and "assigned, nobody told" look
 * identical otherwise.
 *
 * @return array{0:bool, 1:string}  [sent, message for the user]
 */
/**
 * Tell the people a comment named, when the author asked for it.
 *
 * ⚠ ONLY WHEN ASKED. The box on the comment form is unticked by default, and
 * that is the whole design: this mailer opens SMTP INLINE, so the page waits for
 * the mail server before it comes back (DECISIONS — up to the 20-second
 * timeout). Sending on every mention would make every comment with an @ in it
 * slow, including the ones that are a note for later. The tick is the author
 * saying "this one is worth the wait".
 *
 * Returns [sent?, a sentence], in the shape notify_assignee() uses, and NAMES
 * ANYBODY IT COULD NOT REACH. A mention that silently goes nowhere is worse than
 * no mention at all: the author has already moved on believing it landed.
 *
 * @param int[] $crewIds the people named, from ticket_parse_mentions()
 */
function notify_mentioned(PDO $pdo, int $ticketId, array $crewIds, string $body, string $byWho): array {
    global $config;
    if (!$crewIds) return [false, ''];

    if (!SmtpMailer::isConfigured($config)) {
        return [false, 'Email is not set up on this server, so nobody was emailed.'];
    }

    $ids = implode(',', array_map('intval', $crewIds));
    $people = $pdo->query(
        "SELECT id, name, email FROM crew WHERE id IN ({$ids})"
    )->fetchAll();

    $recipients = [];
    $noEmail = [];
    foreach ($people as $c) {
        $addr = trim((string)($c['email'] ?? ''));
        if ($addr !== '') $recipients[] = ['email' => $addr, 'name' => $c['name']];
        else              $noEmail[] = (string)$c['name'];
    }

    $q = $pdo->prepare(
        'SELECT t.id, t.seq, t.title, t.type, p.code AS project_code, p.name AS project_name
           FROM tickets t JOIN projects p ON p.id = t.project_id WHERE t.id = ?'
    );
    $q->execute([$ticketId]);
    $t = $q->fetch();
    if (!$t) return [false, 'That ticket no longer exists, so nobody was emailed.'];

    if (!$recipients) {
        return [false, 'Nobody was emailed: ' . implode(', ', $noEmail)
            . ' ' . (count($noEmail) === 1 ? 'has' : 'have')
            . ' no email address on file. Add one on the Staffing screen.'];
    }

    $label = ($t['project_code'] ?? '') . '-' . (int)$t['seq'];
    $subject = sprintf('[%s] %s mentioned you on %s', $t['project_code'], $byWho, $label);
    $url = ticket_url((int)$t['id']);
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    // The comment itself, trimmed. Enough to know whether it needs answering now
    // without turning the mail into a copy of the ticket.
    $snippet = mb_substr(trim($body), 0, 600);
    if (mb_strlen(trim($body)) > 600) $snippet .= '…';

    $html = '<div style="font-family:system-ui,Segoe UI,Arial,sans-serif;font-size:14px;color:#1c2733;">'
        . '<p><strong>' . $esc($byWho) . '</strong> mentioned you on <strong>' . $esc($label) . '</strong>.</p>'
        . '<h2 style="font-size:17px;margin:12px 0 6px;">' . $esc($t['title']) . '</h2>'
        . '<p style="color:#5a6a7a;margin:0 0 12px;">' . $esc($t['project_name']) . '</p>'
        . '<div style="background:#f4f6f8;padding:10px 12px;border-radius:6px;white-space:pre-wrap;">'
        . $esc($snippet) . '</div>'
        . '<p style="margin-top:16px;"><a href="' . $esc($url) . '" '
        . 'style="background:#2f6fb2;color:#fff;padding:9px 16px;border-radius:6px;text-decoration:none;display:inline-block;">'
        . 'Open ' . ($t['type'] === 'ticket' ? 'ticket' : 'task') . '</a></p>'
        . '<p style="color:#8a97a3;font-size:12px;margin-top:18px;">Anima | Project manager</p>'
        . '</div>';

    $text = "{$byWho} mentioned you on {$label}.\n\n{$t['title']}\n{$t['project_name']}\n\n"
        . $snippet . "\n\nOpen: {$url}\n";

    try {
        (new SmtpMailer($config))->send($recipients, $subject, $html, $text);
        $said = 'Emailed ' . implode(', ', array_column($recipients, 'name')) . '.';
        if ($noEmail) {
            $said .= ' ' . implode(', ', $noEmail) . ' ' . (count($noEmail) === 1 ? 'has' : 'have')
                   . ' no email address on file and ' . (count($noEmail) === 1 ? 'was' : 'were')
                   . ' not reached.';
        }
        return [true, $said];
    } catch (Throwable $e) {
        error_log('[Anima mention mail] ' . $e->getMessage());
        return [false, 'The mention email failed: ' . $e->getMessage()];
    }
}

function notify_assignee(PDO $pdo, int $ticketId, string $reason = 'assigned'): array {
    global $config;

    if (!SmtpMailer::isConfigured($config)) {
        return [false, 'Email is not set up on this server, so no notification was sent.'];
    }

    $q = $pdo->prepare(
        'SELECT t.id, t.seq, t.title, t.type, t.description, t.priority, t.due_date,
                p.code AS project_code, p.name AS project_name,
                c.name AS crew_name, c.email AS crew_email, c.is_team, c.is_placeholder,
                u.display_name AS reporter,
                st.name AS status_name,
                s.episode, s.shot_code, a.name AS asset_name, ps.name AS step_name
           FROM tickets t
           JOIN projects p        ON p.id  = t.project_id
           LEFT JOIN crew c       ON c.id  = t.assigned_crew_id
           LEFT JOIN users u      ON u.id  = t.created_by
           LEFT JOIN statuses st  ON st.id = t.status_id
           LEFT JOIN shots s      ON s.id  = t.shot_id
           LEFT JOIN assets a     ON a.id  = t.asset_id
           LEFT JOIN pipeline_steps ps ON ps.id = t.step_id
          WHERE t.id = ?'
    );
    $q->execute([$ticketId]);
    $t = $q->fetch();
    if (!$t) return [false, 'That ticket no longer exists, so nobody was notified.'];

    $recipients = [];
    if (!empty($t['crew_email'])) {
        $recipients[] = ['email' => trim($t['crew_email']), 'name' => $t['crew_name']];
    } elseif ((int)$t['is_team'] === 1) {
        // A team assignee fans out to every active person whose role names it,
        // so "Animation" reaches the animators.
        $teamQ = $pdo->prepare(
            "SELECT name, email FROM crew
              WHERE active = 1 AND is_team = 0 AND COALESCE(is_placeholder, 0) = 0
                AND email IS NOT NULL AND email <> ''
                AND LOWER(role) LIKE CONCAT('%', LOWER(?), '%')"
        );
        $teamQ->execute([$t['crew_name']]);
        foreach ($teamQ->fetchAll() as $m) {
            $recipients[] = ['email' => trim($m['email']), 'name' => $m['name']];
        }
        if (!$recipients) {
            return [false, 'Assigned to the team "' . $t['crew_name']
                . '", but nobody active has that word in their role and an email address, so no notification went out.'];
        }
    } elseif ((int)($t['is_placeholder'] ?? 0) === 1) {
        return [false, 'Assigned to the slot "' . $t['crew_name']
            . '", which nobody fills yet, so there was nobody to notify. Putting somebody into that position on the Staffing screen fixes this.'];
    }

    if (!$recipients) {
        return [false, ($t['crew_name'] ?? 'The assignee')
            . ' has no email address on file, so no notification was sent. Add one on the Staffing screen.'];
    }

    $label = ($t['project_code'] ?? '') . '-' . (int)$t['seq'];
    $isTicket = $t['type'] === 'ticket';
    $verb = $reason === 'status' ? 'updated' : 'assigned to you';

    $subject = sprintf('[%s] %s %s, %s',
        $t['project_code'], $label, $reason === 'status' ? 'updated' : 'assigned', $t['title']);

    $context = [];
    $context[] = 'Project: ' . $t['project_name'];
    if ($t['episode'] || $t['shot_code']) {
        $shot = trim((string)$t['episode']) !== ''
            ? $t['episode'] . '_' . $t['shot_code'] : $t['shot_code'];
        $context[] = 'Shot: ' . $shot;
    }
    if ($t['asset_name']) $context[] = 'Asset: ' . $t['asset_name'];
    if ($t['step_name'])  $context[] = 'Step: ' . $t['step_name'];
    $context[] = 'Priority: ' . ucfirst($t['priority']);
    if ($t['status_name']) $context[] = 'Status: ' . $t['status_name'];
    if ($t['due_date'])    $context[] = 'Due: ' . date('M j, Y', strtotime($t['due_date']));
    if ($t['reporter'])    $context[] = 'Raised by: ' . $t['reporter'];

    $url = ticket_url((int)$t['id']);
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    $html = '<div style="font-family:system-ui,Segoe UI,Arial,sans-serif;font-size:14px;color:#1c2733;">'
        . '<p><strong>' . $esc($label) . '</strong> has been ' . $esc($verb) . '.</p>'
        . '<h2 style="font-size:17px;margin:12px 0 6px;">' . $esc($t['title']) . '</h2>'
        . '<p style="color:#5a6a7a;margin:0 0 12px;">' . implode(' &middot; ', array_map($esc, $context)) . '</p>'
        . ($t['description']
            ? '<div style="background:#f4f6f8;padding:10px 12px;border-radius:6px;white-space:pre-wrap;">'
              . $esc($t['description']) . '</div>'
            : '')
        . '<p style="margin-top:16px;"><a href="' . $esc($url) . '" '
        . 'style="background:#2f6fb2;color:#fff;padding:9px 16px;border-radius:6px;text-decoration:none;display:inline-block;">'
        . 'Open ' . ($isTicket ? 'ticket' : 'task') . '</a></p>'
        . '<p style="color:#8a97a3;font-size:12px;margin-top:18px;">Anima | Project manager</p>'
        . '</div>';

    $text = "{$label} has been {$verb}.\n\n{$t['title']}\n"
        . implode(' | ', $context) . "\n\n"
        . ($t['description'] ? $t['description'] . "\n\n" : '')
        . "Open: {$url}\n";

    try {
        (new SmtpMailer($config))->send($recipients, $subject, $html, $text);
        return [true, 'Notified ' . implode(', ', array_column($recipients, 'email')) . '.'];
    } catch (Throwable $e) {
        error_log('[Anima ticket mail] ' . $e->getMessage());
        return [false, 'The notification email failed: ' . $e->getMessage()];
    }
}
