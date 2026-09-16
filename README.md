# SaintapediaSuggest

Lets readers suggest a correction to **one specific Cargo field** stored for a page,
and gives editors a dashboard to triage those suggestions.

| Audience | What they get |
|----------|----------------|
| **Readers** | A floating **Suggest a correction** button on articles that have Cargo data. Pick a field, see what is stored now, propose something else. No account needed (public mode), with hCaptcha, per-IP rate limits and block checks. Hide the button (×) for this tab; restore it from **Tools → Suggest a correction** |
| **Editors** | Dashboard + toolbox link (`saintapediasuggest-view`, granted to **sysop** by default). Seeing the submitter's contact email and downloading the JSON export each need their own separate right (`saintapediasuggest-viewemail`, `saintapediasuggest-export`), also sysop by default |

> **This version is collect + triage only.**
> Nothing in this extension writes back into `#cargo_store`, a template, or wikitext.
> Marking a suggestion **actioned** records that a human applied the change somewhere
> else — it does not apply it. That is deliberate: auto-applying reader input to
> structured data with no edit history and no attribution is not something this
> extension should do behind an admin's back.

Companion to [SaintapediaFeedback](https://github.com/Saintapedia/SaintapediaFeedback),
which collects *page-level* feedback. This one is *field-level*. The access model,
on-wiki config pages, status workflow and dashboard UX are deliberately the same, so
configuring one teaches you the other.

---

## Where is the dashboard?

| Wiki | Dashboard URL |
|------|----------------|
| **Path on any wiki** | `/wiki/Special:SaintapediaSuggest` |
| **Local Canasta dev** (`mwdev`, port 8080) | **http://localhost:8080/wiki/Special:SaintapediaSuggest** |
| Per article | `/wiki/Special:SaintapediaSuggest/<pageid>` |
| From an article page | Toolbox → **Field suggestions** |

---

## Requirements

| Dependency | Version | Required? |
|------------|---------|-----------|
| MediaWiki | >= 1.39 | yes |
| PHP | >= 8.1 | yes |
| [Cargo](https://www.mediawiki.org/wiki/Extension:Cargo) | any current release (tested on 3.9.2) | **yes** — no Cargo, no suggestable fields |
| [ConfirmEdit](https://www.mediawiki.org/wiki/Extension:ConfirmEdit) + hCaptcha | — | only for public/anonymous mode |
| [Echo](https://www.mediawiki.org/wiki/Extension:Echo) | — | optional, for notifications |

Class imports are deliberately kept to the un-namespaced core names — `Config`,
`User`, `OutputPage`, `WebRequest`, `ExtensionRegistry`, `Title`, `TitleFactory`
— rather than the `MediaWiki\…` namespaces those classes moved into after 1.39
(`Title` and `TitleFactory` as late as 1.41, per core's own `HISTORY`). Core
keeps `class_alias` shims for all of them, so one codebase runs unmodified on
1.39 through 1.43. Only classes that predate 1.39 are imported under their
namespace: `MediaWikiServices` (1.28), `UserIdentity` (1.35) and
`LoadExtensionSchemaUpdatesHook` (1.35). For the same reason the hook handler classes do not `implements`
the core hook interfaces — `BeforePageDisplayHook` changed namespace between
those releases, and `HookContainer` dispatches on method name anyway.

---

> **Deploying to a real wiki?** See [DEPLOY.md](./DEPLOY.md) for the pinned-tag
> install, the smoke checklist, rollback, and the known gaps.
> [CHANGELOG.md](./CHANGELOG.md) records what each release changed.

## Install (each wiki)

```bash
cd extensions
git clone https://github.com/Saintapedia/Suggestor.git SaintapediaSuggest
```

In `LocalSettings.php`, **after** Cargo:

```php
wfLoadExtension( 'SaintapediaSuggest' );
```

Then create the tables:

```bash
php maintenance/run.php update.php
# Canasta: canasta maintenance exec -i <instance> -- php maintenance/run.php update.php
```

Nothing is suggestable until you opt at least one table in — see the next section.
That is the safe default: installing this extension does not expose any of your
Cargo columns to readers.

---

## Choosing what readers may correct

`$wgSaintapediaSuggestTables` is the allow-list. Four accepted forms:

```php
// Explicit fields — recommended
$wgSaintapediaSuggestTables = [
	'Parishes' => [ 'Address', 'Phone', 'Website', 'MassTimes' ],
	'Dioceses' => [ 'Bishop', 'Cathedral' ],
];

// Every non-internal field in a table
$wgSaintapediaSuggestTables = [ 'Parishes' => '*' ];

// List form — same as '*' for each named table
$wgSaintapediaSuggestTables = [ 'Parishes', 'Dioceses' ];

// A single field
$wgSaintapediaSuggestTables = [ 'Parishes' => 'Phone' ];
```

A target must pass **two** independent gates before a reader can touch it:

1. It is in the allow-list above.
2. It exists in the live Cargo schema (`CargoUtils::getTableSchemas()`).

Gate 2 is what stops a typo or a stale LocalSettings entry from reaching the
database layer as an arbitrary identifier, and it silently drops fields that were
removed from a template since you wrote the allow-list.

Cargo's own bookkeeping columns (`_pageID`, `_pageName`, `_ID`, …) are **never**
suggestable — not under `'*'`, and not even if you list them explicitly.

### Pages with several Cargo rows

A page can store more than one row in the same table — three sightings on a
saint's page, a list of Mass times on a parish. Each row is offered separately
and a suggestion records which row it targets (Cargo's `_ID`), so a reviewer can
tell a correction to the third sighting from one to the first.

Rows are named in the picker by the first allow-listed field that has a value.
When that is not the natural name, say so:

```php
$wgSaintapediaSuggestRowLabelField = [
	'Sightings' => 'LocationTitle',
];
```

A row whose every candidate field is empty falls back to "Row 3".
`$wgSaintapediaSuggestMaxRowsPerTable` (default 25) caps how many rows of one
table a single page may offer.

On the common one-row page none of this is visible: the picker is a flat list of
field names, exactly as before.

### Pages in several Cargo tables

Also handled. The picker groups by table when a page has rows in more than one,
and by row when a table has more than one — showing only the levels that
actually vary, so a simple page stays simple.

---

## Public wiki (recommended defaults)

Open to anonymous readers, captcha on, tight limits:

```php
wfLoadExtension( 'SaintapediaSuggest' );

$wgSaintapediaSuggestMode       = 'public';   // captcha on by default
$wgSaintapediaSuggestNamespaces = [ NS_MAIN ];
$wgSaintapediaSuggestRateLimit  = 5;          // per IP per day
$wgSaintapediaSuggestEnableEmail = false;     // no contact email collected

$wgSaintapediaSuggestTables = [
	'Parishes' => [ 'Address', 'Phone', 'Website' ],
];

// hCaptcha — secrets stay here, never on a wiki page
wfLoadExtension( 'ConfirmEdit' );
wfLoadExtension( 'ConfirmEdit/hCaptcha' );
$wgCaptchaClass     = 'MediaWiki\\Extension\\ConfirmEdit\\hCaptcha\\HCaptcha';
$wgHCaptchaSiteKey  = 'your-site-key';
$wgHCaptchaSecretKey = getenv( 'HCAPTCHA_SECRET' );
```

If a captcha is required but the site key is missing, submits **fail closed** and
the widget shows a configuration error rather than silently accepting spam.

## Enterprise wiki

Mostly logged-in staff, relaxed limits, contact email on:

```php
wfLoadExtension( 'SaintapediaSuggest' );

$wgSaintapediaSuggestMode                = 'enterprise'; // captcha off by default
$wgSaintapediaSuggestNamespaces          = [ NS_MAIN, NS_PROJECT ];
$wgSaintapediaSuggestEnterpriseRateLimit = 50;
$wgSaintapediaSuggestEnableEmail         = true;
$wgSaintapediaSuggestMaxValueLength      = 1000;

$wgSaintapediaSuggestTables = [ 'Parishes' => '*', 'Dioceses' => '*' ];

// Notifications
$wgSaintapediaSuggestNotifyUsers    = [ 'DataSteward' ];
$wgSaintapediaSuggestNotifyWatchers = true;
$wgSaintapediaSuggestNotifyEmail    = 'data@example.org';
```

---

## Editor workflow

| URL / UI | Purpose |
|----------|---------|
| **`Special:SaintapediaSuggest`** | **Dashboard:** all suggestions, status chips, field filter, search, bulk process |
| `Special:SaintapediaSuggest/<pageid>` | One article |
| `Special:SaintapediaSuggest/detail/<id>` | One suggestion: full values, every duplicate report, and the complete status history |
| `Special:SaintapediaSuggest/export` | JSON of the current filters |
| `Special:SaintapediaSuggest/export/<pageid>` | JSON for one article |
| Toolbox → **Field suggestions** | Jump to this page's suggestions (users with the right) |

Each row has **Copy** (the proposed value) and **Edit article** (opens the
page in edit mode in a new tab). Nothing is written back: copy, paste into
the template, save, then mark the suggestion **actioned**.

The dashboard also has a **Go to page** box for jumping straight to one
article's suggestions by title.

Statuses: **new → reviewed → actioned / dismissed**. Every transition is written to
an append-only audit table (`sps_suggestion_log`) with the acting user, both statuses
and any reviewer note. A reviewer cannot move an item back to `new`.

Status changes use POST + CSRF token + redirect, so a browser refresh cannot replay one.

### Repeat reports are folded together

Ten readers noticing one wrong phone number should be one queue item, not ten.
When a submission matches an **open** suggestion for the same page and field,
it is stored as a duplicate of that canonical row, which shows a
**+N other readers** badge. The dashboard, its counts, and the field facets all
list canonical rows only; the detail view shows every individual report,
because a second reporter often supplies the source the first one omitted.

Two readers correcting **different rows** to the same value are not reporting
the same problem, so row identity is part of the duplicate key.

Matching is otherwise deliberately conservative. It folds case, surrounding and
internal whitespace, and the Unicode punctuation that phones and copy-paste substitute
silently (curly apostrophes, en/em dashes, non-breaking spaces). It does **not**
strip punctuation: `555-0100` and `5550100` are different proposed values and a
reviewer should see both.

Only open suggestions absorb duplicates. If a suggestion was dismissed and a new
reader proposes the same value again, that is evidence the dismissal may have
been wrong, so it gets its own queue item rather than disappearing into a closed
one. Set `$wgSaintapediaSuggestMergeDuplicates = false` to keep every submission
separate.

---

## Rights

| Right | Default | Meaning |
|-------|---------|---------|
| `saintapediasuggest-view` | sysop | Dashboard access — view and triage suggestions |
| `saintapediasuggest-viewemail` | sysop | See the submitter's contact email, independent of dashboard access |
| `saintapediasuggest-export` | sysop | Download the JSON export, independent of dashboard access |

The two secondary rights are genuinely independent: the export deliberately omits
the contact email, so holding `-export` never yields email addresses, and holding
`-viewemail` never yields a bulk download.

Blocked users are denied — dashboard access *and* submitting — including partial
blocks. An admin can rely on a block alone even under a broad access-group setting.

### Who can use the dashboard?

**`LocalSettings.php`-only, as of 0.8.0** — there is no `MediaWiki:`-namespace
page for this. Dashboard access, contact-email visibility, and export access
used to also be overridable from wiki pages editable by anyone holding
`editinterface`, with no deploy or code review; that override was removed
entirely (matching SaintapediaFeedback's identical fix), because a stray edit
to the email-access setting alone could add someone to the group that sees
readers' submitted contact emails.

```php
$wgSaintapediaSuggestAccessGroups       = [ 'sysop' ];  // dashboard access
$wgSaintapediaSuggestEmailAccessGroups  = [ 'sysop' ];  // contact-email visibility
$wgSaintapediaSuggestExportAccessGroups = [ 'sysop' ];  // JSON export
```

| Token / group | Meaning |
|---------------|---------|
| `sysop` | Administrators (**default** for all three) |
| `user` | Any named account — not anon, not a MediaWiki temp account |
| `autoconfirmed` | Autoconfirmed users |
| `*` | Everyone including anons — **never honored for email access**: a `*` in `EmailAccessGroups` is dropped (and logged) before the check runs, so contact email can never be made public regardless of configuration |

An unset or empty array falls back to `[ 'sysop' ]` for all three.
MediaWiki merges an extension's array-type config default with your
`LocalSettings.php` value (no `merge_strategy` is set on these), so the
effective list is actually `[ 'sysop', ...your value ]`, not a full
replacement — harmless if you're only adding groups, worth knowing if you
expected `'sysop'` to disappear.

---

## On-wiki config for operational settings (no deploy)

Only settings that are content curation, not abuse/access control, get a wiki
page. Rate limit, require-captcha, and the three access-group settings above
are `LocalSettings.php`-only — see "Who can use the dashboard?" above.

| Setting | Page (DB key, no prefix) | Format | Overrides |
|---------|---------------------------|--------|-----------|
| Cargo table/field allow-list | `SaintapediaSuggest-tables` | one `Table: Field1, Field2` / `Table: *` / bare `Table` line per table | `$wgSaintapediaSuggestTables` |
| Notify users | `SaintapediaSuggest-notify-users` | one username per line | `$wgSaintapediaSuggestNotifyUsers` |
| Widget on/off | `SaintapediaSuggest-enabled` | `true` / `false` | `$wgSaintapediaSuggestEnabled` |

All are WAN-cached for an hour and invalidated immediately on save, delete or move.

> **Secrets never go on a wiki page.** MediaWiki-namespace pages are world-*readable*
> even though editing needs `editinterface`. The hCaptcha secret key and any tokens
> belong in `LocalSettings.php` or the environment.

The allow-list (`$wgSaintapediaSuggestTables`, or the wiki page above) **is**
on-wiki overridable as of 0.7.0 — the earlier design note here calling it
LocalSettings-only is outdated. Two things worth knowing before editing that
page:

- A page whose content doesn't name any real, currently existing Cargo table
  (a typo, a dropped table) falls back to the PHP list rather than silently
  disabling every suggestion on the wiki.
- **Allow-listing a field publishes its current value**, not just an
  affordance to correct it — the widget's "currently stored" line shows it to
  every reader, whether or not the article's template actually prints that
  field anywhere. Only allow-list fields you're comfortable making visible.

---

## Configuration reference

| Variable | Default | Meaning |
|----------|---------|---------|
| `$wgSaintapediaSuggestTables` | `[]` | Allow-list of Cargo tables/fields. Empty = nothing suggestable |
| `$wgSaintapediaSuggestMode` | `'public'` | `public` (captcha on, short form) or `enterprise` (captcha off, long form, email on) |
| `$wgSaintapediaSuggestEnabled` | `true` | Master switch for the reader widget; dashboard stays reachable |
| `$wgSaintapediaSuggestEntryPoint` | `'button'` | `'button'` for our own floating button, `'none'` to let another extension open the panel via `mw.hook` |
| `$wgSaintapediaSuggestNamespaces` | `[ 0 ]` | Where the widget shows and the API accepts submissions |
| `$wgSaintapediaSuggestMaxFields` | `40` | Cap on (table, field) pairs exposed to one page's widget |
| `$wgSaintapediaSuggestMaxValueLength` | `500` | Max characters for a suggested value and the stored snapshot |
| `$wgSaintapediaSuggestMaxRowsPerTable` | `25` | Cap on rows of one Cargo table offered for a single page |
| `$wgSaintapediaSuggestFreshnessCheck` | `true` | Compare each suggestion against the live Cargo value and badge the notable cases |
| `$wgSaintapediaSuggestMaxFreshnessLookups` | `50` | Cap on distinct (page, table) pairs checked per dashboard screen |
| `$wgSaintapediaSuggestRowLabelField` | `[]` | Table => field naming each row in the picker |
| `$wgSaintapediaSuggestRateLimit` | `5` | Submissions per IP per day (public mode) |
| `$wgSaintapediaSuggestEnterpriseRateLimit` | `50` | Submissions per IP per day (enterprise mode) |
| `$wgSaintapediaSuggestEnableEmail` | `false` | Show the optional contact email field (auto-on in enterprise) |
| `$wgSaintapediaSuggestRequireCaptcha` | `null` | `null` = auto from mode; `true`/`false` overrides |
| `$wgSaintapediaSuggestNotifyUsers` | `[]` | Usernames receiving Echo alerts |
| `$wgSaintapediaSuggestNotifyWatchers` | `true` | Also alert page watchers who may triage |
| `$wgSaintapediaSuggestNotifyEmail` | `false` | One email address alerted per submission |
| `$wgSaintapediaSuggestMergeDuplicates` | `true` | Fold repeat reports of one value into a single queue item |
| `$wgSaintapediaSuggestWebhook` | `''` | HTTPS endpoint for the batch exporter. HTTP is refused |
| `$wgSaintapediaSuggestWebhookToken` | `''` | Optional Bearer token for the batch POST. Keep in LocalSettings / env |
| `$wgSaintapediaSuggestBatchSize` | `100` | Suggestions per exporter run (max 500) |
| `$wgSaintapediaSuggestAccessGroups` | `[ 'sysop' ]` | Dashboard access groups. `LocalSettings.php`-only, no wiki-page override |
| `$wgSaintapediaSuggestEmailAccessGroups` | `[ 'sysop' ]` | Contact-email visibility groups. `LocalSettings.php`-only; `*` is always dropped |
| `$wgSaintapediaSuggestExportAccessGroups` | `[ 'sysop' ]` | Export groups. `LocalSettings.php`-only |
| `$wgSaintapediaSuggestTablesPage` / `NotifyUsersPage` / `EnabledPage` | see table above | Renames the corresponding `MediaWiki:` config page. The equivalent `*Page` vars for access/email/export/rate-limit/require-captcha no longer exist |

---

## API

```
action=saintapediasuggest  (POST, csrf token required)
```

| Parameter | Required | Meaning |
|-----------|----------|---------|
| `pageid` | yes | Page whose Cargo row is being corrected |
| `table` | yes | Cargo table — must be allow-listed |
| `field` | yes | Cargo field — must be allow-listed and in the live schema |
| `rowid` | when ambiguous | Cargo `_ID` of the row being corrected. Optional for a single-row table (the omitted id is filled in). On a table with two or more rows for that page, omitting it is `sps-norow`, same as an id that does not belong to this page and table |
| `suggestedvalue` | yes | Proposed value |
| `comment` | no | Free-text explanation |
| `email` | no | Contact email, stored only when the email field is enabled |
| `captchaWord` | conditional | hCaptcha token when a captcha is required |

Checks run in this order, cheapest first, so a malformed request never burns the
reader's one-time hCaptcha token or an outbound `siteverify` round-trip:

**block → title/namespace → allow-list → row belongs to this page (or is
unambiguous) → current value exists → value non-empty / not unchanged → captcha
→ per-IP rate limit → insert**

The response is `{ result, id }` and nothing else. **Contact emails are never
exposed on the public API**, and the API never writes to Cargo.

Two things are deliberately re-derived server-side and not trusted from the client:
the **target** (re-validated against the allow-list and schema) and the
**current value** (re-read from Cargo). A client-supplied "current value" would let
a submitter fabricate the before-state a reviewer sees.

---

## Security model (public mode)

- Anonymous submit is intentional; hCaptcha + per-IP daily cap + block check gate it.
- The rate limit uses a **salted SHA-256 of the IP** (`$wgSecretKey`). The raw address is never stored.
- Rate-limit counting takes a named DB lock keyed on the IP hash, so concurrent
  submits cannot all pass the check at once — including the first-row case where
  the counted range is still empty. Duplicate folding takes a second lock keyed
  on the target (page, table, field, row), so two different IPs reporting the
  same value cannot both become canonical.
- Reader-supplied text reaches the dashboard through `Html::element()` /
  `->text()` only — never `rawElement` — so a suggested value cannot inject markup.
- Dashboard list and export queries never select the contact-email column; it is
  fetched separately for displayed rows only, and only for holders of
  `saintapediasuggest-viewemail`.
- Echo recipients are filtered through the access check **twice** (at submit and at
  format time), because the notification body carries the reader's proposed value.
- No raw SQL in request handlers: everything goes through `SuggestionStore` on the
  MediaWiki `ILoadBalancer`, and Cargo reads go through `CargoUtils::getDB()`
  (Cargo data may live in a separate database).

---

## Database

| Table | Purpose |
|-------|---------|
| `sps_suggestion` | One row per submission, including folded duplicates. Indexed on `(page_id, status, timestamp)`, `(status, timestamp)`, `(ip_hash, timestamp)`, `(table, field, status)`, `(page_id, table, field, duplicate_of)`, `(duplicate_of)`, `(batch_processed, timestamp)` |
| `sps_suggestion_log` | Append-only audit of every status change |

Migrations are registered in `includes/SchemaHooks.php`. Columns and indexes are
registered **separately** — `addExtensionField()` for the former,
`addExtensionIndex()` for the latter. A bare `CREATE INDEX` bundled into a column
patch aborts the rest of that patch when the index name already exists, and
MediaWiki cannot resume a half-applied patch file. This is not hypothetical:
MySQL keeps a composite index alive (minus the dropped column) when a column is
dropped, so any wiki where a column was dropped and re-added hits exactly that
clash.

---

## Development / tests

Two suites, split by what they need.

```bash
# Unit — pure helpers, no MediaWiki install, no database
phpunit -c phpunit.xml.dist
```

Covers allow-list parsing, Cargo's physical-column layout, duplicate matching,
batch payload construction and redaction, filter normalization, access-page
parsing, and the wiki-config resolution rules including the captcha
fail-closed path.

```bash
# Integration — needs a MediaWiki checkout with its require-dev packages
cd /path/to/mediawiki
php vendor/bin/phpunit --group SaintapediaSuggest
```

Integration tests covering `SuggestionStore` against a real database (rate-limit
locking, duplicate folding, audit entries, the per-page mutation guard, search
escaping, contact-email column exclusion, batch marking), `CargoFieldRegistry`
against a real Cargo schema, and the submit API end to end — refusal ordering,
the server-side snapshot, Coordinates fields, omitted `rowid` on multi-row
tables, and duplicate folding through HTTP.

**The Cargo tests build their own fixture** (`CargoFixtureTrait`) rather than
reading whatever the wiki contains. MediaWiki's test framework clones tables
into a prefixed test database, so pre-existing Cargo content is invisible — a
content-dependent suite silently skips every one of its assertions and looks
like it passed. The fixture declares one field of each Cargo layout, including
a list field and a Coordinates field, which are the two that have no column
under their own name.

> Production images — Canasta included — ship **without** MediaWiki's
> `require-dev` packages, so the integration suite cannot start on one. Use a
> separate checkout (`composer install`, a scratch database, `wfLoadExtension`
> for Cargo and this extension) rather than adding dev packages to a live wiki.

---

## Offline / LLM batch export (optional)

Posts pending suggestions to an external endpoint — an LLM classifier, a ticket
queue, a spreadsheet job — for triage help. It never changes a status and never
touches Cargo; it only records that a row has been sent.

```php
$wgSaintapediaSuggestWebhook      = 'https://triage.example.org/suggestions';
$wgSaintapediaSuggestWebhookToken = getenv( 'SUGGEST_WEBHOOK_TOKEN' );
$wgSaintapediaSuggestBatchSize    = 100;
```

```bash
php maintenance/run.php \
    extensions/SaintapediaSuggest/maintenance/ProcessSuggestions.php --dry-run

# Canasta:
# canasta maintenance exec -i <instance> -- php maintenance/run.php \
#     extensions/SaintapediaSuggest/maintenance/ProcessSuggestions.php
```

| Flag | Effect |
|------|--------|
| `--dry-run` | Print the payload; post nothing, mark nothing |
| `--webhook=<url>` | Override the configured endpoint |
| `--limit=<n>` | Rows this run (clamped to 500) |

Behaviour worth knowing before you point it at anything:

- **HTTPS only.** A plaintext endpoint is refused outright — the payload is
  reader-submitted content and the request carries your bearer token.
- **Failure is safe.** If the POST fails or returns a non-2xx status, no rows are
  marked exported, so the next run retries them. Rows are marked only after the
  endpoint accepts them.
- **The payload carries the suggestion, not the submitter.** No contact email, no
  IP hash, no private reviewer note — it crosses a boundary to a third party.
  It does include `duplicateCount`, so a classifier can weight corroborated
  reports.
- **Only open, canonical rows are sent.** Actioned and dismissed items have
  already had a human decision; folded duplicates travel as a count on their
  canonical row.
- **Tokens are redacted from log output**, including one smuggled into the URL's
  query string.

---

## Running alongside SaintapediaFeedback

The two are designed to coexist and share no namespace: separate database
tables, config variables, rights, special pages, API modules, ResourceLoader
modules, i18n keys and `MediaWiki:` config pages. They register some of the same
hooks, which MediaWiki supports — each gets its own handler.

The one place they genuinely compete is the bottom-right corner of an article.
SaintapediaFeedback puts its button there (and, when public counts are on, a
chip above it) at `z-index: 1001`. When both extensions are installed, this one
detects that and stacks above, measured on a live page as:

| Control | Occupies (px from bottom) |
|---------|---------------------------|
| SaintapediaFeedback button | 24 – 66 |
| SaintapediaFeedback counts chip (if enabled) | 80 – 108 |
| **SaintapediaSuggest button** | **120 – 158** |

A reader on such a wiki sees two buttons: *Improve this article* for
page-level feedback, *Suggest a correction* for a specific Cargo field. Each can
be dismissed independently for the tab.

> **Known limitation.** Both widgets load hCaptcha from the same URL. This one
> reuses a script tag another extension already injected, but SaintapediaFeedback
> injects unconditionally, so opening *its* panel second still adds a second tag.
> Observed effect is benign — hCaptcha initialises and both captchas render — but
> the clean fix is the same guard in SaintapediaFeedback's `loadHCaptchaScript()`.

---

## Is the suggestion still relevant?

This extension never writes to Cargo, so a queue item can sit while the data
moves on beneath it. The dashboard compares each suggestion against what Cargo
holds **now** and badges the cases worth a reviewer's attention:

| Badge | Meaning |
|-------|---------|
| **Already applied** | The stored value already equals what the reader proposed — somebody made the change. The item is busywork |
| **Data changed** | The field is now neither what the reader saw nor what they proposed, so the "Currently:" snapshot is stale |
| **Actioned, but unchanged** | Marked actioned, yet the stored value is still the one the reader reported. **The edit probably never happened** |

The last is the point of the whole thing: marking something actioned asserts the
change was made elsewhere, and nothing else in this extension checks that claim.

Comparison reuses the duplicate-matching normalization, so case, whitespace and
curly punctuation do not flip a badge on their own. Nothing is written — the
check is read-only.

```php
$wgSaintapediaSuggestFreshnessCheck = true;      // default
$wgSaintapediaSuggestMaxFreshnessLookups = 50;   // distinct (page, table) pairs per screen
```

Lookups are grouped by (page, table), so a screenful costs one Cargo query per
group rather than one per suggestion. Above the cap the check is skipped for that
screen — a missing hint rather than a wrong one, and never an unbounded number of
queries. Pre-0.3.0 rows that name no Cargo row are only compared when the table
holds exactly one row, since otherwise there is no way to know which they meant.

---

## One entry point instead of two

On a wiki also running
[SaintapediaFeedback](https://github.com/Saintapedia/SaintapediaFeedback), two
floating buttons compete for the same corner. This extension stacks above it by
default (see above), but the tidier arrangement is for one of them to own the
entry point:

```php
// Render the panel, but no button of our own.
$wgSaintapediaSuggestEntryPoint = 'none';
```

Then anything on the page can open it:

```javascript
mw.hook( 'saintapediasuggest.open' ).fire();
// …or land the reader on a particular field:
mw.hook( 'saintapediasuggest.open' ).fire( { table: 'Parishes', field: 'Phone' } );
```

To know whether the option is worth offering at all, listen for readiness — it
fires only on pages that actually have suggestable Cargo fields:

```javascript
mw.hook( 'saintapediasuggest.ready' ).add( function ( info ) {
	// info = { pageId, fieldCount, tables: [...], ownButton: false }
} );
```

A hook rather than a global is deliberate: firing into a hook nobody listens on
is a no-op, so a wiki without this extension installed degrades to nothing
rather than a `TypeError`. `'button'` (the default) is unchanged, and an
unrecognised value falls back to `'button'` rather than to no entry point at
all — a typo should not silently make the feature unreachable.

---

## Translations

`en` is the source. `es`, `fr`, `it` and `pt` cover the reader widget and the
dashboard; any message not present in a language falls back to English, so a
partial file is safe. The `qqq` file documents every message for translators.

For a wiki that wants broad language coverage, the right long-term path is
[translatewiki.net](https://translatewiki.net/) rather than hand-maintained
files here.

---

## Version

**0.8.0** — dashboard access, contact-email visibility, export access, rate
limit and require-captcha are `LocalSettings.php`-only; the wiki-page override
for all five is removed.

**0.7.0** — the Cargo table/field allow-list gets a `MediaWiki:`-namespace
override, joining the other operational settings.

**0.6.1** — pre-production review fixes: a stale Echo access check, a
row-id race on a multi-row table, and a field-switch value carryover in the
widget.

**0.6.0** — dashboard Copy + Edit article (still no write-back); omit `rowid`
on a multi-row table is refused; duplicate folding lock is per target, not
per IP; per-row status buttons encode the id so a list click cannot hit the
last row on the page.

**0.5.0** — flag suggestions the live Cargo value has moved past.

**0.4.0** — let another extension own the entry point.

**0.3.1** — actually meet the >= 1.39 requirement.

**0.3.0** — record which Cargo row a suggestion targets.

**0.2.1** — stack the widget above SaintapediaFeedback.

**0.2.0** — duplicate folding, suggestion detail view with audit trail, page
lookup, batch exporter, named config registry, es/fr/it/pt, integration suite.
Still collect + triage only; no write-back to Cargo.

**0.1.0** — initial scaffold.
