# SaintapediaSuggest production deploy

**Stable release: v0.8.1** — pin prod to this tag. Do not track floating `main`.

See [CHANGELOG.md](./CHANGELOG.md). Requires **Extension:Cargo**.

> The extension directory must be named `SaintapediaSuggest`, not `Suggestor`
> (the repo name). `wfLoadExtension()` resolves by directory.

## Install

1. Place the extension:

   ```bash
   cd /path/to/mediawiki/w/extensions   # or user-extensions on Canasta
   git clone --branch v0.8.1 --depth 1 \
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

3. Configure the parts that stay in `LocalSettings.php` — secrets, and
   settings deliberately kept out of wiki pages because they're abuse/access
   controls, not content curation:

   ```php
   $wgSaintapediaSuggestMode       = 'public';
   $wgSaintapediaSuggestNamespaces = [ NS_MAIN ];

   // Abuse controls. No MediaWiki:-namespace override for these — see the
   // "Access control" note below.
   $wgSaintapediaSuggestRateLimit      = 5;      // public mode
   // $wgSaintapediaSuggestEnterpriseRateLimit = 50;   // enterprise mode
   $wgSaintapediaSuggestRequireCaptcha = null;   // null = auto from mode

   // Who may triage suggestions. 'user' = any logged-in named account, not
   // just sysop -- any registered account can open the dashboard and mark
   // items actioned. On a public wiki with open registration, that's a
   // deliberately low bar; use a named group (e.g. 'autoconfirmed') or
   // leave unset for the sysop-only default if that's too wide for your wiki.
   $wgSaintapediaSuggestAccessGroups = [ 'user' ];
   // Note: MediaWiki merges this with the extension's own ['sysop'] default
   // (no merge_strategy is set on this array config), so the effective list
   // is actually ['sysop', 'user'] -- harmless here since 'user' alone
   // already grants every logged-in account, but don't expect setting this
   // to fully replace the default rather than add to it.

   // Contact emails and bulk export stay sysop-only regardless of the
   // dashboard-access setting above -- deliberately more restrictive by
   // default, not widened by this change.
   // $wgSaintapediaSuggestEmailAccessGroups  = [ 'sysop' ];
   // $wgSaintapediaSuggestExportAccessGroups = [ 'sysop' ];

   // Echo-notify watchers of a page when a suggestion comes in on it, not
   // just the fixed notify-list. Defaults to true, but was a near no-op
   // while dashboard access was sysop-only (the alert is gated on the same
   // access check, precisely so it can't leak a reader's proposed value to
   // someone who can't open the dashboard) -- now meaningful with
   // AccessGroups widened above.
   // $wgSaintapediaSuggestNotifyWatchers = true;

   // Row label field: which field names each row of a multi-row table in
   // the picker. No wiki-page override.
   $wgSaintapediaSuggestRowLabelField = [ 'Footprints' => 'LocationTitle' ];

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

   **Content-curation settings are wiki-editable, no deploy needed** — one
   `MediaWiki:`-namespace page per setting (`editinterface` right required to
   edit), read with an hour's WAN cache and invalidated immediately on save:

   | Page | Holds | PHP fallback |
   |------|-------|--------------|
   | `MediaWiki:SaintapediaSuggest-tables` | The allow-list. **Nothing is suggestable until a table is opted in** — that is the safe default, not a misconfiguration. One line per table: `Table: Field1, Field2`, `Table: *` for every field, or bare `Table` (also every field). A page whose content doesn't name any real, currently existing Cargo table falls back to the PHP list rather than disabling every suggestion wiki-wide. | `$wgSaintapediaSuggestTables` |
   | `MediaWiki:SaintapediaSuggest-enabled` | `true`/`false`, master widget switch. | `$wgSaintapediaSuggestEnabled` |
   | `MediaWiki:SaintapediaSuggest-notify-users` | One username per line, who gets Echo alerts. | `$wgSaintapediaSuggestNotifyUsers` |

   **Allow-listing a field publishes its current value**, not just an
   affordance to correct it — the widget's "currently stored" line shows it
   to every reader, whether or not the article's template actually prints
   that field anywhere. Only allow-list fields you're comfortable making
   visible; this is a wiki-editable page, so treat it with the same care
   as any other reader-facing content.

   Example `MediaWiki:SaintapediaSuggest-tables` page body:

   ```
   Footprints: LocationTitle, Coordinates, Address, City, AdministrativeSubdivision, Country, Diocese, SiteType, VisitAccess, EventYear, Sources
   ```

   **Access control (rate limit, require-captcha, and who can triage /
   see emails / export) is `LocalSettings.php`-only, deliberately with no
   wiki-page override.** Through 0.7.0, all five had one — anyone holding
   `editinterface` could disable captcha, raise the submission cap, or add
   themselves to the group that sees readers' contact emails, with no
   deploy or code review. 0.8.0 removed that entirely, matching
   SaintapediaFeedback's identical 1.9.0 fix. If `editinterface` on your
   wiki is not a high-trust group, review who holds it.

4. **Run `update.php`** — required; this extension has its own tables:

   ```bash
   php maintenance/run.php update.php
   # Canasta: canasta maintenance exec -i <instance> -- php maintenance/run.php update.php
   ```

   Creates `sps_suggestion` and `sps_suggestion_log`.

5. Restart web and confirm **Special:Version** lists SaintapediaSuggest **0.8.1**.

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

These are independent of, and in addition to, the `*AccessGroups` settings
above — a user passes if they hold the right *or* belong to an allowed
group. With `SaintapediaSuggestAccessGroups = [ 'user' ]` (as configured
above), the `saintapediasuggest-view` right is redundant for dashboard
access specifically — any logged-in account already qualifies via the
group check.

## Rollback

```bash
cd extensions/SaintapediaSuggest && git fetch --tags && git checkout v0.8.1
# or remove SaintapediaSuggest from settings.yaml and restart
```

Removing the extension leaves `sps_suggestion` in place; no data is lost and
nothing else reads those tables. This extension never writes to Cargo, so a
rollback cannot corrupt wiki content.

> If `extension.json` is missing, the whole wiki can fatal on every request.
> Keep the load line only when the directory is present.

## Known gaps

- **Never run with real reader traffic.** Every claim below rests on 132 unit
  tests, 78 integration tests and scratch-wiki verification, not production use.
- `>= 1.39` is accurate by inspection (core's `HISTORY` for the namespace moves,
  plus the `class_alias` shims in 1.43) but has not been executed on a real
  1.39 or 1.40 wiki. Tested on 1.43.9.
- The freshness check costs one Cargo query per (page, table) on a dashboard
  screen, capped at 50 groups. On a very busy wiki, watch the slow-query log for
  the first day, or set `$wgSaintapediaSuggestFreshnessCheck = false`.
- Schema is MySQL/MariaDB only (raw `sql/*.sql`, not abstract schema), matching
  the sibling extensions. SQLite and Postgres are untested.
