<?php
/**
 * Records API — My Records Collection
 * RESTful CRUD endpoints for the records table.
 *
 * GET    /api/api.php              — List all records (supports ?search= & ?genre=)
 * GET    /api/api.php?id=N         — Get single record
 * POST   /api/api.php              — Create a new record
 * PUT    /api/api.php?id=N         — Update a record
 * DELETE /api/api.php?id=N         — Delete a record
 * GET    /api/api.php?stats=1      — Collection statistics
 * POST   /api/api.php?import=csv   — Bulk import from CSV data
 * GET    /api/api.php?lookup=1     — Lookup record info from MusicBrainz
 * GET    /api/api.php?tracks=1     — Fetch track list from MusicBrainz
 * GET    /api/api.php?discogs=value&id=N — Fetch Discogs marketplace value
 * GET    /api/api.php?discogs=valuate_all — Bulk-value uncached records
 */

// Suppress error display in production
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once __DIR__ . '/db.php';

define('DISCOGS_TOKEN_FILE', __DIR__ . '/discogs_token.txt');
define('DISCOGS_RATE_FILE', sys_get_temp_dir() . '/records_discogs_rate.json');
define('DISCOGS_RATE_LIMIT', 30);  // max Discogs requests per minute (half of Discogs's 60 to stay safe)

$method = $_SERVER['REQUEST_METHOD'];

// Handle CORS preflight
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $pdo = get_db();

    switch ($method) {
        // ── READ ────────────────────────────────────────────
        case 'GET':
            // Stats endpoint
            if (isset($_GET['stats'])) {
                handleStats($pdo);
                break;
            }

            // MusicBrainz lookup endpoint
            if (isset($_GET['lookup'])) {
                handleLookup();
                break;
            }

            // MusicBrainz track list endpoint
            if (isset($_GET['tracks'])) {
                handleTracks($pdo);
                break;
            }

            // Discogs valuation endpoint
            if (isset($_GET['discogs'])) {
                if (!checkDiscogsRateLimit()) {
                    http_response_code(429);
                    echo json_encode(['error' => 'Discogs rate limit reached. Please wait a minute.']);
                    break;
                }
                if ($_GET['discogs'] === 'valuate_all') {
                    handleDiscogsValuateAll($pdo);
                } elseif ($_GET['discogs'] === 'clear_cache') {
                    ensureDiscogsCacheTable($pdo);
                    $pdo->exec('TRUNCATE TABLE discogs_cache');
                    echo json_encode(['message' => 'Discogs cache cleared.']);
                } else {
                    handleDiscogsValue($pdo);
                }
                break;
            }

            // Lyrics endpoint — GET ?lyrics=1&artist=...&title=...
            // With &fetch=1, fetches lyrics from the web instead of DB
            if (isset($_GET['lyrics'])) {
                if (isset($_GET['fetch'])) {
                    handleFetchLyricsFromWeb();
                } else {
                    handleGetLyrics($pdo);
                }
                break;
            }

            // Single record
            if (isset($_GET['id'])) {
                $id = (int) $_GET['id'];
                $stmt = $pdo->prepare('SELECT * FROM records WHERE id = :id');
                $stmt->execute([':id' => $id]);
                $record = $stmt->fetch();

                if (!$record) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Record not found.']);
                } else {
                    echo json_encode($record);
                }
                break;
            }

            // List with optional filters
            $where = [];
            $params = [];

            if (!empty($_GET['search'])) {
                $where[] = '(artist LIKE :searchA OR album LIKE :searchB)';
                $params[':searchA'] = '%' . $_GET['search'] . '%';
                $params[':searchB'] = '%' . $_GET['search'] . '%';
            }
            if (!empty($_GET['genre'])) {
                $where[] = 'genre = :genre';
                $params[':genre'] = $_GET['genre'];
            }
            if (!empty($_GET['format'])) {
                $where[] = 'format = :format';
                $params[':format'] = $_GET['format'];
            }

            $sql = 'SELECT * FROM records';
            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }

            $sort = $_GET['sort'] ?? 'artist';
            $allowedSort = ['artist', 'album', 'year', 'genre', 'format', 'date_added'];
            if (!in_array($sort, $allowedSort, true)) {
                $sort = 'artist';
            }
            // Text-based columns default to ASC (A→Z); date/year default to DESC (newest first)
            $ascDefault = in_array($sort, ['artist', 'album', 'genre', 'format'], true);
            if (isset($_GET['order'])) {
                $order = strtoupper($_GET['order']) === 'ASC' ? 'ASC' : 'DESC';
            } else {
                $order = $ascDefault ? 'ASC' : 'DESC';
            }
            $sql .= " ORDER BY $sort $order";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $records = $stmt->fetchAll();

            echo json_encode($records);
            break;

        // ── CREATE ──────────────────────────────────────────
        case 'POST':
            // CSV bulk import
            if (isset($_GET['import']) && $_GET['import'] === 'csv') {
                handleCsvImport($pdo);
                break;
            }

            // Save lyrics — POST ?lyrics=1
            if (isset($_GET['lyrics'])) {
                handleSaveLyrics($pdo);
                break;
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid JSON body.']);
                break;
            }

            $errors = validateRecord($data);
            if ($errors) {
                http_response_code(422);
                echo json_encode(['errors' => $errors]);
                break;
            }

            // Duplicate check (artist + album, case-insensitive)
            $force = isset($_GET['force']) && $_GET['force'] === '1';
            if (!$force) {
                $dup = findDuplicate($pdo, trim($data['artist']), trim($data['album']));
                if ($dup) {
                    http_response_code(409);
                    echo json_encode([
                        'error'     => 'A record with the same artist and album already exists.',
                        'duplicate' => $dup,
                    ]);
                    break;
                }
            }

            $coverUrl = cacheCoverUrl($data['cover_url'] ?? '');

            $stmt = $pdo->prepare('
                INSERT INTO records (artist, album, year, genre, format, condition_grade, notes, cover_url)
                VALUES (:artist, :album, :year, :genre, :format, :condition_grade, :notes, :cover_url)
            ');
            $stmt->execute([
                ':artist'          => trim($data['artist']),
                ':album'           => trim($data['album']),
                ':year'            => !empty($data['year']) ? (int) $data['year'] : null,
                ':genre'           => trim($data['genre'] ?? ''),
                ':format'          => $data['format'] ?? 'Vinyl',
                ':condition_grade' => trim($data['condition_grade'] ?? ''),
                ':notes'           => trim($data['notes'] ?? ''),
                ':cover_url'       => $coverUrl,
            ]);

            $newId = (int) $pdo->lastInsertId();
            http_response_code(201);
            echo json_encode(['id' => $newId, 'message' => 'Record created.']);
            break;

        // ── UPDATE ──────────────────────────────────────────
        case 'PUT':
            if (!isset($_GET['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing record id.']);
                break;
            }
            $id = (int) $_GET['id'];

            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid JSON body.']);
                break;
            }

            $errors = validateRecord($data);
            if ($errors) {
                http_response_code(422);
                echo json_encode(['errors' => $errors]);
                break;
            }

            $coverUrl = cacheCoverUrl($data['cover_url'] ?? '');

            $stmt = $pdo->prepare('
                UPDATE records SET
                    artist          = :artist,
                    album           = :album,
                    year            = :year,
                    genre           = :genre,
                    format          = :format,
                    condition_grade = :condition_grade,
                    notes           = :notes,
                    cover_url       = :cover_url
                WHERE id = :id
            ');
            $stmt->execute([
                ':artist'          => trim($data['artist']),
                ':album'           => trim($data['album']),
                ':year'            => !empty($data['year']) ? (int) $data['year'] : null,
                ':genre'           => trim($data['genre'] ?? ''),
                ':format'          => $data['format'] ?? 'Vinyl',
                ':condition_grade' => trim($data['condition_grade'] ?? ''),
                ':notes'           => trim($data['notes'] ?? ''),
                ':cover_url'       => $coverUrl,
                ':id'              => $id,
            ]);

            // rowCount() returns 0 when values are unchanged — verify the record exists
            if ($stmt->rowCount() === 0) {
                $check = $pdo->prepare('SELECT id FROM records WHERE id = :id');
                $check->execute([':id' => $id]);
                if (!$check->fetch()) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Record not found.']);
                } else {
                    echo json_encode(['message' => 'Record updated.']);
                }
            } else {
                // Record changed — invalidate Discogs cache so it re-fetches with new info
                try {
                    ensureDiscogsCacheTable($pdo);
                    $pdo->prepare('DELETE FROM discogs_cache WHERE record_id = :rid')->execute([':rid' => $id]);
                } catch (\Throwable $e) {
                    // Non-critical — cache will expire naturally
                }
                echo json_encode(['message' => 'Record updated.']);
            }
            break;

        // ── DELETE ──────────────────────────────────────────
        case 'DELETE':
            if (!isset($_GET['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing record id.']);
                break;
            }
            $id = (int) $_GET['id'];

            // Fetch the record first so we can clean up related data
            $stmt = $pdo->prepare('SELECT artist, album, cover_url FROM records WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $record = $stmt->fetch();

            if (!$record) {
                http_response_code(404);
                echo json_encode(['error' => 'Record not found.']);
                break;
            }

            // Delete the record
            $stmt = $pdo->prepare('DELETE FROM records WHERE id = :id');
            $stmt->execute([':id' => $id]);

            // Delete matching track_cache entry
            $cacheKey = mb_strtolower(trim($record['artist']) . '||' . trim($record['album']));
            $stmt = $pdo->prepare('DELETE FROM track_cache WHERE cache_key = :key');
            $stmt->execute([':key' => $cacheKey]);

            // Delete cached cover art file (safe: only within covers/ directory)
            if (!empty($record['cover_url'])) {
                $coverPath = realpath(__DIR__ . '/../' . $record['cover_url']);
                $coversReal = realpath(__DIR__ . '/../covers');
                if ($coverPath && $coversReal && str_starts_with($coverPath, $coversReal) && is_file($coverPath)) {
                    @unlink($coverPath);
                }
            }

            echo json_encode(['message' => 'Record deleted.']);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed.']);
    }
} catch (\Throwable $e) {
    error_log('[Records API] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error.']);
}

// ── Helpers ──────────────────────────────────────────────────

function findDuplicate(PDO $pdo, string $artist, string $album, ?int $excludeId = null): ?array {
    $sql = 'SELECT id, artist, album FROM records WHERE LOWER(artist) = LOWER(:artist) AND LOWER(album) = LOWER(:album)';
    $params = [':artist' => $artist, ':album' => $album];
    if ($excludeId !== null) {
        $sql .= ' AND id != :excludeId';
        $params[':excludeId'] = $excludeId;
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function validateRecord(array $data): array {
    $errors = [];
    if (empty(trim($data['artist'] ?? ''))) {
        $errors[] = 'Artist is required.';
    } elseif (mb_strlen($data['artist']) > 255) {
        $errors[] = 'Artist name too long (max 255 chars).';
    }
    if (empty(trim($data['album'] ?? ''))) {
        $errors[] = 'Album is required.';
    } elseif (mb_strlen($data['album']) > 255) {
        $errors[] = 'Album name too long (max 255 chars).';
    }
    $validFormats = ['Vinyl', 'CD', 'Cassette', 'Digital'];
    if (!empty($data['format']) && !in_array($data['format'], $validFormats, true)) {
        $errors[] = 'Invalid format. Must be one of: ' . implode(', ', $validFormats);
    }
    if (!empty($data['year'])) {
        $y = (int) $data['year'];
        if ($y < 1900 || $y > (int) date('Y') + 1) {
            $errors[] = 'Year must be between 1900 and ' . ((int) date('Y') + 1) . '.';
        }
    }
    if (mb_strlen($data['genre'] ?? '') > 100) {
        $errors[] = 'Genre too long (max 100 chars).';
    }
    if (mb_strlen($data['notes'] ?? '') > 5000) {
        $errors[] = 'Notes too long (max 5000 chars).';
    }
    if (mb_strlen($data['cover_url'] ?? '') > 500) {
        $errors[] = 'Cover URL too long (max 500 chars).';
    }
    return $errors;
}

/**
 * Fetch track list for an album.
 * Checks DB cache first; falls back to MusicBrainz and stores the result.
 * GET ?tracks=1&artist=...&album=...
 */
function handleTracks(PDO $pdo): void {
    $artist = trim($_GET['artist'] ?? '');
    $album  = trim($_GET['album']  ?? '');

    if ($artist === '' || $album === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Both artist and album are required.']);
        return;
    }

    // Ensure cache table exists
    ensureTrackCacheTable($pdo);

    // ── Check DB cache ──────────────────────────────────────
    $cacheKey = mb_strtolower($artist . '||' . $album);
    $stmt = $pdo->prepare('SELECT artist, album, cover_url, tracks_json, fetched_at FROM track_cache WHERE cache_key = :key LIMIT 1');
    $stmt->execute([':key' => $cacheKey]);
    $cached = $stmt->fetch();

    if ($cached) {
        $tracks = json_decode($cached['tracks_json'], true) ?: [];
        echo json_encode([
            'artist'    => $cached['artist'],
            'album'     => $cached['album'],
            'cover_url' => $cached['cover_url'],
            'tracks'    => $tracks,
            'source'    => 'cache',
        ]);
        return;
    }

    // ── Fetch from MusicBrainz ──────────────────────────────
    $ctx = stream_context_create([
        'http' => [
            'header'  => "User-Agent: MyRecordsCollection/1.0 (record-collection-app)\r\nAccept: application/json\r\n",
            'timeout' => 10,
        ],
    ]);

    // Step 1: Search for the release to get its MBID
    $queryParts = [];
    $queryParts[] = 'artist:"' . $artist . '"';
    $queryParts[] = 'release:"' . $album . '"';
    $query = implode(' AND ', $queryParts);

    $searchUrl = 'https://musicbrainz.org/ws/2/release/?query=' . urlencode($query)
               . '&fmt=json&limit=1';

    $searchResp = @file_get_contents($searchUrl, false, $ctx);
    if ($searchResp === false) {
        http_response_code(502);
        echo json_encode(['error' => 'Failed to reach MusicBrainz API.']);
        return;
    }

    $searchData = json_decode($searchResp, true);
    $releases = $searchData['releases'] ?? [];

    if (empty($releases)) {
        echo json_encode(['tracks' => [], 'album' => $album, 'artist' => $artist]);
        return;
    }

    $mbid = $releases[0]['id'];
    $albumTitle = $releases[0]['title'] ?? $album;
    $artistName = '';
    if (!empty($releases[0]['artist-credit'])) {
        $parts = [];
        foreach ($releases[0]['artist-credit'] as $ac) {
            $parts[] = $ac['name'] ?? ($ac['artist']['name'] ?? '');
            if (!empty($ac['joinphrase'])) $parts[] = $ac['joinphrase'];
        }
        $artistName = implode('', $parts);
    }
    $coverUrl = "https://coverartarchive.org/release/$mbid/front-250";

    // MusicBrainz rate limit: 1 req/sec
    usleep(1100000);

    // Step 2: Fetch the release with recordings
    $releaseUrl = "https://musicbrainz.org/ws/2/release/$mbid?inc=recordings&fmt=json";
    $releaseResp = @file_get_contents($releaseUrl, false, $ctx);
    if ($releaseResp === false) {
        http_response_code(502);
        echo json_encode(['error' => 'Failed to fetch release details.']);
        return;
    }

    $releaseData = json_decode($releaseResp, true);
    $media = $releaseData['media'] ?? [];

    $tracks = [];
    foreach ($media as $disc) {
        $discTitle = $disc['title'] ?? '';
        $discPos   = $disc['position'] ?? 1;
        foreach ($disc['tracks'] ?? [] as $t) {
            $lengthMs = $t['length'] ?? ($t['recording']['length'] ?? null);
            $duration = null;
            if ($lengthMs) {
                $totalSec = intdiv((int) $lengthMs, 1000);
                $min = intdiv($totalSec, 60);
                $sec = $totalSec % 60;
                $duration = sprintf('%d:%02d', $min, $sec);
            }
            $tracks[] = [
                'position' => $t['position'] ?? $t['number'] ?? '',
                'title'    => $t['title'] ?? ($t['recording']['title'] ?? ''),
                'duration' => $duration,
                'disc'     => count($media) > 1 ? $discPos : null,
                'disc_title' => $discTitle,
            ];
        }
    }

    // ── Store in DB cache ────────────────────────────────────
    if (!empty($tracks)) {
        try {
            $ins = $pdo->prepare('
                INSERT INTO track_cache (cache_key, artist, album, cover_url, mbid, tracks_json)
                VALUES (:key, :artist, :album, :cover_url, :mbid, :tracks_json)
                ON DUPLICATE KEY UPDATE
                    artist      = VALUES(artist),
                    album       = VALUES(album),
                    cover_url   = VALUES(cover_url),
                    mbid        = VALUES(mbid),
                    tracks_json = VALUES(tracks_json),
                    fetched_at  = CURRENT_TIMESTAMP
            ');
            $ins->execute([
                ':key'         => $cacheKey,
                ':artist'      => $artistName ?: $artist,
                ':album'       => $albumTitle,
                ':cover_url'   => $coverUrl,
                ':mbid'        => $mbid,
                ':tracks_json' => json_encode($tracks, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (PDOException $e) {
            error_log('[Records API] Cache write failed: ' . $e->getMessage());
        }
    }

    echo json_encode([
        'artist'    => $artistName ?: $artist,
        'album'     => $albumTitle,
        'cover_url' => $coverUrl,
        'tracks'    => $tracks,
        'source'    => 'musicbrainz',
    ]);
}

/**
 * Auto-create the track_cache table if it doesn't exist.
 */
function ensureTrackCacheTable(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS track_cache (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            cache_key   VARCHAR(600) NOT NULL UNIQUE,
            artist      VARCHAR(255) NOT NULL,
            album       VARCHAR(255) NOT NULL,
            cover_url   VARCHAR(500) DEFAULT \'\',
            mbid        VARCHAR(36)  DEFAULT \'\',
            tracks_json MEDIUMTEXT   NOT NULL,
            fetched_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cache_key (cache_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
}

/**
 * Lookup album info from multiple sources with fallback chain:
 *   1. MusicBrainz  (primary)
 *   2. iTunes Search API  (fallback)
 *   3. Deezer API  (fallback)
 *
 * GET ?lookup=1&artist=...&album=...&source=auto|musicbrainz|itunes|deezer
 */
function handleLookup(): void {
    $artist = trim($_GET['artist'] ?? '');
    $album  = trim($_GET['album']  ?? '');
    $source = trim($_GET['source'] ?? 'auto');

    if ($artist === '' && $album === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Provide artist and/or album name.']);
        return;
    }

    $results = [];
    $usedSource = 'none';

    if ($source === 'musicbrainz' || ($source === 'auto' && empty($results))) {
        $results = lookupMusicBrainz($artist, $album);
        if (!empty($results)) $usedSource = 'musicbrainz';
    }

    if ($source === 'itunes' || ($source === 'auto' && empty($results))) {
        $results = lookupItunes($artist, $album);
        if (!empty($results)) $usedSource = 'itunes';
    }

    if ($source === 'deezer' || ($source === 'auto' && empty($results))) {
        $results = lookupDeezer($artist, $album);
        if (!empty($results)) $usedSource = 'deezer';
    }

    echo json_encode([
        'results' => $results,
        'source'  => $usedSource,
    ]);
}

// ── MusicBrainz lookup ──────────────────────────────────────
function lookupMusicBrainz(string $artist, string $album): array {
    $queryParts = [];
    if ($artist !== '') $queryParts[] = 'artist:"' . $artist . '"';
    if ($album  !== '') $queryParts[] = 'release:"' . $album . '"';
    $query = implode(' AND ', $queryParts);

    $url = 'https://musicbrainz.org/ws/2/release/?query=' . urlencode($query)
         . '&fmt=json&limit=5';

    $response = httpGet($url, [
        'User-Agent: MyRecordsCollection/1.0 (record-collection-app)',
        'Accept: application/json',
    ]);
    if ($response === false) return [];

    $mb = json_decode($response, true);
    $releases = $mb['releases'] ?? [];
    if (empty($releases)) return [];

    $results = [];
    foreach (array_slice($releases, 0, 5) as $rel) {
        $mbid = $rel['id'] ?? '';
        $artistName = '';
        if (!empty($rel['artist-credit'])) {
            $parts = [];
            foreach ($rel['artist-credit'] as $ac) {
                $parts[] = $ac['name'] ?? ($ac['artist']['name'] ?? '');
                if (!empty($ac['joinphrase'])) $parts[] = $ac['joinphrase'];
            }
            $artistName = implode('', $parts);
        }

        $tags = [];
        if (!empty($rel['tags'])) {
            foreach ($rel['tags'] as $tag) {
                $tags[] = $tag['name'];
            }
        }

        // If no release tags, try to get genre from the artist
        if (empty($tags) && !empty($rel['artist-credit'][0]['artist']['id'])) {
            $artistId = $rel['artist-credit'][0]['artist']['id'];
            $artistUrl = "https://musicbrainz.org/ws/2/artist/$artistId?inc=genres+tags&fmt=json";
            $artistResp = httpGet($artistUrl, [
                'User-Agent: MyRecordsCollection/1.0 (record-collection-app)',
                'Accept: application/json',
            ]);
            if ($artistResp !== false) {
                $artistData = json_decode($artistResp, true);
                // Prefer genres (official), fall back to tags
                $genreList = $artistData['genres'] ?? [];
                if (!empty($genreList)) {
                    // Sort by count descending to get the most relevant genre
                    usort($genreList, fn($a, $b) => ($b['count'] ?? 0) - ($a['count'] ?? 0));
                    foreach ($genreList as $g) {
                        if (!empty($g['name'])) {
                            $tags[] = $g['name'];
                            break;
                        }
                    }
                }
                if (empty($tags) && !empty($artistData['tags'])) {
                    usort($artistData['tags'], fn($a, $b) => ($b['count'] ?? 0) - ($a['count'] ?? 0));
                    foreach ($artistData['tags'] as $t) {
                        if (!empty($t['name'])) {
                            $tags[] = $t['name'];
                            break;
                        }
                    }
                }
            }
        }

        $coverUrl = $mbid ? "https://coverartarchive.org/release/$mbid/front-250" : '';

        $results[] = [
            'artist'    => $artistName,
            'album'     => $rel['title'] ?? '',
            'year'      => !empty($rel['date']) ? (int) substr($rel['date'], 0, 4) : null,
            'genre'     => !empty($tags) ? ucfirst($tags[0]) : '',
            'country'   => $rel['country'] ?? '',
            'label'     => !empty($rel['label-info'][0]['label']['name'])
                           ? $rel['label-info'][0]['label']['name'] : '',
            'cover_url' => $coverUrl,
            'mbid'      => $mbid,
            'source'    => 'musicbrainz',
        ];
    }
    return $results;
}

// ── iTunes Search API lookup ────────────────────────────────
function lookupItunes(string $artist, string $album): array {
    $term = trim("$artist $album");
    $url  = 'https://itunes.apple.com/search?'
          . http_build_query(['term' => $term, 'entity' => 'album', 'limit' => 5]);

    $response = httpGet($url);
    if ($response === false) return [];

    $data = json_decode($response, true);
    $items = $data['results'] ?? [];
    if (empty($items)) return [];

    $results = [];
    foreach ($items as $item) {
        $coverUrl = $item['artworkUrl100'] ?? '';
        // Upscale to 250px if available
        if ($coverUrl) {
            $coverUrl = str_replace('100x100bb', '250x250bb', $coverUrl);
        }

        $results[] = [
            'artist'    => $item['artistName'] ?? '',
            'album'     => $item['collectionName'] ?? '',
            'year'      => !empty($item['releaseDate']) ? (int) substr($item['releaseDate'], 0, 4) : null,
            'genre'     => $item['primaryGenreName'] ?? '',
            'country'   => $item['country'] ?? '',
            'label'     => '',
            'cover_url' => $coverUrl,
            'mbid'      => '',
            'source'    => 'itunes',
        ];
    }
    return $results;
}

// ── Deezer API lookup ───────────────────────────────────────
function lookupDeezer(string $artist, string $album): array {
    $q = trim("$artist $album");
    $url = 'https://api.deezer.com/search/album?q=' . urlencode($q) . '&limit=5';

    $response = httpGet($url);
    if ($response === false) return [];

    $data = json_decode($response, true);
    $items = $data['data'] ?? [];
    if (empty($items)) return [];

    $results = [];
    foreach ($items as $item) {
        // Deezer gives cover_medium (250px)
        $coverUrl = $item['cover_medium'] ?? ($item['cover'] ?? '');

        $genre = '';
        if (!empty($item['genre_id'])) {
            // Fetch genre name from Deezer
            $genreData = httpGet("https://api.deezer.com/genre/{$item['genre_id']}");
            if ($genreData !== false) {
                $g = json_decode($genreData, true);
                $genre = $g['name'] ?? '';
            }
        }

        $results[] = [
            'artist'    => $item['artist']['name'] ?? '',
            'album'     => $item['title'] ?? '',
            'year'      => null, // Deezer search doesn't include release date
            'genre'     => $genre,
            'country'   => '',
            'label'     => $item['label'] ?? '',
            'cover_url' => $coverUrl,
            'mbid'      => '',
            'source'    => 'deezer',
        ];
    }
    return $results;
}

/**
 * Generic HTTP GET helper — uses cURL if available, falls back to file_get_contents.
 */
function httpGet(string $url, array $extraHeaders = [], int $timeout = 8): string|false {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $headers = array_merge(['Accept: application/json'], $extraHeaders);
        if (!preg_grep('/^User-Agent:/i', $headers)) {
            $headers[] = 'User-Agent: MyRecordsCollection/1.0';
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code >= 200 && $code < 300 && $body !== false) ? $body : false;
    }

    // Fallback: file_get_contents
    $headerStr = implode("\r\n", array_merge(
        ['User-Agent: MyRecordsCollection/1.0', 'Accept: application/json'],
        $extraHeaders
    ));
    $ctx = stream_context_create([
        'http' => [
            'header'          => $headerStr . "\r\n",
            'timeout'         => $timeout,
            'follow_location' => 1,
            'max_redirects'   => 5,
        ],
    ]);
    return @file_get_contents($url, false, $ctx);
}

function handleCsvImport(PDO $pdo): void {
    // Limit request body size (10 MB)
    $rawBody = file_get_contents('php://input');
    if (strlen($rawBody) > 10 * 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['error' => 'Request too large (max 10 MB).']);
        return;
    }
    $input = json_decode($rawBody, true);
    if (empty($input['rows']) || !is_array($input['rows'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No rows provided.']);
        return;
    }
    if (count($input['rows']) > 5000) {
        http_response_code(413);
        echo json_encode(['error' => 'Too many rows (max 5000).']);
        return;
    }

    $rows = $input['rows'];
    $validFormats = ['Vinyl', 'CD', 'Cassette', 'Digital'];
    $imported = 0;
    $skipped  = 0;
    $errors   = [];

    $stmt = $pdo->prepare('
        INSERT INTO records (artist, album, year, genre, format, condition_grade, notes, cover_url)
        VALUES (:artist, :album, :year, :genre, :format, :condition_grade, :notes, :cover_url)
    ');

    foreach ($rows as $i => $row) {
        $lineNum = $i + 2; // +2 because row 1 is header, arrays are 0-indexed
        $artist = trim($row['artist'] ?? '');
        $album  = trim($row['album']  ?? '');

        if ($artist === '' || $album === '') {
            $skipped++;
            $errors[] = "Row $lineNum: artist and album are required.";
            continue;
        }

        // Skip duplicates (check against existing DB records)
        $dup = findDuplicate($pdo, $artist, $album);
        if ($dup) {
            $skipped++;
            $errors[] = "Row $lineNum: duplicate of existing record #{$dup['id']} ({$dup['artist']} — {$dup['album']}).";
            continue;
        }

        $format = trim($row['format'] ?? 'Vinyl');
        if (!in_array($format, $validFormats, true)) {
            $format = 'Vinyl';
        }

        $year = null;
        if (!empty($row['year'])) {
            $y = (int) $row['year'];
            if ($y >= 1900 && $y <= (int) date('Y') + 1) {
                $year = $y;
            }
        }

        try {
            $stmt->execute([
                ':artist'          => $artist,
                ':album'           => $album,
                ':year'            => $year,
                ':genre'           => trim($row['genre'] ?? ''),
                ':format'          => $format,
                ':condition_grade' => trim($row['condition_grade'] ?? '') ?: 'Mint (M)',
                ':notes'           => trim($row['notes'] ?? ''),
                ':cover_url'       => trim($row['cover_url'] ?? ''),
            ]);
            $imported++;
        } catch (PDOException $e) {
            $skipped++;
            $errors[] = "Row $lineNum: " . $e->getMessage();
        }
    }

    $statusCode = $imported > 0 ? 201 : 422;
    http_response_code($statusCode);
    echo json_encode([
        'imported' => $imported,
        'skipped'  => $skipped,
        'total'    => count($rows),
        'errors'   => array_slice($errors, 0, 20), // limit error list
        'message'  => "$imported of " . count($rows) . " records imported.",
    ]);
}

/**
 * If cover_url is an external URL, download it to the local covers/ folder
 * and return the relative path. Otherwise return as-is.
 */
function cacheCoverUrl(string $url): string {
    $url = trim($url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return $url; // already local or empty
    }

    $coversDir = __DIR__ . '/../covers';
    if (!is_dir($coversDir)) {
        @mkdir($coversDir, 0755, true);
    }

    $safeKey    = md5($url);
    $localFile  = $coversDir . '/' . $safeKey . '.jpg';
    $localPath  = 'covers/' . $safeKey . '.jpg';

    // Already downloaded previously
    if (file_exists($localFile) && filesize($localFile) > 0) {
        return $localPath;
    }

    // Download the image — prefer cURL for reliable redirect handling
    $imgData = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'MyRecordsCollection/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $imgData  = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode < 200 || $httpCode >= 300) {
            $imgData = false;
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'header'           => "User-Agent: MyRecordsCollection/1.0\r\n",
                'timeout'          => 10,
                'follow_location'  => 1,
                'max_redirects'    => 5,
            ],
        ]);
        $imgData = @file_get_contents($url, false, $ctx);
    }

    if ($imgData !== false && strlen($imgData) > 100) {
        file_put_contents($localFile, $imgData);
        return $localPath;
    }

    // Download failed — return empty so the cover proxy can try later
    return '';
}

// ── Lyrics ──────────────────────────────────────────────────

/**
 * Auto-create the song_lyrics table if it doesn't exist.
 */
function ensureLyricsTable(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS song_lyrics (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            artist      VARCHAR(255) NOT NULL,
            album       VARCHAR(255) NOT NULL,
            title       VARCHAR(255) NOT NULL,
            lyrics      MEDIUMTEXT   NOT NULL,
            updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY  uq_song (artist(191), album(191), title(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
}

/**
 * GET ?lyrics=1&artist=...&album=...&title=...
 * Returns saved lyrics for a song, or empty if not found.
 */
function handleGetLyrics(PDO $pdo): void {
    $artist = trim($_GET['artist'] ?? '');
    $album  = trim($_GET['album']  ?? '');
    $title  = trim($_GET['title']  ?? '');

    if ($artist === '' || $title === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Artist and title are required.']);
        return;
    }

    ensureLyricsTable($pdo);

    $stmt = $pdo->prepare('
        SELECT lyrics, updated_at FROM song_lyrics
        WHERE LOWER(artist) = LOWER(:artist)
          AND LOWER(album)  = LOWER(:album)
          AND LOWER(title)  = LOWER(:title)
        LIMIT 1
    ');
    $stmt->execute([':artist' => $artist, ':album' => $album, ':title' => $title]);
    $row = $stmt->fetch();

    echo json_encode([
        'artist'     => $artist,
        'album'      => $album,
        'title'      => $title,
        'lyrics'     => $row ? $row['lyrics'] : null,
        'updated_at' => $row ? $row['updated_at'] : null,
    ]);
}

/**
 * POST ?lyrics=1  { artist, album, title, lyrics }
 * Saves (inserts or updates) lyrics for a song.
 */
function handleSaveLyrics(PDO $pdo): void {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body.']);
        return;
    }

    $artist = trim($data['artist'] ?? '');
    $album  = trim($data['album']  ?? '');
    $title  = trim($data['title']  ?? '');
    $lyrics = trim($data['lyrics'] ?? '');

    if ($artist === '' || $title === '') {
        http_response_code(422);
        echo json_encode(['error' => 'Artist and title are required.']);
        return;
    }

    ensureLyricsTable($pdo);

    $stmt = $pdo->prepare('
        INSERT INTO song_lyrics (artist, album, title, lyrics)
        VALUES (:artist, :album, :title, :lyrics)
        ON DUPLICATE KEY UPDATE
            lyrics     = VALUES(lyrics),
            updated_at = CURRENT_TIMESTAMP
    ');
    $stmt->execute([
        ':artist' => $artist,
        ':album'  => $album,
        ':title'  => $title,
        ':lyrics' => $lyrics,
    ]);

    echo json_encode(['message' => 'Lyrics saved.', 'title' => $title]);
}

/**
 * GET ?lyrics=1&fetch=1&artist=...&title=...(&album=...)
 * Fetches lyrics from external free APIs and returns them.
 * Sources tried in order: LRCLIB, lyrics.ovh
 */
function handleFetchLyricsFromWeb(): void {
    $artist = trim($_GET['artist'] ?? '');
    $title  = trim($_GET['title']  ?? '');

    if ($artist === '' || $title === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Artist and title are required.']);
        return;
    }

    // ── Source 1: LRCLIB ────────────────────────────────────
    $lrclibUrl = 'https://lrclib.net/api/get?' . http_build_query([
        'artist_name' => $artist,
        'track_name'  => $title,
        'album_name'  => trim($_GET['album'] ?? ''),
    ]);

    $resp = httpGet($lrclibUrl, ['User-Agent: MyRecordsCollection/1.0']);
    if ($resp !== false) {
        $data = json_decode($resp, true);
        // LRCLIB returns plainLyrics and syncedLyrics
        $lyrics = $data['plainLyrics'] ?? '';
        if ($lyrics !== '') {
            echo json_encode([
                'lyrics' => $lyrics,
                'source' => 'LRCLIB',
                'artist' => $artist,
                'title'  => $title,
            ]);
            return;
        }
    }

    // ── Source 2: lyrics.ovh ────────────────────────────────
    $ovhUrl = 'https://api.lyrics.ovh/v1/'
            . rawurlencode($artist) . '/'
            . rawurlencode($title);

    $resp = httpGet($ovhUrl, [], 12);
    if ($resp !== false) {
        $data = json_decode($resp, true);
        $lyrics = trim($data['lyrics'] ?? '');
        if ($lyrics !== '') {
            echo json_encode([
                'lyrics' => $lyrics,
                'source' => 'lyrics.ovh',
                'artist' => $artist,
                'title'  => $title,
            ]);
            return;
        }
    }

    // Nothing found
    echo json_encode([
        'lyrics' => null,
        'source' => null,
        'artist' => $artist,
        'title'  => $title,
    ]);
}

function handleStats(PDO $pdo): void {
    $total      = $pdo->query('SELECT COUNT(*) FROM records')->fetchColumn();
    $byGenre    = $pdo->query('SELECT genre, COUNT(*) as count FROM records WHERE genre != "" GROUP BY genre ORDER BY count DESC')->fetchAll();
    $byFormat   = $pdo->query('SELECT format, COUNT(*) as count FROM records GROUP BY format ORDER BY count DESC')->fetchAll();
    $byDecade   = $pdo->query('SELECT FLOOR(year/10)*10 AS decade, COUNT(*) as count FROM records WHERE year IS NOT NULL GROUP BY decade ORDER BY decade')->fetchAll();
    $latest     = $pdo->query('SELECT artist, album, date_added FROM records ORDER BY date_added DESC LIMIT 5')->fetchAll();

    // Aggregate Discogs valuation data from cache
    $valuation = null;
    try {
        ensureDiscogsCacheTable($pdo);
        $valRow = $pdo->query('
            SELECT COUNT(*) AS priced,
                   SUM(lowest_price)  AS total_value,
                   MIN(lowest_price)  AS min_price,
                   MAX(lowest_price)  AS max_price,
                   AVG(lowest_price)  AS avg_price
            FROM discogs_cache
            WHERE lowest_price IS NOT NULL AND lowest_price > 0
              AND record_id IN (SELECT id FROM records)
        ')->fetch();

        if ($valRow && (int) $valRow['priced'] > 0) {
            $valuation = [
                'priced'      => (int) $valRow['priced'],
                'total_value' => round((float) $valRow['total_value'], 2),
                'avg_price'   => round((float) $valRow['avg_price'], 2),
                'min_price'   => round((float) $valRow['min_price'], 2),
                'max_price'   => round((float) $valRow['max_price'], 2),
            ];
        }
    } catch (PDOException $e) {
        // Ignore — valuation is optional
    }

    echo json_encode([
        'total'      => (int) $total,
        'by_genre'   => $byGenre,
        'by_format'  => $byFormat,
        'by_decade'  => $byDecade,
        'latest'     => $latest,
        'valuation'  => $valuation,
    ]);
}

// ── Discogs API ─────────────────────────────────────────────

/**
 * Simple file-based rate limiter for Discogs API requests.
 * Returns true if under limit, false if rate exceeded.
 */
function checkDiscogsRateLimit(): bool {
    $file = DISCOGS_RATE_FILE;
    $now = time();
    $window = 60; // seconds

    $data = ['timestamps' => []];
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) $data = $parsed;
        }
    }

    // Prune timestamps older than the window
    $data['timestamps'] = array_values(array_filter(
        $data['timestamps'] ?? [],
        fn($ts) => ($now - $ts) < $window
    ));

    if (count($data['timestamps']) >= DISCOGS_RATE_LIMIT) {
        return false;
    }

    $data['timestamps'][] = $now;
    @file_put_contents($file, json_encode($data), LOCK_EX);
    return true;
}

function getDiscogsToken(): string {
    $env = getenv('DISCOGS_TOKEN');
    if ($env !== false && $env !== '') {
        return trim($env);
    }
    if (is_file(DISCOGS_TOKEN_FILE)) {
        return trim(file_get_contents(DISCOGS_TOKEN_FILE));
    }
    return '';
}

/**
 * Auto-create the discogs_cache table if it doesn't exist.
 */
function ensureDiscogsCacheTable(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS discogs_cache (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            record_id      INT NOT NULL,
            discogs_id     INT DEFAULT NULL,
            discogs_url    VARCHAR(500) DEFAULT \'\',
            label          VARCHAR(255) DEFAULT \'\',
            catalog_number VARCHAR(100) DEFAULT \'\',
            country        VARCHAR(100) DEFAULT \'\',
            format_detail  VARCHAR(255) DEFAULT \'\',
            num_for_sale   INT DEFAULT NULL,
            lowest_price   DECIMAL(10,2) DEFAULT NULL,
            median_price   DECIMAL(10,2) DEFAULT NULL,
            highest_price  DECIMAL(10,2) DEFAULT NULL,
            currency       VARCHAR(10) DEFAULT \'USD\',
            fetched_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_record (record_id),
            INDEX idx_record_id (record_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
}

/**
 * GET ?discogs=value&id=N — Fetch Discogs marketplace value for a record.
 * Returns cached data if fresh (< 7 days), otherwise queries Discogs API.
 */
function handleDiscogsValue(PDO $pdo): void {
    $recordId = (int) ($_GET['id'] ?? 0);
    if ($recordId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Record id is required.']);
        return;
    }

    try {

    // Get the record
    $stmt = $pdo->prepare('SELECT id, artist, album, year, format FROM records WHERE id = :id');
    $stmt->execute([':id' => $recordId]);
    $record = $stmt->fetch();
    if (!$record) {
        http_response_code(404);
        echo json_encode(['error' => 'Record not found.']);
        return;
    }

    ensureDiscogsCacheTable($pdo);

    // Allow cache bypass with &refresh=1
    $refresh = !empty($_GET['refresh']);

    // Check cache (fresh if < 7 days)
    if (!$refresh) {
        $stmt = $pdo->prepare('
            SELECT * FROM discogs_cache
            WHERE record_id = :rid AND fetched_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            LIMIT 1
        ');
        $stmt->execute([':rid' => $recordId]);
        $cached = $stmt->fetch();
    } else {
        // Delete stale entry so it gets re-fetched
        $pdo->prepare('DELETE FROM discogs_cache WHERE record_id = :rid')->execute([':rid' => $recordId]);
        $cached = false;
    }

    if ($cached) {
        echo json_encode([
            'record_id'      => $recordId,
            'discogs_id'     => (int) $cached['discogs_id'],
            'discogs_url'    => $cached['discogs_url'],
            'label'          => $cached['label'],
            'catalog_number' => $cached['catalog_number'],
            'country'        => $cached['country'],
            'format_detail'  => $cached['format_detail'],
            'num_for_sale'   => (int) $cached['num_for_sale'],
            'lowest_price'   => $cached['lowest_price'] !== null ? (float) $cached['lowest_price'] : null,
            'median_price'   => $cached['median_price'] !== null ? (float) $cached['median_price'] : null,
            'highest_price'  => $cached['highest_price'] !== null ? (float) $cached['highest_price'] : null,
            'currency'       => $cached['currency'],
            'source'         => 'cache',
        ]);
        return;
    }

    // Query Discogs API
    $token = getDiscogsToken();
    $headers = ['User-Agent: MyRecordsCollection/1.0 +https://mimetime.com'];
    if ($token !== '') {
        $headers[] = 'Authorization: Discogs token=' . $token;
    }

    // Step 1: Search for the release
    $artist = $record['artist'];
    $album  = $record['album'];
    $dbFormat = $record['format'] ?? '';
    $searchParams = [
        'q'       => $artist . ' ' . $album,
        'type'    => 'release',
        'per_page' => 10,
    ];
    // Map DB format to Discogs format filter
    $discogsFormat = mapFormatToDiscogs($dbFormat);
    if ($discogsFormat !== '') {
        $searchParams['format'] = $discogsFormat;
    }
    $searchUrl = 'https://api.discogs.com/database/search?' . http_build_query($searchParams);

    $searchResp = httpGet($searchUrl, $headers, 12);
    if ($searchResp === false) {
        http_response_code(502);
        echo json_encode(['error' => 'Failed to reach Discogs API. Set the DISCOGS_TOKEN env var or place a token in api/discogs_token.txt']);
        return;
    }

    $searchData = json_decode($searchResp, true);
    $results = $searchData['results'] ?? [];

    if (empty($results)) {
        echo json_encode([
            'record_id' => $recordId,
            'error'     => 'No matching release found on Discogs.',
            'artist'    => $artist,
            'album'     => $album,
        ]);
        return;
    }

    // Pick best match — prefer result whose format includes our DB format
    $release = pickBestFormatMatch($results, $dbFormat);
    $discogsId  = (int) ($release['id'] ?? 0);
    // Build Discogs web URL from resource_url or uri
    $discogsUrl = '';
    if (!empty($release['uri'])) {
        $discogsUrl = 'https://www.discogs.com' . $release['uri'];
    } elseif (!empty($release['resource_url'])) {
        // Convert API URL to web URL
        $discogsUrl = str_replace('api.discogs.com/', 'www.discogs.com/', $release['resource_url']);
    }
    $label   = '';
    if (!empty($release['label']) && is_array($release['label'])) {
        $label = $release['label'][0];
    }
    $catno   = $release['catno'] ?? '';
    $country = $release['country'] ?? '';
    $formatDetail = '';
    if (!empty($release['format']) && is_array($release['format'])) {
        $formatDetail = implode(', ', $release['format']);
    } elseif (!empty($release['format'])) {
        $formatDetail = (string) $release['format'];
    }

    // Step 2: Get marketplace stats
    $lowestPrice  = null;
    $medianPrice  = null;
    $highestPrice = null;
    $numForSale   = 0;
    $currency     = 'USD';

    // Rate limit: wait 1s between Discogs requests
    usleep(1100000);

    $statsUrl = "https://api.discogs.com/marketplace/stats/$discogsId?curr_abbr=USD";
    $statsResp = httpGet($statsUrl, $headers, 12);
    if ($statsResp !== false) {
        $stats = json_decode($statsResp, true) ?: [];
        // With curr_abbr, prices can be objects {value,currency} or plain numbers
        $lowestPrice  = extractPrice($stats, 'lowest_price');
        $medianPrice  = extractPrice($stats, 'median_price');
        $highestPrice = extractPrice($stats, 'highest_price');
        $numForSale   = (int) ($stats['num_for_sale'] ?? 0);
    }

    // Step 3: If no median from stats, try the release page community data
    if ($medianPrice === null) {
        usleep(1100000);
        $releaseUrl = "https://api.discogs.com/releases/$discogsId";
        $relResp = httpGet($releaseUrl, $headers, 12);
        if ($relResp !== false) {
            $relData = json_decode($relResp, true) ?: [];
            if (isset($relData['lowest_price'])) {
                $lowestPrice = $lowestPrice ?? (float) $relData['lowest_price'];
            }
            // community have/want can indicate value
            if (isset($relData['community']['have'])) {
                // Release endpoint has no median, but we use lowest as primary
            }
        }
    }

    // Store in cache
    try {
        $ins = $pdo->prepare('
            INSERT INTO discogs_cache
                (record_id, discogs_id, discogs_url, label, catalog_number, country, format_detail,
                 num_for_sale, lowest_price, median_price, highest_price, currency)
            VALUES
                (:rid, :did, :durl, :label, :catno, :country, :fmt,
                 :nfs, :low, :med, :high, :curr)
            ON DUPLICATE KEY UPDATE
                discogs_id     = VALUES(discogs_id),
                discogs_url    = VALUES(discogs_url),
                label          = VALUES(label),
                catalog_number = VALUES(catalog_number),
                country        = VALUES(country),
                format_detail  = VALUES(format_detail),
                num_for_sale   = VALUES(num_for_sale),
                lowest_price   = VALUES(lowest_price),
                median_price   = VALUES(median_price),
                highest_price  = VALUES(highest_price),
                currency       = VALUES(currency),
                fetched_at     = CURRENT_TIMESTAMP
        ');
        $ins->execute([
            ':rid'     => $recordId,
            ':did'     => $discogsId,
            ':durl'    => $discogsUrl,
            ':label'   => $label,
            ':catno'   => $catno,
            ':country' => $country,
            ':fmt'     => $formatDetail,
            ':nfs'     => $numForSale,
            ':low'     => $lowestPrice,
            ':med'     => $medianPrice,
            ':high'    => $highestPrice,
            ':curr'    => $currency,
        ]);
    } catch (PDOException $e) {
        error_log('[Records API] Discogs cache write failed: ' . $e->getMessage());
    }

    echo json_encode([
        'record_id'      => $recordId,
        'discogs_id'     => $discogsId,
        'discogs_url'    => $discogsUrl,
        'label'          => $label,
        'catalog_number' => $catno,
        'country'        => $country,
        'format_detail'  => $formatDetail,
        'num_for_sale'   => $numForSale,
        'lowest_price'   => $lowestPrice,
        'median_price'   => $medianPrice,
        'highest_price'  => $highestPrice,
        'currency'       => $currency,
        'source'         => 'discogs',
    ]);

    } catch (\Throwable $e) {
        error_log('[Records API][Discogs] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        echo json_encode(['error' => 'Discogs lookup failed. Please try again later.']);
    }
}

/**
 * Safely extract a price value from Discogs stats response.
 * Price can be: a plain number, an object {value, currency}, or missing.
 */
function extractPrice(array $data, string $key): ?float {
    if (!array_key_exists($key, $data) || $data[$key] === null) {
        return null;
    }
    $val = $data[$key];
    if (is_numeric($val)) {
        return (float) $val;
    }
    if (is_array($val) && isset($val['value'])) {
        return (float) $val['value'];
    }
    return null;
}

/**
 * Map DB format value to Discogs search format parameter.
 */
function mapFormatToDiscogs(string $dbFormat): string {
    $map = [
        'Vinyl'    => 'Vinyl',
        'CD'       => 'CD',
        'Cassette' => 'Cassette',
        'Digital'  => 'File',
    ];
    return $map[$dbFormat] ?? '';
}

/**
 * Pick the best matching release from Discogs search results based on format.
 * Prefers results whose format array contains a term matching the DB format.
 */
function pickBestFormatMatch(array $results, string $dbFormat): array {
    if (empty($results)) {
        return $results[0] ?? [];
    }
    $target = strtolower($dbFormat);
    // Discogs format array contains strings like "LP", "Vinyl", "CD", "Album", etc.
    // "Vinyl" in our DB should match "Vinyl" or "LP" in Discogs format list.
    $formatKeywords = [
        'vinyl' => ['vinyl', 'lp', '12"', '10"', '7"'],
        'cd'    => ['cd'],
    ];
    $keywords = $formatKeywords[$target] ?? [$target];

    foreach ($results as $r) {
        $formats = $r['format'] ?? [];
        if (!is_array($formats)) continue;
        $joined = strtolower(implode(' ', $formats));
        foreach ($keywords as $kw) {
            if (str_contains($joined, $kw)) {
                return $r;
            }
        }
    }
    // No format match found — fall back to first result
    return $results[0];
}

/**
 * GET ?discogs=valuate_all — Fetch Discogs values for all records without cache.
 * Returns summary of how many were priced.
 */
function handleDiscogsValuateAll(PDO $pdo): void {
    ensureDiscogsCacheTable($pdo);

    // Get records that don't have fresh cache entries
    $stmt = $pdo->query('
        SELECT r.id, r.artist, r.album, r.format
        FROM records r
        LEFT JOIN discogs_cache dc ON dc.record_id = r.id AND dc.fetched_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        WHERE dc.id IS NULL
        LIMIT 50
    ');
    $uncached = $stmt->fetchAll();

    $token = getDiscogsToken();
    if ($token === '' && !empty($uncached)) {
        http_response_code(400);
        echo json_encode(['error' => 'Discogs token required for bulk valuation. Set the DISCOGS_TOKEN env var or place a token in api/discogs_token.txt']);
        return;
    }

    $headers = [
        'User-Agent: MyRecordsCollection/1.0 +https://mimetime.com',
        'Authorization: Discogs token=' . $token,
    ];

    $priced  = 0;
    $skipped = 0;

    foreach ($uncached as $rec) {
        // Search with format filter
        $dbFormat = $rec['format'] ?? '';
        $searchParams = [
            'q'        => $rec['artist'] . ' ' . $rec['album'],
            'type'     => 'release',
            'per_page' => 5,
        ];
        $discogsFormat = mapFormatToDiscogs($dbFormat);
        if ($discogsFormat !== '') {
            $searchParams['format'] = $discogsFormat;
        }
        $searchResp = httpGet('https://api.discogs.com/database/search?' . http_build_query($searchParams), $headers, 12);
        if ($searchResp === false) { $skipped++; usleep(1100000); continue; }

        $results = (json_decode($searchResp, true))['results'] ?? [];
        if (empty($results)) { $skipped++; usleep(1100000); continue; }

        $release   = pickBestFormatMatch($results, $dbFormat);
        $discogsId = (int) $release['id'];

        usleep(1100000); // rate limit

        // Marketplace stats
        $statsResp = httpGet("https://api.discogs.com/marketplace/stats/$discogsId?curr_abbr=USD", $headers, 12);
        $lowestPrice = $medianPrice = $highestPrice = null;
        $numForSale = 0;
        $currency = 'USD';
        if ($statsResp !== false) {
            $stats = json_decode($statsResp, true) ?: [];
            $lowestPrice  = extractPrice($stats, 'lowest_price');
            $medianPrice  = extractPrice($stats, 'median_price');
            $highestPrice = extractPrice($stats, 'highest_price');
            $numForSale   = (int) ($stats['num_for_sale'] ?? 0);
        }

        // Build safe values from release
        $durl = '';
        if (!empty($release['uri'])) {
            $durl = 'https://www.discogs.com' . $release['uri'];
        } elseif (!empty($release['resource_url'])) {
            $durl = str_replace('api.discogs.com/', 'www.discogs.com/', $release['resource_url']);
        }
        $rlabel = (!empty($release['label']) && is_array($release['label'])) ? $release['label'][0] : '';
        $rfmt   = (!empty($release['format']) && is_array($release['format'])) ? implode(', ', $release['format']) : '';

        try {
            $ins = $pdo->prepare('
                INSERT INTO discogs_cache
                    (record_id, discogs_id, discogs_url, label, catalog_number, country, format_detail,
                     num_for_sale, lowest_price, median_price, highest_price, currency)
                VALUES
                    (:rid, :did, :durl, :label, :catno, :country, :fmt,
                     :nfs, :low, :med, :high, :curr)
                ON DUPLICATE KEY UPDATE
                    discogs_id     = VALUES(discogs_id),
                    discogs_url    = VALUES(discogs_url),
                    label          = VALUES(label),
                    catalog_number = VALUES(catalog_number),
                    country        = VALUES(country),
                    format_detail  = VALUES(format_detail),
                    num_for_sale   = VALUES(num_for_sale),
                    lowest_price   = VALUES(lowest_price),
                    median_price   = VALUES(median_price),
                    highest_price  = VALUES(highest_price),
                    currency       = VALUES(currency),
                    fetched_at     = CURRENT_TIMESTAMP
            ');
            $ins->execute([
                ':rid'     => $rec['id'],
                ':did'     => $discogsId,
                ':durl'    => $durl,
                ':label'   => $rlabel,
                ':catno'   => $release['catno'] ?? '',
                ':country' => $release['country'] ?? '',
                ':fmt'     => $rfmt,
                ':nfs'     => $numForSale,
                ':low'     => $lowestPrice,
                ':med'     => $medianPrice,
                ':high'    => $highestPrice,
                ':curr'    => $currency,
            ]);
            if ($lowestPrice !== null && $lowestPrice > 0) $priced++;
            else $skipped++;
        } catch (PDOException $e) {
            $skipped++;
        }

        usleep(1100000); // rate limit between pairs
    }

    echo json_encode([
        'message'   => "Valuation complete. $priced priced, $skipped skipped.",
        'priced'    => $priced,
        'skipped'   => $skipped,
        'remaining' => max(0, count($uncached) - $priced - $skipped),
    ]);
}
