<?php
/**
 * ------------------------------------------------------------
 * includes/avatar.php
 * Profile photos without the per-page cost.
 *
 * Photos are stored in the database as data URIs (students.photo / teachers.photo, see
 * database/supabase_profile_photos.sql). Inlining that 20-40 KB string into every page made each
 * request read it from Postgres and send it to the browser again. Now:
 *   - the page links to avatar.php?v=<hash>, which the browser caches for a year
 *     (the hash changes when the photo changes, so a new photo shows at once);
 *   - the hash is looked up once per login and kept in $_SESSION, so pages make no photo query.
 * Anything that changes a photo must call forget_photo_cache() (student/profile.php does).
 * ------------------------------------------------------------
 */

/** [table, id column] holding the photo for this role, or null (admins have no photo). */
function photo_table_for_role(string $role): ?array {
    return ['student' => ['students', 'student_id'], 'teacher' => ['teachers', 'teacher_id']][$role] ?? null;
}

/** URL of the logged-in user's photo ('' when they have none). One small query per login. */
function current_photo_url(PDO $pdo): string {
    $table = photo_table_for_role($_SESSION['role'] ?? '');
    if (!$table || empty($_SESSION['profile_id'])) return '';
    $owner = $_SESSION['role'] . ':' . $_SESSION['profile_id'];
    if (($_SESSION['photo_cache']['owner'] ?? null) !== $owner) {
        try {
            // md5() runs in Postgres, so only 32 characters come back, not the photo.
            $st = $pdo->prepare("SELECT md5(photo) FROM {$table[0]} WHERE {$table[1]} = ? AND photo IS NOT NULL AND photo <> ''");
            $st->execute([$_SESSION['profile_id']]);
            $_SESSION['photo_cache'] = ['owner' => $owner, 'hash' => (string) $st->fetchColumn()];
        } catch (PDOException $e) {
            error_log('current_photo_url: ' . $e->getMessage());
            return '';
        }
    }
    $hash = $_SESSION['photo_cache']['hash'];
    return $hash === '' ? '' : BASE_URL . 'avatar.php?v=' . substr($hash, 0, 12);
}

/** Call after saving or removing a photo so the next page looks the hash up again. */
function forget_photo_cache(): void {
    unset($_SESSION['photo_cache']);
}
