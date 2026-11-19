<?php
// The iPhone install of the Reviews app: a configuration profile holding a Web Clip.
//
// David, 2026-11-19: "the install button needs to do the actual install, not just tell
// the user how to do it", and then: "we've developed apps already that do the install
// automatically in iPhone, think of a workaround and make it happen".
//
// ⚠ WHY A PROFILE. Safari gives a web page no way to put itself on the home screen
// (there is no install window like Android's). What Apple does allow is a configuration
// profile, the same kind of file a company uses to set up its phones, and one of the
// things a profile can carry is a "Web Clip": a home-screen icon that opens a web
// address full screen, like an app. So the Install button on review.php opens this
// page in Safari; iOS asks "Allow" to download it, then the person confirms once in
// Settings (Profile Downloaded → Install), and the Reviews icon appears.
//
// ⚠ IT IS NOT SIGNED, so Settings labels it "Not Verified" (in red) on the Install
// screen. It still installs. Signing it needs a code-signing certificate and its
// private key on the server; that is a later step if the label bothers anyone.
//
// The UUIDs are fixed on purpose: installing it again replaces the same icon rather
// than adding a second one. Deleting the icon removes it (IsRemovable); so does
// Settings → General → VPN & Device Management → Anima Reviews → Remove.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';   // site_url(): the live address, from config.php
require_login();

$x = fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

$url  = site_url('review.php');
$icon = @file_get_contents(__DIR__ . '/assets/icon-192.png');
$iconXml = $icon !== false
    ? "\n    <key>Icon</key>\n    <data>" . base64_encode($icon) . "</data>\n    <key>Precomposed</key>\n    <true/>"
    : '';

header('Content-Type: application/x-apple-aspen-config');
header('Content-Disposition: attachment; filename="Anima-Reviews.mobileconfig"');
header('Cache-Control: no-store');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>PayloadContent</key>
  <array>
   <dict>
    <key>PayloadType</key>
    <string>com.apple.webClip.managed</string>
    <key>PayloadVersion</key>
    <integer>1</integer>
    <key>PayloadIdentifier</key>
    <string>com.mana.anima.reviews.webclip</string>
    <key>PayloadUUID</key>
    <string>FBEB5F98-9605-416C-90E3-B3EB9BC545C9</string>
    <key>PayloadDisplayName</key>
    <string>Reviews</string>
    <key>Label</key>
    <string>Reviews</string>
    <key>URL</key>
    <string><?= $x($url) ?></string>
    <key>FullScreen</key>
    <true/>
    <key>IgnoreManifestScope</key>
    <true/>
    <key>IsRemovable</key>
    <true/><?= $iconXml ?>

   </dict>
  </array>
  <key>PayloadType</key>
  <string>Configuration</string>
  <key>PayloadVersion</key>
  <integer>1</integer>
  <key>PayloadIdentifier</key>
  <string>com.mana.anima.reviews</string>
  <key>PayloadUUID</key>
  <string>C81A25DF-B4DF-4BC0-9813-17EE8F2B7343</string>
  <key>PayloadDisplayName</key>
  <string>Anima Reviews</string>
  <key>PayloadDescription</key>
  <string>Puts the Anima Reviews app on your home screen: approve or send for retake on the go.</string>
  <key>PayloadOrganization</key>
  <string>Mana Animation Studio</string>
  <key>PayloadRemovalDisallowed</key>
  <false/>
</dict>
</plist>
