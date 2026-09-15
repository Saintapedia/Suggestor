# SaintapediaSuggest production deploy

**Stable release: v0.7.0** — pin prod to this tag. Do not track floating `main`.

See [CHANGELOG.md](./CHANGELOG.md). Requires **Extension:Cargo**.

> The extension directory must be named `SaintapediaSuggest`, not `Suggestor`
> (the repo name). `wfLoadExtension()` resolves by directory.

## Install

1. Place the extension:

   ```bash
   cd /path/to/mediawiki/w/extensions   # or user-extensions on Canasta
   git clone --branch v0.7.0 --depth 1 \
     https://github.com/Saintapedia/Suggestor.git SaintapediaSuggest
   ```

   On Canasta, if code lives under `user-extensions/`, ensure a symlink:

   ```bash
   ln -sfn ../user-extensions/SaintapediaSuggest /path/to/w/extensions/SaintapediaSuggest
   ```

2. Enable **after Cargo** — Canasta `settings.yaml`:

   ```yaml
   extensions:
     - Cargo
     - SaintapediaSuggest
   ```

3. Configure the parts that stay in `LocalSettings.php` — secrets, and the
   handful of settings not meant to be wiki-editable:

   ```php
   $wgSaintapediaSuggestMode       = 'public';
   $wgSaintapediaSuggestNamespaces = [ NS_MAIN ];

   // hCaptcha via ConfirmEdit — secrets here, never on a wiki page.
   // (Skip if another extension already loads ConfirmEdit/hCaptcha.)
   $wgHCaptchaSiteKey   = 'your-site-key';
   $wgHCaptchaSecretKey = getenv( 'HCAPTCHA_SECRET' );
   ```

   Running alongside **SaintapediaFeedback**? Let its panel own the entry point
   so readers see one button instead of two:

   ```php
   $wgSaintapediaSuggestEntryPoint = 'none';   // needs SaintapediaFeedback >= 1.8.0
   ```

   **Everything else is wiki-editable, no deploy needed** — one
   `MediaWiki:`-namespace page per setting (`editinterface` right required to
   edit), read with an hour's WAN cache and invalidated immediately on save:

   | Page | Holds | PHP fallback |
   |------|-------|--------------|
   | `MediaWiki:SaintapediaSuggest-tables` | The allow-list. **Nothing is suggestable until a table is opted in** — that is the safe default, not a misconfiguration. One line per table: `Table: Field1, Field2`, `Table: *` for every field, or bare `Table` (also every field). | `$wgSaintapediaSuggestTables` |
   | `MediaWiki:SaintapediaSuggest-ratelimit` | A single integer, the per-IP daily cap. | `$wgSaintapediaSuggestRateLimit` |
   | `MediaWiki:SaintapediaSuggest-require-captcha` | `true`/`false`. | `$wgSaintapediaSuggestRequireCaptcha` |
   | `MediaWiki:SaintapediaSuggest-enabled` | `true`/`false`, master widget switch. | `$wgSaintapediaSuggestEnabled` |
   | `MediaWiki:SaintapediaSuggest-notify-users` | One username per line, who gets Echo alerts. | `$wgSaintapediaSuggestNotifyUsers` |

   Example `MediaWiki:SaintapediaSuggest-tables` page body:

   ```
   Footprints: LocationTitle, Coordinates, Address, City, AdministrativeSubdivision, Country, Diocese, SiteType, VisitAccess, EventYear, Sources
   ```

   `$wgSaintapediaSuggestRowLabelField` (which field names each row of a
   multi-row table in the picker) has no wiki-page override yet and stays in
   `LocalSettings.php`:

   ```php
   $wgSaintapediaSuggestRowLabelField = [ 'Footprints' => 'LocationTitle' ];
   ```

4. **Run `update.php`** — required; this extension has its own tables:

   ```bash
   php maintenance/run.php update.php
   # Canasta: canasta maintenance exec -i <instance> -- php maintenance/run.php update.php
   ```

   Creates `sps_suggestion` and `sps_suggestion_log`.

5. Restart web and confirm **Special:Version** lists SaintapediaSuggest **0.7.0**.

## Smoke checklist

| Check | Expected |
|-------|----------|
| Article **with** an allow-listed Cargo row (anon) | **Suggest a correction** button |
| Article **without** one | No button at all |
| Open the panel | Field picker lists only allow-listed fields, with current values |
| Page storing several rows in one table | Picker groups by row, each named |
| Submit a change | Thank-you; row on the dashboard with the correct before-value |
| Submit the value already stored | Refused ("same as the value already stored") |
| Submit the same correction twice | One queue item showing **+1 other reader** |
| `Special:SaintapediaSuggest` as anon | Permission error, not a stack trace |
| …as sysop | Dashboard with chips, filters, bulk actions |
| Mark actioned **without** editing the data | Item shows **Actioned, but unchanged** |
| JSON export | No email, no IP hash |
| `ProcessSuggestions.php --dry-run` | Prints a payload, posts nothing |

## Rights

| Right | Default | Meaning |
|-------|---------|---------|
| `saintapediasuggest-view` | sysop | Dashboard access |
| `saintapediasuggest-viewemail` | sysop | See the submitter's contact email |
| `saintapediasuggest-export` | sysop | Download the JSON export |

## Rollback

```bash
cd extensions/SaintapediaSuggest && git fetch --tags && git checkout v0.7.0
# or remove SaintapediaSuggest from settings.yaml and restart
```

Removing the extension leaves `sps_suggestion` in place; no data is lost and
nothing else reads those tables. This extension never writes to Cargo, so a
rollback cannot corrupt wiki content.

> If `extension.json` is missing, the whole wiki can fatal on every request.
> Keep the load line only when the directory is present.

## Known gaps

- **Never run with real reader traffic.** Every claim below rests on 123 unit
  tests, 78 integration tests and scratch-wiki verification, not production use.
- `>= 1.39` is accurate by inspection (core's `HISTORY` for the namespace moves,
  plus the `class_alias` shims in 1.43) but has not been executed on a real
  1.39 or 1.40 wiki. Tested on 1.43.9.
- The freshness check costs one Cargo query per (page, table) on a dashboard
  screen, capped at 50 groups. On a very busy wiki, watch the slow-query log for
  the first day, or set `$wgSaintapediaSuggestFreshnessCheck = false`.
- Schema is MySQL/MariaDB only (raw `sql/*.sql`, not abstract schema), matching
  the sibling extensions. SQLite and Postgres are untested.
