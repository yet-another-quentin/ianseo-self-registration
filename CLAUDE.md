# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

IANSEO module (archery competition management software) that lets archers register themselves online to a competition. The organiser opens registration per competition, chooses the sessions and bow types offered and sets quotas per session and bow type. Requests stay pending until the organiser validates them. Code, identifiers and comments are in English. The UI is translated French/English (`SelfRegistration/i18n.js` for both pages, `sr_t()` in `lib.php` for server messages). Sister module with the same conventions: `../ianseo-entry-import`.

## Commands

```sh
node --test tests/*.test.js          # translations (same keys fr/en, every key used exists), nightly versioning
php tests/core.test.php              # pure logic of core.php (no IANSEO, no database)
for f in SelfRegistration/*.php; do php -l "$f"; done
```

No local PHP? Use `docker run --rm -v "$PWD":/app -w /app php:8.2-cli php tests/core.test.php`.

PHP and UI are verified against a real IANSEO in `dev/`:

```sh
# IANSEO_DIR = a COPY of the IANSEO sources (seed.sh writes its Common/config.inc.php: host db, user/password ianseo, ROOT_DIR='/').
cd dev && IANSEO_DIR=/path/to/copy docker compose up -d --build && IANSEO_DIR=/path/to/copy ./seed.sh   # http://localhost:8089/
```

- `seed.sh` loads `Install/install.sql`, opens the home page once (IANSEO upgrades its schema there), and creates competition TEST26 (FR rules, indoor type 6, sub-rule 4).
  - Session 1 holds 2 targets × 2 archers, to test full sessions and quotas.
  - It adds licenses with birth dates, and Cloudflare's Turnstile **test** keys: secret `1x…AA` always passes, `2x…AA` always fails.
- `localhost` is allowed over plain http (`sr_is_local()`); anything else requires HTTPS.
- Reference IANSEO sources are in `../ianseo`, read-only.

## Architecture

**The module never writes to the archer tables.** Validating requests builds "List load" lines (`sr_build_lines()`) that `admin.js` posts to `Partecipants/ListLoad.php` (`txtList`, `TextList=1`, **never** `OverwritePreviousArchers`, which would overwrite an archer's entry in another session). IANSEO imports them and shows its own report. Keep it that way.

The module writes only to its own tables `SelfRegRequests` (one row per requested session) and `SelfRegAttempts` (rate limiting), and to `ModulesParameters`.
- The tables are created by `sr_ensure_schema()`: bump `SR_SCHEMA` and add the migration there (v2: `SrLang`, `SrNotified`).
- IANSEO's `tour_delete()` does not know them: `sr_purge_orphans()` (admin page load) removes rows of deleted competitions.
- Never join module tables to IANSEO tables on string columns: collations may differ on upgraded installs. Query separately (see `sr_requests()`).

In `SelfRegistration/`:
- `core.php`: pure logic, tested by `tests/core.test.php`:
  - input checks (license `6 digits + letter`, date, email);
  - `sr_availability()`: free places and quotas per session for a division;
  - `sr_check_request()`;
  - `sr_build_lines()`.
- `lib.php`: database access. Every function takes the competition id explicitly, because the public form has no IANSEO competition session. Also parameters, schema, admin context and self-update (same mechanism as the sister module, prefix `sr_`).
- `security.php`: protections of the public form.
  - `sr_session_ini()` must run **before** IANSEO's `config.php` (it starts the session).
  - CSRF token in header `X-SR-Token`, plus an Origin/Referer check.
  - Turnstile server check (the hostname check is skipped for Cloudflare test keys).
  - Rate limits (`SR_LIMIT_*`).
  - The verified identity lives in `$_SESSION['SR']['verified'][ToId]` for `SR_VERIFY_TTL`.
- `register.php` + `register.js` + `register.css`: the **public** form, `register.php?c=<ToCode>`.
  - Stand-alone HTML (no IANSEO template) with a strict CSP: no inline script. The context is a `<script type="application/json" id="sr-ctx">` block.
  - `register.js` builds the DOM with `h()`, text nodes only, never `innerHTML` with data.
  - The header shows the competition as entered in IANSEO (`sr_tour_public()`): name, organiser (`ToComDescr`), venue (`ToVenue`), town (`ToWhere`) and dates, logos `ToImgL`/`ToImgR`, banner `ToImgB` at the bottom. The identify step also shows the schedule of open sessions, without places.
- `register-logo.php?c=<ToCode>&t=L|R|B`: public competition images.
  - Needed because IANSEO's `Common/TourLogo.php` requires a competition session.
  - Only served for competitions whose form can be shown.
  - Re-encoded as PNG and reduced by `sr_tour_image()` (GD), so only a real image leaves the server.
  - Cacheable: the URL carries the blob size (`v=`).
- `register-api.php`: public JSON API (`verify`, `offer`, `submit`).
  - Errors are `err.*` keys of `i18n.js`.
  - It uses `sr_json()`, not IANSEO's `JsonOut()`, which sends `Access-Control-Allow-Origin: *`.
  - `submit` ignores any license sent by the client and uses the verified one from the session.
  - Places are re-checked and booked under `GET_LOCK('selfreg_<ToId>')`. Never `exit` (`sr_json`) while the lock is held: `exit` skips `finally`.
- `index.php` + `admin.js` + `admin.css` + `api.php`: admin page (IANSEO template, `window.SR_CONTEXT`).
  - Rights: same as ListLoad (`AclParticipants`/`pAdvancedEntries`). Turnstile keys and updates require `AclRoot`.
  - `api.php` only accepts JSON bodies (a cross-site form cannot send them).
  - `admin.js` re-renders through `innerHTML`: every value goes through `esc()`/`th()`.
- `menu.php`: included by `Common/Menu.php` (`glob Modules/Custom/*/menu.php`) from both `get_which_menu` and `get_which_run_menu`; in the latter `$acl` and `$ret['PART']` may be missing.

## Emails (optional Mailer module, `../ianseo-mailer`)

- `mailer-templates.php` declares the templates `selfreg.received`, `selfreg.validated`, `selfreg.refused` and `selfreg.new` (organiser). Check it with Mailer's `tests/templates.test.php`.
- `sr_mailer()` loads `Modules/Custom/Mailer/api.php` only when installed and ready. Without it nothing is sent and nothing fails.
- Settings `mails.*` (per competition) switch each email on or off.
- **Acknowledgement** (`sr_notify_received()`, after `submit`): to the archer in the language of the form (`SrLang`), plus the organiser notification to `mailer_contact()`, in the competition's language, with the archer as Reply-To.
- **Refusal**: sent by the `refuse` action.
- **Validation**: `sr_notify_validated()` runs on every admin page load or API call and only notifies requests whose entry now exists, i.e. once the List load import went through.
  - That is why `admin.js` opens the List load report in another tab and refreshes on return.
  - Only decisions of the last 7 days are notified.
- `SrNotified` (1 validated, 2 refused) prevents duplicates; it is reset when a request goes back to pending.
- The public form language: `sr_lang(true)` (Lang, IANSEO cookie, then browser languages by primary subtag). IANSEO's `SelectLanguage()` only knows exact codes (`fr-FR` alone → English) and prefers the competition's print language.
- `sr_json()` sends `Content-Length`: Mailer sends emails after the answer, and without PHP-FPM the browser needs the length to stop waiting.
- Dev: `MAILER_DIR=../../ianseo-mailer/Mailer` in `dev/` mounts Mailer. Mailpit catches the emails on http://localhost:8025/ (SMTP `mailpit:1025`, no security).

## Deployment behind a reverse proxy (README › Putting IANSEO online safely)

- IANSEO has no usable login: `Modules/Authentication` is not in the public release, so `$CFG->USERAUTH` cannot be enabled.
- Its IP lock (`checkFullACL()`) is per competition: home, competition creation, IANSEO update and a fresh competition, hence the installation-wide settings, stay open. `127.0.0.1` always gets ReadWrite.
- The site is therefore protected at the proxy (Nginx Proxy Manager Access List), with a regex `location` in "Advanced" that leaves only the 6 public files of the form open. It proxies `$uri`, not `$request_uri`.
  - Tested with a reproduction of NPM's template: path tricks (`..`, `%2F`, `;`, case) all get 401.
- IANSEO's Apache needs `mod_remoteip` (X-Real-IP from the proxy network) and `HTTPS=on` from `X-Forwarded-Proto`, only for connections from the proxy (`CONN_REMOTE_ADDR`). Otherwise `REMOTE_ADDR` is the proxy for every visitor, which breaks the rate limits, and `getMyScheme()` says http.
- The session cookie is `Secure` once HTTPS is detected: tests over plain http must pass the cookie by hand.
- `sr_api_guard()` compares host **names** only: NPM forwards `Host: $host`, without the port.

## IANSEO behaviour the code relies on (checked in the sources)

- **ListLoad**:
  - processes lines **in order** and inserts entries inline, so `##TARGET##` and `##EMAIL##` lines come after the entry lines;
  - a 4-column line (license, session, division, class) takes name, sex, birth date and club from `LookUpEntries` (exact `LueCode` + `ToIocCode`, `ORDER BY LueDefault DESC`, the query reused by `sr_lookup()`);
  - inserted entries get `EnTargetFace=0`: a `##TARGET##` line is always sent, with the face's index among the faces matching `(div+class LIKE TfClasses OR REGEXP TfRegExp)` ordered by `TfId` (`sr_face_index()`);
  - `##EMAIL##` refuses addresses outside `^[a-z0-9._#-]+@[a-z0-9._-]+$` (no `+`), and the form applies the same rule;
  - `;` is turned into a tab, so values go through `sr_line_value()`.
- **Class**: ListLoad recomputes the age class (`calculateAgeClass()`: year of `ToWhenTo` − birth year, classes ordered by `ClViewOrder`) and keeps the sent class when it is one of them. `sr_class_for()` sends the first age class, so the import agrees. The FFTA season shift is already applied to `ClAgeFrom/To` by the FR rules.
- **Faces offered**: same matching and order as `getTargets()` (`Participants/getRows.php`); the first one is the default.
- **Places**:
  - capacity = `SesTar4Session × SesAth4Target` (as `Partecipants/CheckSession.php`);
  - taken = entries with `EnAthlete=1` in the session (any status, like IANSEO) + pending requests;
  - validated requests count through the entries they created. A validated request without an entry is flagged in the admin page and can be put back to pending.
- **License validity**: statuses 0 and 1 valid; status forced to 5 when `LueStatusValidUntil` < `ToWhenFrom`. `LueCtrlCode` holds the birth date.

## Persistence

- Parameters are stored like `setModuleParameter()` does (serialized), but the value is always a JSON string. `sr_param_get/set($TourId, …)` use direct SQL, with TourId 0 = installation-wide.
- Per competition: `settings` (open, deadline, sessions, divisions, quotas, allowFace).
- Installation-wide: `turnstile` (site/secret, never exported with a competition), `trustCf`, `channel`, `updateCheck`, `schema`.
- `MpParameter` is at most 30 characters.

## Releases and self-update

Same as `../ianseo-entry-import`:
- a `vX.Y.Z` tag runs `release.yml` (stable "latest" release);
- `nightly.yml` publishes the `nightly` pre-release when `main` changed;
- `version.json` stays `0.0.0-dev` in the repo;
- the archive must contain only `SelfRegistration/…` with `SelfRegistration/version.json`;
- the GitHub repo (`yet-another-quentin/ianseo-self-registration`) must be public.

## Git

- Commit messages: [gitmoji](https://gitmoji.dev/) prefix (Unicode emoji), English, a single line, no `Co-Authored-By`.
- Commits are GPG-signed. If signing fails because pinentry cannot prompt, ask the user to run the commit.
