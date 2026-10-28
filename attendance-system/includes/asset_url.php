<?php
/**
 * ------------------------------------------------------------
 * includes/asset_url.php
 * asset_url('assets/css/style.css') -> "<BASE_URL>assets/css/style.css?v=<hash of the file's contents>"
 *
 * Every <link>/<script> for a file under assets/ goes through this, so a deploy that changes a file also
 * changes its URL. That matters twice: browsers re-fetch it, and the service worker (sw.php), which answers
 * assets/ requests from its cache first, gets a cache miss instead of serving the old copy with new HTML.
 * The hash is of the CONTENTS, not filemtime(): Vercel gives every deployed file the same mtime, so an
 * mtime stamp never changes between deploys.
 * ------------------------------------------------------------
 */
function asset_url(string $path): string {
    static $hashes = [];
    if (!isset($hashes[$path])) {
        $hash = @md5_file(__DIR__ . '/../' . $path);
        $hashes[$path] = $hash === false ? '' : substr($hash, 0, 10);
    }
    return BASE_URL . $path . ($hashes[$path] === '' ? '' : '?v=' . $hashes[$path]);
}
