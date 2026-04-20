<?php
/**
 * Cover Art Cache Proxy — My Records Collection
 *
 * GET /api/cover.php?artist=...&album=...
 *
 * First request:  Looks up MBID → downloads from Cover Art Archive → saves to /covers/
 * Next requests:  Serves the cached file directly.
 *
 * Also checks track_cache table for a known MBID to skip the search step.
 */

require_once __DIR__ . '/db.php';

$artist = trim($_GET['artist'] ?? '');
$album  = trim($_GET['album']  ?? '');

if ($artist === '' || $album === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'artist and album are required.']);
    exit;
}

$coversDir = __DIR__ . '/../covers';
if (!is_dir($coversDir)) {
    @mkdir($coversDir, 0755, true);
}

// Build a filesystem-safe cache filename
$safeKey  = md5(mb_strtolower($artist . '||' . $album));
$cachedFile = $coversDir . '/' . $safeKey . '.jpg';

// ── Serve from local cache ──────────────────────────────────
if (file_exists($cachedFile) && filesize($cachedFile) > 0) {
    serveCachedImage($cachedFile);
    exit;
}

// ── Resolve MBID ────────────────────────────────────────────
$mbid = null;
$pdo  = get_db();

// Check track_cache first (already has MBID stored)
$cacheKey = mb_strtolower($artist . '||' . $album);
$stmt = $pdo->prepare('SELECT mbid FROM track_cache WHERE cache_key = :key AND mbid != "" LIMIT 1');
$stmt->execute([':key' => $cacheKey]);
$row = $stmt->fetch();
if ($row) {
    $mbid = $row['mbid'];
}

// If not in cache, search MusicBrainz
if (!$mbid) {
    $queryParts = [];
    $queryParts[] = 'artist:"' . $artist . '"';
    $queryParts[] = 'release:"' . $album . '"';
    $query = implode(' AND ', $queryParts);

    $searchUrl = 'https://musicbrainz.org/ws/2/release/?query=' . urlencode($query)
               . '&fmt=json&limit=1';

    $ctx = stream_context_create([
        'http' => [
            'header'  => "User-Agent: MyRecordsCollection/1.0 (record-collection-app)\r\nAccept: application/json\r\n",
            'timeout' => 8,
        ],
    ]);

    $resp = @file_get_contents($searchUrl, false, $ctx);
    if ($resp !== false) {
        $data = json_decode($resp, true);
        if (!empty($data['releases'][0]['id'])) {
            $mbid = $data['releases'][0]['id'];
        }
    }
}

// ── Try Cover Art Archive (needs MBID) ──────────────────────
$imageData = false;
if ($mbid) {
    $coverUrl = "https://coverartarchive.org/release/$mbid/front-250";
    $imageData = downloadImage($coverUrl);
}

// ── Fallback: iTunes Search API ────────────────────────────
if ($imageData === false) {
    $term = trim("$artist $album");
    $itunesUrl = 'https://itunes.apple.com/search?'
               . http_build_query(['term' => $term, 'entity' => 'album', 'limit' => 1]);
    $resp = downloadUrl($itunesUrl);
    if ($resp !== false) {
        $json = json_decode($resp, true);
        $artUrl = $json['results'][0]['artworkUrl100'] ?? '';
        if ($artUrl) {
            $artUrl = str_replace('100x100bb', '600x600bb', $artUrl);
            $imageData = downloadImage($artUrl);
        }
    }
}

// ── Fallback: Deezer API ───────────────────────────────────
if ($imageData === false) {
    $q = trim("$artist $album");
    $deezerUrl = 'https://api.deezer.com/search/album?q=' . urlencode($q) . '&limit=1';
    $resp = downloadUrl($deezerUrl);
    if ($resp !== false) {
        $json = json_decode($resp, true);
        $artUrl = $json['data'][0]['cover_big'] ?? ($json['data'][0]['cover_medium'] ?? '');
        if ($artUrl) {
            $imageData = downloadImage($artUrl);
        }
    }
}

if ($imageData === false || strlen($imageData) < 100) {
    // No source had cover art — cache a miss marker
    @file_put_contents($cachedFile . '.miss', '1');
    servePixel();
    exit;
}

// Save to disk
@file_put_contents($cachedFile, $imageData);

// Also update the record's cover_url in the DB to point to local cache
$localUrl = 'covers/' . $safeKey . '.jpg';
$updateStmt = $pdo->prepare('
    UPDATE records SET cover_url = :url
    WHERE cover_url = "" AND LOWER(artist) = LOWER(:artist) AND LOWER(album) = LOWER(:album)
');
$updateStmt->execute([
    ':url'    => $localUrl,
    ':artist' => $artist,
    ':album'  => $album,
]);

serveCachedImage($cachedFile);
exit;

// ── Helpers ─────────────────────────────────────────────────

function serveCachedImage(string $path): void {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($path) ?: 'image/jpeg';

    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Length: ' . filesize($path));
    readfile($path);
}

function servePixel(): void {
    // 1x1 transparent GIF
    header('Content-Type: image/gif');
    header('Cache-Control: public, max-age=86400');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
}

/**
 * Download a URL and return the body as a string, or false on failure.
 */
function downloadUrl(string $url, int $timeout = 8): string|false {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => 'MyRecordsCollection/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code >= 200 && $code < 300 && $body !== false) ? $body : false;
    }
    $ctx = stream_context_create([
        'http' => [
            'header'          => "User-Agent: MyRecordsCollection/1.0\r\n",
            'timeout'         => $timeout,
            'follow_location' => 1,
            'max_redirects'   => 5,
        ],
    ]);
    return @file_get_contents($url, false, $ctx);
}

/**
 * Download an image URL — returns raw bytes or false.
 */
function downloadImage(string $url): string|false {
    $data = downloadUrl($url, 10);
    if ($data !== false && strlen($data) > 100) {
        return $data;
    }
    return false;
}
