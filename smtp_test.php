<?php
/**
 * Email diagnostic. Ported from STFC's smtp-diagnose.php, plus a real test send.
 *
 * Two things it does, in the order you need them:
 *   1. PROBE — tries every plausible host / port / encryption combination for a
 *      cPanel server using the credentials already in config.php, and reports
 *      which one authenticates. The working settings can then be read off the
 *      page instead of guessed at.
 *   2. SEND  — puts a real message through the configured settings, using the
 *      same SmtpMailer the ticket notifications use, and prints the full SMTP
 *      transcript either way.
 *
 * Nothing is ever typed in: credentials come from config.php and the password is
 * never displayed, only its length. The transcript redacts AUTH payloads —
 * base64 is encoding, not encryption.
 *
 * ADMIN ONLY. It reveals the sending address and whether a password is set, and
 * it can send mail — neither is something a viewer or editor account needs.
 */
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';
require_admin();

/** One probe: connect, greet, upgrade if needed, authenticate. */
function smtp_probe(string $host, int $port, string $secure, string $user, string $pass, int $timeout = 8): array {
    $steps = [];
    $t0 = microtime(true);
    $ms = fn() => (int)((microtime(true) - $t0) * 1000);

    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
    ]]);
    $dsn = ($secure === 'ssl' ? 'ssl://' : '') . $host;

    $sock = @stream_socket_client("{$dsn}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) {
        return ['ok' => false, 'stage' => 'connect',
                'error' => ($errstr ?: 'no route / refused') . ($errno ? " (errno {$errno})" : ''),
                'steps' => $steps, 'ms' => $ms()];
    }
    stream_set_timeout($sock, $timeout);
    $steps[] = 'TCP connected';

    $read = function () use ($sock) {
        $out = '';
        while (($l = fgets($sock, 515)) !== false) {
            $out .= $l;
            if (strlen($l) < 4 || $l[3] !== '-') break;
        }
        return trim($out);
    };
    $write = fn($c) => fwrite($sock, $c . "\r\n");

    $banner = $read();
    if ((int)substr($banner, 0, 3) !== 220) {
        fclose($sock);
        return ['ok' => false, 'stage' => 'greeting',
                'error' => $banner ?: 'no banner — wrong encryption for this port?',
                'steps' => $steps, 'ms' => $ms()];
    }
    $steps[] = 'Greeting: ' . substr($banner, 0, 60);

    $ehloName = $GLOBALS['config']['smtp_ehlo'] ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $write('EHLO ' . $ehloName);
    $ehlo = $read();
    if ((int)substr($ehlo, 0, 3) !== 250) {
        fclose($sock);
        return ['ok' => false, 'stage' => 'EHLO', 'error' => $ehlo, 'steps' => $steps, 'ms' => $ms()];
    }
    $steps[] = 'EHLO accepted';

    if ($secure === 'tls') {
        if (stripos($ehlo, 'STARTTLS') === false) {
            fclose($sock);
            return ['ok' => false, 'stage' => 'STARTTLS',
                    'error' => 'The server does not offer STARTTLS on this port.',
                    'steps' => $steps, 'ms' => $ms()];
        }
        $write('STARTTLS');
        $r = $read();
        if ((int)substr($r, 0, 3) !== 220) {
            fclose($sock);
            return ['ok' => false, 'stage' => 'STARTTLS', 'error' => $r, 'steps' => $steps, 'ms' => $ms()];
        }
        $up = @stream_socket_enable_crypto($sock, true,
            STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
        if (!$up) {
            fclose($sock);
            return ['ok' => false, 'stage' => 'TLS handshake',
                    'error' => error_get_last()['message'] ?? 'crypto negotiation failed',
                    'steps' => $steps, 'ms' => $ms()];
        }
        $steps[] = 'TLS established';
        $write('EHLO ' . $ehloName);
        $read();
    }

    $write('AUTH LOGIN');
    $r = $read();
    if ((int)substr($r, 0, 3) !== 334) {
        fclose($sock);
        return ['ok' => false, 'stage' => 'AUTH offered', 'error' => $r, 'steps' => $steps, 'ms' => $ms()];
    }
    $write(base64_encode($user));
    $read();
    $write(base64_encode($pass));
    $r = $read();
    $authOk = (int)substr($r, 0, 3) === 235;
    if ($authOk) $steps[] = 'Authenticated as ' . $user;

    $write('QUIT');
    fclose($sock);

    return ['ok' => $authOk, 'stage' => $authOk ? 'done' : 'AUTH credentials',
            'error' => $authOk ? null : $r, 'steps' => $steps, 'ms' => $ms()];
}

$results = [];
$ranProbe = false;
$sendLog = [];
$sendOk = null;
$sendMsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['probe'])) {
    $ranProbe = true;
    $user = $config['smtp_user'] ?? '';
    $pass = $config['smtp_pass'] ?? '';
    // Strip a leading "mail." so both spellings get tried from whatever is set.
    $domain = preg_replace('/^mail\./i', '', $config['smtp_host'] ?: ($_SERVER['HTTP_HOST'] ?? 'localhost'));

    // Every combination worth trying on cPanel, most likely first.
    $candidates = [
        [$domain,            465, 'ssl'],
        [$domain,            587, 'tls'],
        ['mail.' . $domain,  465, 'ssl'],
        ['mail.' . $domain,  587, 'tls'],
        ['localhost',        465, 'ssl'],
        ['localhost',        587, 'tls'],
        ['127.0.0.1',        587, 'tls'],
    ];
    foreach ($candidates as [$h, $p, $s]) {
        $results[] = ['host' => $h, 'port' => $p, 'secure' => $s] + smtp_probe($h, $p, $s, $user, $pass);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send'])) {
    $to = trim($_POST['to'] ?? '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $sendOk = false;
        $sendMsg = 'That is not a valid email address, so nothing was sent.';
    } elseif (!SmtpMailer::isConfigured($config)) {
        $sendOk = false;
        $sendMsg = 'SMTP is not configured in config.php — smtp_host, smtp_user and smtp_pass '
                 . 'all have to be filled in before anything can send.';
    } else {
        // Deliberately the SAME class the ticket notifications use. A test that
        // sends by some other route proves nothing about whether tickets work.
        $mailer = new SmtpMailer($config);
        try {
            $mailer->send(
                [['email' => $to, 'name' => '']],
                'Anima test message',
                '<p>This is a test from <strong>Anima | Project manager</strong>.</p>'
                . '<p>If you are reading it, ticket notifications will send too — they go '
                . 'through exactly this route.</p>',
                "This is a test from Anima | Project manager.\n\n"
                . "If you are reading it, ticket notifications will send too — they go "
                . "through exactly this route.\n"
            );
            $sendOk = true;
            $sendMsg = 'Sent to ' . $to . '. Check the inbox, and the spam folder if it is not there.';
        } catch (Throwable $e) {
            $sendOk = false;
            $sendMsg = 'Failed: ' . $e->getMessage();
        }
        $sendLog = $mailer->log;
    }
}

// Some shared hosts switch these off, which makes outbound SMTP from PHP
// impossible no matter what the settings say. Worth stating plainly rather than
// letting every probe fail at "connect" with no explanation.
$disabledFns = array_values(array_filter(
    ['stream_socket_client', 'stream_socket_enable_crypto'],
    fn($f) => !function_exists($f)
           || in_array($f, array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)
));

$configured = SmtpMailer::isConfigured($config);

$pageTitle = 'Email diagnostic';
require __DIR__ . '/includes/header.php';
?>

<p class="crumb"><a href="index.php">← All projects</a></p>
<h2 class="wizard-title">Email diagnostic</h2>

<p class="lead">
  Ticket notifications send through an authenticated mailbox, because PHP's own
  <code>mail()</code> cannot authenticate and anything it sends gets spam-foldered. This page
  works out which settings that mailbox needs, then proves them with a real message.
</p>

<?php if ($disabledFns): ?>
  <div class="flash flash-err">
    Your host has disabled <strong><?= e(implode(', ', $disabledFns)) ?></strong>. Outbound email
    from PHP is impossible until they switch these back on — everything below will fail at
    "connect" no matter what the settings say. Contact hosting support.
  </div>
<?php endif; ?>

<section class="panel">
  <h2>What config.php currently says</h2>
  <table>
    <tr><th>Setting</th><th>Value</th></tr>
    <tr><td><code>smtp_host</code></td><td><code><?= e($config['smtp_host'] ?: '(empty)') ?></code></td></tr>
    <tr><td><code>smtp_port</code></td><td><code><?= (int)($config['smtp_port'] ?? 0) ?></code></td></tr>
    <tr><td><code>smtp_secure</code></td>
        <td><code><?= e($config['smtp_secure'] ?? '(empty)') ?></code>
        <?php
        $port = (int)($config['smtp_port'] ?? 0);
        $sec  = strtolower((string)($config['smtp_secure'] ?? ''));
        $mismatch = ($port === 465 && $sec !== 'ssl') || ($port === 587 && $sec !== 'tls');
        if ($mismatch): ?>
          <span class="warn-text">— port <?= $port ?> should be
          '<?= $port === 465 ? 'ssl' : 'tls' ?>'. Mixed settings hang rather than fail.</span>
        <?php endif; ?>
        </td></tr>
    <tr><td><code>smtp_user</code></td><td><code><?= e($config['smtp_user'] ?: '(empty)') ?></code></td></tr>
    <tr><td><code>smtp_pass</code></td>
        <td><?= !empty($config['smtp_pass'])
              ? '<span class="ok">set (' . strlen((string)$config['smtp_pass']) . ' characters)</span>'
              : '<span class="err">EMPTY — nothing will send</span>' ?></td></tr>
    <tr><td><code>mail_from</code></td><td><code><?= e($config['mail_from'] ?: '(empty)') ?></code></td></tr>
    <tr><td><code>mail_reply_to</code></td><td><code><?= e($config['mail_reply_to'] ?: '(none)') ?></code></td></tr>
  </table>
  <p class="muted small">The password itself is never shown — only whether one is set, and how long it is.</p>
</section>

<section class="panel">
  <h2>1 · Find the working settings</h2>
  <p class="muted small">
    Tries every plausible host, port and encryption combination for a cPanel server, using the
    username and password already in <code>config.php</code>. Whichever row comes back green is
    what your config should say.
  </p>
  <form method="post">
    <div class="actions">
      <button type="submit" name="probe" value="1" <?= empty($config['smtp_user']) ? 'disabled' : '' ?>>Run the probe</button>
      <span class="hint">Takes up to a minute — each failing combination waits for its timeout.</span>
    </div>
    <?php if (empty($config['smtp_user'])): ?>
      <small>Fill in <code>smtp_user</code> and <code>smtp_pass</code> in <code>config.php</code> first —
             there is nothing to authenticate with yet.</small>
    <?php endif; ?>
  </form>

  <?php if ($ranProbe): ?>
    <?php $winner = null; foreach ($results as $r) { if ($r['ok']) { $winner = $r; break; } } ?>
    <?php if ($winner): ?>
      <div class="flash flash-ok" style="margin-top:14px;">
        <strong>Found a working combination.</strong> Put these into <code>config.php</code>:
      </div>
      <pre class="ticket-body">'smtp_host'        => '<?= e($winner['host']) ?>',
'smtp_port'        => <?= (int)$winner['port'] ?>,
'smtp_secure'      => '<?= e($winner['secure']) ?>',
'smtp_verify_peer' => false,</pre>
    <?php else: ?>
      <div class="flash flash-err" style="margin-top:14px;">
        Nothing authenticated. The most informative row is the one that got <em>furthest</em> —
        read the "stage reached" column.
      </div>
      <ul class="dash-attention small">
        <li><strong>Every row stopped at "connect"</strong> — the host blocks outbound SMTP from
            PHP. Ask hosting support to allow it.</li>
        <li><strong>Something reached "AUTH credentials"</strong> — host and port are right, the
            mailbox password is wrong. Reset it in cPanel → Email Accounts and update
            <code>smtp_pass</code>.</li>
        <li><strong>Stopped at "greeting"</strong> — wrong encryption for that port. 465 needs
            <code>ssl</code>, 587 needs <code>tls</code>.</li>
      </ul>
    <?php endif; ?>

    <table style="margin-top:12px;">
      <thead><tr><th></th><th>Host</th><th>Port</th><th>Encryption</th><th>Stage reached</th><th>Detail</th><th>Time</th></tr></thead>
      <tbody>
      <?php foreach ($results as $r): ?>
        <tr>
          <td><?= $r['ok'] ? '<span class="ok">✓</span>' : '<span class="err">✗</span>' ?></td>
          <td><code><?= e($r['host']) ?></code></td>
          <td><?= (int)$r['port'] ?></td>
          <td><?= e($r['secure']) ?></td>
          <td><?= e($r['stage']) ?></td>
          <td class="muted small"><?= e(mb_strimwidth((string)($r['error'] ?? 'OK'), 0, 120, '…')) ?></td>
          <td class="muted small"><?= (int)$r['ms'] ?> ms</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>2 · Send a real test</h2>
  <p class="muted small">
    Uses the settings exactly as they stand, through the same code the ticket notifications use —
    a test that sent by any other route would prove nothing about whether tickets work.
  </p>

  <?php if ($sendMsg !== null): ?>
    <div class="flash flash-<?= $sendOk ? 'ok' : 'err' ?>"><?= e($sendMsg) ?></div>
  <?php endif; ?>

  <form method="post" class="inline-form">
    <label>Send to
      <input type="email" name="to" required
             value="<?= e($_POST['to'] ?? ($config['mail_reply_to'] ?: '')) ?>" placeholder="you@example.com">
    </label>
    <button type="submit" name="send" value="1" <?= $configured ? '' : 'disabled' ?>>Send test message</button>
    <?php if (!$configured): ?>
      <small><code>smtp_host</code>, <code>smtp_user</code> and <code>smtp_pass</code> must all be
             set in <code>config.php</code> before anything can send.</small>
    <?php endif; ?>
  </form>

  <?php if ($sendLog): ?>
    <h3>SMTP transcript</h3>
    <p class="muted small">Every line the server and this app exchanged. <code>&gt;</code> is what we
       sent, <code>&lt;</code> is what came back. Credentials are redacted.</p>
    <pre class="ticket-body"><?= e(implode("\n", $sendLog)) ?></pre>
  <?php endif; ?>
</section>

<p class="muted small">
  With <code>smtp_host</code> left empty, email is simply off: tickets still work and every screen
  says notifications are switched off rather than pretending to have sent one.
</p>

</div>
</body></html>
