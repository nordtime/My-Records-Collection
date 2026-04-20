(() => {
    'use strict';

    const API = 'api/api.php';

    // Parse URL params: ?artist=...&album=...&title=...
    const params = new URLSearchParams(window.location.search);
    const artist = params.get('artist') || '';
    const album  = params.get('album')  || '';
    const title  = params.get('title')  || '';

    // DOM refs
    const $headerTitle  = document.getElementById('headerTitle');
    const $headerMeta   = document.getElementById('headerMeta');
    const $songTitle    = document.getElementById('songTitle');
    const $songArtist   = document.getElementById('songArtist');
    const $songAlbum    = document.getElementById('songAlbum');
    const $lyricsDisplay = document.getElementById('lyricsDisplay');
    const $lyricsEditor = document.getElementById('lyricsEditor');
    const $viewMode     = document.getElementById('viewMode');
    const $editMode     = document.getElementById('editMode');
    const $statusMsg    = document.getElementById('statusMsg');
    const $cover        = document.getElementById('lyricsCover');

    // Set cover art
    $cover.src = `api/cover.php?artist=${encodeURIComponent(artist)}&album=${encodeURIComponent(album)}`;

    // Set page info
    document.title = `🎤 ${title} — ${artist}`;
    $headerTitle.textContent = title;
    $headerMeta.textContent = `${artist} — ${album}`;
    $songTitle.textContent = title;
    $songArtist.textContent = artist;
    $songAlbum.textContent = album;

    // Load lyrics from DB
    loadLyrics();

    async function loadLyrics() {
        try {
            const p = new URLSearchParams({ lyrics: '1', artist, album, title });
            const res = await fetch(`${API}?${p}`);
            const data = await res.json();

            if (data.lyrics) {
                $lyricsDisplay.textContent = data.lyrics;
                $lyricsDisplay.classList.remove('empty');
            } else {
                $lyricsDisplay.textContent = 'No lyrics saved yet. Click "Edit Lyrics" to add them.';
                $lyricsDisplay.classList.add('empty');
            }
        } catch {
            $lyricsDisplay.textContent = 'Failed to load lyrics.';
            $lyricsDisplay.classList.add('empty');
        }
    }

    // Edit mode
    document.getElementById('btnEdit').addEventListener('click', () => {
        const current = $lyricsDisplay.classList.contains('empty') ? '' : $lyricsDisplay.textContent;
        $lyricsEditor.value = current;
        $viewMode.style.display = 'none';
        $editMode.style.display = 'block';
        $lyricsEditor.focus();
    });

    // Cancel edit
    document.getElementById('btnCancelEdit').addEventListener('click', () => {
        $editMode.style.display = 'none';
        $viewMode.style.display = 'block';
    });

    // Fetch from web
    const $fetchStatus = document.getElementById('fetchStatus');
    document.getElementById('btnFetchWeb').addEventListener('click', async () => {
        const $btn = document.getElementById('btnFetchWeb');
        $btn.disabled = true;
        $btn.textContent = '⏳ Searching…';
        $fetchStatus.classList.remove('visible', 'error');

        try {
            // Try server-side proxy first (handles CORS / multiple sources)
            const p = new URLSearchParams({ lyrics: '1', fetch: '1', artist, album, title });
            const res = await fetch(`${API}?${p}`);
            const data = await res.json();

            if (data.lyrics) {
                // Auto-save fetched lyrics to DB
                await fetch(`${API}?lyrics=1`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ artist, album, title, lyrics: data.lyrics }),
                });

                $lyricsDisplay.textContent = data.lyrics;
                $lyricsDisplay.classList.remove('empty');
                $fetchStatus.textContent = `Lyrics found via ${data.source || 'web'} and saved ✓`;
                $fetchStatus.classList.remove('error');
                $fetchStatus.classList.add('visible');
                setTimeout(() => $fetchStatus.classList.remove('visible'), 4000);
            } else {
                $fetchStatus.textContent = 'No lyrics found online for this song.';
                $fetchStatus.classList.add('visible', 'error');
                setTimeout(() => $fetchStatus.classList.remove('visible'), 4000);
            }
        } catch {
            $fetchStatus.textContent = 'Failed to fetch lyrics from web.';
            $fetchStatus.classList.add('visible', 'error');
            setTimeout(() => $fetchStatus.classList.remove('visible'), 4000);
        } finally {
            $btn.disabled = false;
            $btn.innerHTML = '&#127760; Fetch from Web';
        }
    });

    // Save lyrics
    document.getElementById('btnSave').addEventListener('click', async () => {
        const lyrics = $lyricsEditor.value.trim();
        $statusMsg.classList.remove('visible', 'error');

        try {
            const res = await fetch(`${API}?lyrics=1`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ artist, album, title, lyrics }),
            });
            const data = await res.json();

            if (!res.ok) {
                throw new Error(data.error || 'Save failed.');
            }

            // Update display
            if (lyrics) {
                $lyricsDisplay.textContent = lyrics;
                $lyricsDisplay.classList.remove('empty');
            } else {
                $lyricsDisplay.textContent = 'No lyrics saved yet. Click "Edit Lyrics" to add them.';
                $lyricsDisplay.classList.add('empty');
            }

            $editMode.style.display = 'none';
            $viewMode.style.display = 'block';

            $statusMsg.textContent = 'Lyrics saved ✓';
            $statusMsg.classList.remove('error');
            $statusMsg.classList.add('visible');
            setTimeout(() => $statusMsg.classList.remove('visible'), 3000);
        } catch (err) {
            $statusMsg.textContent = err.message || 'Failed to save lyrics.';
            $statusMsg.classList.add('visible', 'error');
        }
    });
})();
