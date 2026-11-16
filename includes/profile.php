<?php
// A person's own profile: phone, bio and picture (migration 075).
//
// David, 2026-10-30: the profile on Home's General info tab. These three
// functions lived in includes/home.php and were lifted here WORD FOR WORD on
// 2026-11-12, when the portrait in a Realm house became a second way to read and
// change the same profile (David: "a portrait in the wall: so they can see theirs
// and others bio and profile from Anima, and update it").
//
// ⚠ WHY ITS OWN FILE: includes/home.php loads the money code for the invoice
// tab, and the Realm must never open the finance connection (see the note at the
// top of includes/realm.php). One copy of the saving rules, two screens.

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/uploads.php';      // accept_upload(), for the profile picture
require_once __DIR__ . '/birthday.php';     // birthday_available(), birthday_valid() (migration 100)

/** Has migration 075 run? The profile's own fields: phone, bio and picture. */
function home_profile_available(PDO $pdo): bool {
    return column_exists($pdo, 'crew', 'photo_stored');
}

/** A person's profile: name and email always; phone, bio and picture after 075. */
function home_profile(PDO $pdo, int $crewId): array {
    $cols = 'id, name, email' . (home_profile_available($pdo) ? ', phone, bio, photo_stored, photo_mime' : '')
          . (birthday_available($pdo) ? ', birth_month, birth_day' : '');
    $q = $pdo->prepare("SELECT {$cols} FROM crew WHERE id = ?");
    $q->execute([$crewId]);
    return ($q->fetch() ?: []) + ['name' => '', 'email' => '', 'phone' => null, 'bio' => null,
                                   'photo_stored' => null, 'photo_mime' => null,
                                   'birth_month' => null, 'birth_day' => null];
}

/**
 * Save a person's OWN email, phone, bio and picture. The crew id is the caller's
 * effective_crew_id(), never anything the form sent (see the allow-list entry
 * for home.php in includes/auth.php). The name is not written here: it is what
 * every screen and statement shows them by, so it stays with Production.
 *
 * THE EMAIL (David, 2026-11-16: "let's include email to the invoice details and
 * to the profile"; asked, he chose letting them edit it here). It is crew.email,
 * the one address Anima has: notifications and invites go to it and their
 * invoice prints it. Logins are by username, so changing it does not lock
 * anybody out. It cannot be emptied from here (Anima would lose the way to
 * reach them); Production can still clear it on the person page.
 *
 * @return string a sentence for the flash message
 */
function home_save_profile(PDO $pdo, int $crewId, array $in, ?array $photo, bool $removePhoto): string {
    if (!home_profile_available($pdo)) {
        throw new RuntimeException('Your profile needs database update 075, which has not been applied yet.');
    }
    $phone = trim((string)($in['phone'] ?? ''));
    if (mb_strlen($phone) > 50) throw new RuntimeException('That phone number is too long.');
    if ($phone !== '' && !preg_match('/^[0-9+()\-.\s\/ext]+$/i', $phone)) {
        throw new RuntimeException('A phone number can only have digits, spaces and + ( ) - . characters.');
    }
    // Only touched when the form sent it, so another caller leaves it alone.
    $email = null;
    if (array_key_exists('email', $in)) {
        $email = trim((string)$in['email']);
        if ($email === '') throw new RuntimeException('Your email cannot be empty: it is how Anima reaches you. Nothing was saved.');
        if (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('"' . $email . '" does not look like an email address. Nothing was saved.');
        }
    }
    $bio = trim((string)($in['bio'] ?? ''));
    if (mb_strlen($bio) > 2000) throw new RuntimeException('Keep the bio under 2,000 characters.');

    $old = home_profile($pdo, $crewId);
    $stored = $old['photo_stored'] ?: null;
    $mime = $old['photo_mime'] ?: null;
    $said = 'Profile saved.';

    if ($photo && ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        // A picture, and only a picture: the upload rules every file goes
        // through, then a check that what arrived is an image.
        $errors = [];
        $got = accept_upload($photo, ensure_upload_dir(), $errors);
        if (!$got) throw new RuntimeException(implode(' ', $errors) ?: 'The picture could not be saved.');
        if (!attachment_is_image($got['mime'])) {
            @unlink(upload_dir_path() . '/' . $got['stored']);
            throw new RuntimeException('A profile picture has to be an image (JPG, PNG, GIF or WebP).');
        }
        $removePhoto = true;                 // the old one goes
        $newStored = $got['stored']; $newMime = $got['mime'];
    }
    if ($removePhoto && $stored) {
        @unlink(upload_dir_path() . '/' . basename($stored));
        $stored = null; $mime = null;
        $said = isset($newStored) ? 'Profile and picture saved.' : 'Profile saved, picture removed.';
    }
    if (isset($newStored)) { $stored = $newStored; $mime = $newMime; $said = 'Profile and picture saved.'; }

    $pdo->prepare('UPDATE crew SET phone = ?, bio = ?, photo_stored = ?, photo_mime = ? WHERE id = ?')
        ->execute([$phone !== '' ? $phone : null, $bio !== '' ? $bio : null, $stored, $mime, $crewId]);
    if ($email !== null && $email !== (string)($old['email'] ?? '')) {
        $pdo->prepare('UPDATE crew SET email = ? WHERE id = ?')->execute([$email, $crewId]);
        $said = rtrim($said, '.') . '. Anima now reaches you at ' . $email . '.';
    }

    // THE BIRTHDAY (migration 100; David, 2026-11-16): month and day, never the
    // year, both optional and both or neither. Only touched when the form sent the
    // fields, so a form that does not offer them (or a database before 100) leaves
    // a date that is already there alone.
    if (birthday_available($pdo) && (array_key_exists('birth_month', $in) || array_key_exists('birth_day', $in))) {
        $bm = (int)($in['birth_month'] ?? 0);
        $bd = (int)($in['birth_day'] ?? 0);
        if ($bm === 0 && $bd === 0) {
            $bm = null; $bd = null;
        } elseif (!birthday_valid($bm, $bd)) {
            throw new RuntimeException('Your other details were saved, but that birthday is not a real date: pick a month and a day, or leave both empty.');
        }
        $pdo->prepare('UPDATE crew SET birth_month = ?, birth_day = ? WHERE id = ?')->execute([$bm, $bd, $crewId]);
    }
    return $said;
}
