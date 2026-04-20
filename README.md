# My Records Collection

A web app for managing your vinyl, CD, and cassette collection — with metadata lookup, marketplace valuation, and tracklist/lyrics support.

![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-4479A1?logo=mysql&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-green)

## Features

- **Collection CRUD** — Add, edit, delete records with artist, album, year, genre, format, condition grade, notes, and cover art
- **Metadata Lookup** — Auto-fill from MusicBrainz, iTunes, and Deezer
- **Discogs Valuation** — Per-record and bulk marketplace value lookup with rate limiting and caching
- **Tracklists** — Fetch track listings from MusicBrainz (multi-disc support)
- **Lyrics** — Per-track lyrics with save/fetch support
- **Search & Filter** — Text search, genre/format filters, sortable columns
- **Import/Export** — CSV import (drag-and-drop) and export with downloadable template
- **Stats Dashboard** — Collection statistics overview
- **Duplicate Detection** — Prevents adding the same album twice

## Tech Stack

| Layer    | Technology                          |
|----------|-------------------------------------|
| Frontend | Vanilla JS, HTML5, CSS              |
| Backend  | PHP 8.x (FastCGI)                   |
| Database | MySQL / MariaDB (PDO)               |
| Server   | IIS (with `web.config`) or Apache   |
| APIs     | MusicBrainz, iTunes, Deezer, Discogs |

## Setup

### 1. Database

Create a MySQL database and import the schema. The app auto-creates required tables on first use.

### 2. Environment Variables

Set these on your server (e.g. via IIS FastCGI settings, Apache `SetEnv`, or system env vars):

| Variable       | Description            | Default            |
|----------------|------------------------|--------------------|
| `RC_DB_HOST`   | Database host          | `127.0.0.1`        |
| `RC_DB_NAME`   | Database name          | `record_collection` |
| `RC_DB_USER`   | Database username      | *(required)*        |
| `RC_DB_PASS`   | Database password      | *(required)*        |
| `DISCOGS_TOKEN`| Discogs API token      | *(optional)*        |

Get a Discogs token at [discogs.com/settings/developers](https://www.discogs.com/settings/developers).

### 3. PHP Extensions

Ensure these PHP extensions are enabled:

- `pdo_mysql`
- `curl`
- `json`
- `gd` (for cover image handling)

### 4. Deploy

Point your web server's document root to the project folder. On IIS, the included `web.config` handles routing and security headers. For Apache, configure equivalent rewrite rules.

## Project Structure

```
├── index.html          # Main app page
├── lyrics.html         # Lyrics viewer page
├── web.config          # IIS configuration
├── css/
│   └── style.css       # Styles
├── js/
│   ├── app.js          # Main app logic
│   └── lyrics.js       # Lyrics page logic
├── api/
│   ├── api.php         # REST API endpoints
│   ├── cover.php       # Cover art proxy
│   └── db.php          # Database configuration
└── covers/             # Uploaded cover images (gitignored)
```

## API Endpoints

All endpoints are at `api/api.php?action=`:

| Action            | Method | Description                     |
|-------------------|--------|---------------------------------|
| `list`            | GET    | List all records                |
| `get`             | GET    | Get a single record             |
| `add`             | POST   | Add a new record                |
| `update`          | POST   | Update a record                 |
| `delete`          | POST   | Delete record(s)                |
| `stats`           | GET    | Collection statistics           |
| `export`          | GET    | Export as CSV                   |
| `import`          | POST   | Import from CSV                 |
| `discogs_value`   | GET    | Discogs valuation for a record  |
| `bulk_valuation`  | GET    | Bulk Discogs valuation          |
| `tracklist`       | GET    | Fetch tracklist from MusicBrainz|
| `lyrics`          | GET    | Fetch/get lyrics for a track    |
| `save_lyrics`     | POST   | Save lyrics for a track         |

## Contributing

Contributions are welcome! Please open an issue or submit a pull request.

## License

MIT
