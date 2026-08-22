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

Class imports are deliberately kept to the un-namespaced core names
(`Config`, `User`, `OutputPage`, `WebRequest`, `ExtensionRegistry`) rather than the
`MediaWiki\…` namespaces those classes moved into after 1.39. Core keeps
`class_alias` shims for all of them, so one codebase runs unmodified on 1.39
through 1.43. For the same reason the hook handler classes do not `implements`
the core hook interfaces — `BeforePageDisplayHook` changed namespace between
those releases, and `HookContainer` dispatches on method name anyway.

---

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
| `Special:SaintapediaSuggest/export` | JSON of the current filters |
| `Special:SaintapediaSuggest/export/<pageid>` | JSON for one article |
| Toolbox → **Field suggestions** | Jump to this page's suggestions (users with the right) |

Statuses: **new → reviewed → actioned / dismissed**. Every transition is written to
an append-only audit table (`sps_suggestion_log`) with the acting user, both statuses
and any reviewer note. A reviewer cannot move an item back to `new`.

Status changes use POST + CSRF token + redirect, so a browser refresh cannot replay one.

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
blocks. An admin can rely on a block alone even under a broad access-page setting.

### Who can use the dashboard?

Same pattern as SaintapediaFeedback. Edit `MediaWiki:SaintapediaSuggest-access`,
one group per line:

```
# Administrators (default)
sysop

# Any named account (not temp / not anon):
# user

# Or restrict further, for example:
# editor
# autoconfirmed
```

| Token / group | Meaning |
|---------------|---------|
| `sysop` | Administrators (**default**) |
| `user` | Any named account — not anon, not a MediaWiki temp account |
| `autoconfirmed` | Autoconfirmed users |
| `*` | Everyone including anons (not recommended). A line that is only `*` works; `* *` is the wiki-list form |

Blank lines, `#` and `;` comments, and `*` list markers are all handled.
A missing or empty page falls back to `$wgSaintapediaSuggestAccessGroups` (`[ 'sysop' ]`).

Email and export have their own pages with the same syntax:
`MediaWiki:SaintapediaSuggest-email-access` and `MediaWiki:SaintapediaSuggest-export-access`.

---

## On-wiki config for operational settings (no deploy)

| Setting | Page (DB key, no prefix) | Format | Overrides |
|---------|---------------------------|--------|-----------|
| Rate limit | `SaintapediaSuggest-ratelimit` | non-negative integer (`0` = reject every submit; delete the page to revert to PHP, do not write `0`) | `$wgSaintapediaSuggestRateLimit` / `EnterpriseRateLimit` (mode-appropriate one) |
| Notify users | `SaintapediaSuggest-notify-users` | one username per line | `$wgSaintapediaSuggestNotifyUsers` |
| Require captcha | `SaintapediaSuggest-require-captcha` | `true` / `false` | `$wgSaintapediaSuggestRequireCaptcha` (and the mode-based auto default) |
| Widget on/off | `SaintapediaSuggest-enabled` | `true` / `false` | `$wgSaintapediaSuggestEnabled` |
| Dashboard access | `SaintapediaSuggest-access` | one group per line | `$wgSaintapediaSuggestAccessGroups` |
| Email access | `SaintapediaSuggest-email-access` | one group per line | `$wgSaintapediaSuggestEmailAccessGroups` |
| Export access | `SaintapediaSuggest-export-access` | one group per line | `$wgSaintapediaSuggestExportAccessGroups` |

All are WAN-cached for an hour and invalidated immediately on save, delete or move.

> **Secrets never go on a wiki page.** MediaWiki-namespace pages are world-*readable*
> even though editing needs `editinterface`. The hCaptcha secret key and any tokens
> belong in `LocalSettings.php` or the environment.
>
> `SaintapediaSuggest-require-captcha` is security-sensitive: anyone with
> `editinterface` can turn the captcha off without code review. If a cache or DB
> read of that page fails, the captcha **fails closed** (stays on) rather than
> silently disabling itself.

The allow-list itself (`$wgSaintapediaSuggestTables`) is deliberately **not**
on-wiki overridable — it decides which of your structured columns are exposed, and
that belongs under code review.

---

## Configuration reference

| Variable | Default | Meaning |
|----------|---------|---------|
| `$wgSaintapediaSuggestTables` | `[]` | Allow-list of Cargo tables/fields. Empty = nothing suggestable |
| `$wgSaintapediaSuggestMode` | `'public'` | `public` (captcha on, short form) or `enterprise` (captcha off, long form, email on) |
| `$wgSaintapediaSuggestEnabled` | `true` | Master switch for the reader widget; dashboard stays reachable |
| `$wgSaintapediaSuggestNamespaces` | `[ 0 ]` | Where the widget shows and the API accepts submissions |
| `$wgSaintapediaSuggestMaxFields` | `40` | Cap on (table, field) pairs exposed to one page's widget |
| `$wgSaintapediaSuggestMaxValueLength` | `500` | Max characters for a suggested value and the stored snapshot |
| `$wgSaintapediaSuggestRateLimit` | `5` | Submissions per IP per day (public mode) |
| `$wgSaintapediaSuggestEnterpriseRateLimit` | `50` | Submissions per IP per day (enterprise mode) |
| `$wgSaintapediaSuggestEnableEmail` | `false` | Show the optional contact email field (auto-on in enterprise) |
| `$wgSaintapediaSuggestRequireCaptcha` | `null` | `null` = auto from mode; `true`/`false` overrides |
| `$wgSaintapediaSuggestNotifyUsers` | `[]` | Usernames receiving Echo alerts |
| `$wgSaintapediaSuggestNotifyWatchers` | `true` | Also alert page watchers who may triage |
| `$wgSaintapediaSuggestNotifyEmail` | `false` | One email address alerted per submission |
| `$wgSaintapediaSuggestAccessGroups` | `[ 'sysop' ]` | Dashboard groups when the access page is missing/empty |
| `$wgSaintapediaSuggestEmailAccessGroups` | `[ 'sysop' ]` | Contact-email groups |
| `$wgSaintapediaSuggestExportAccessGroups` | `[ 'sysop' ]` | Export groups |
| `$wgSaintapediaSuggest*Page` | see table above | Renames the corresponding `MediaWiki:` config page |

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
| `suggestedvalue` | yes | Proposed value |
| `comment` | no | Free-text explanation |
| `email` | no | Contact email, stored only when the email field is enabled |
| `captchaWord` | conditional | hCaptcha token when a captcha is required |

Checks run in this order, cheapest first, so a malformed request never burns the
reader's one-time hCaptcha token or an outbound `siteverify` round-trip:

**block → title/namespace → allow-list → current value exists → value non-empty /
not unchanged → captcha → per-IP rate limit → insert**

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
  the counted range is still empty.
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
| `sps_suggestion` | One row per suggestion. Indexed on `(page_id, status, timestamp)`, `(status, timestamp)`, `(ip_hash, timestamp)`, `(table, field, status)` |
| `sps_suggestion_log` | Append-only audit of every status change |

---

## Development / tests

```bash
# Pure unit tests — no MediaWiki install needed
phpunit -c phpunit.xml.dist
```

The unit suite covers the service-free helpers: allow-list parsing, filter
normalization, access-page parsing and the wiki-config resolution rules
(including the captcha fail-closed path). Anything touching the database or
`MediaWikiServices` belongs in an integration test run through MediaWiki's own
`phpunit.php`.

---

## Version

**0.1.0** — initial scaffold. Collect + triage only; no write-back to Cargo.
