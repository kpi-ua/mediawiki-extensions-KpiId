# AGENTS.md — KpiId MediaWiki extension

## Organisation rules

@.ai/AGENTS.md

This repository follows the kpi-ua organisation rules in [`.ai/`](.ai/). Read them before working here and take
the rules from there:

- [`.ai/AGENTS.md`](.ai/AGENTS.md) — working rules: Jira, branches, commits, pull requests, code, language,
  validation, secrets, live systems.
- [`.ai/REVIEW.md`](.ai/REVIEW.md) — how to review pull requests.
- [`.ai/GIT_MESSAGES.md`](.ai/GIT_MESSAGES.md) — commit messages, pull request titles and descriptions.

The files in `.ai/` are copies from kpi-ua/.ai: change them there, not here. The rest of this file is this
repository's own context; where it departs from `.ai/`, it says so explicitly.

## Project

A MediaWiki extension: sign-in through KPI ID (OpenID Connect) for wiki accounts an administrator has linked to
an EMPLOYEE_ID. It configures PluggableAuth and OpenID Connect itself; see [README.md](README.md) for the
behaviour and the settings.

- `extension.json` — registration, hooks, config defaults.
- `includes/Hooks.php` — PluggableAuth configuration, authorization, the redirect URI fix, the denial message.
- `includes/Matcher.php` — reading the `employee_ids` claim, the account lookup, the stored-property guard.
- `maintenance/assign.php` — `KpiId:assign`, the only way to set an account's EMPLOYEE_ID.
- `i18n/` — messages: `en.json`, `uk.json`, and their documentation in `qqq.json`.
- `tests/phpunit/` — unit tests that run without MediaWiki.

Stack: PHP 8.3, MediaWiki 1.46, PluggableAuth 7.5, OpenID Connect 8.3. Code follows the MediaWiki coding
conventions (tabs, `phpcs` with `mediawiki/mediawiki-codesniffer`).

## Commands

```bash
composer install
composer test   # lint, minus-x, phpcs, phpunit
composer fix
```

Without a local PHP: `docker run --rm -v "$PWD":/src:ro composer:2 sh -c 'cp -r /src /work && cd /work &&
composer install -q && composer test'`. Copy instead of mounting the tree directly: on macOS bind mounts show
every file as executable and minus-x fails.

## Invariants

Keep these when changing the code; each was found the hard way:

- The user property must not start with `userjs-`: people can set those options themselves.
- EMPLOYEE_IDs reach the database as a list of strings. PHP turns numeric string keys into ints, and PostgreSQL
  refuses `text = integer`.
- The redirect URI is the English `Special:PluggableAuthLogin` (`GetFullURL`), and that page is exempt from the
  canonical redirect (`TestCanonicalRedirect`); without the second hook the wiki loops.
- Authorization is checked on every sign-in, not only the first, and never creates an account.

## Testing by hand

Run kpiua/mediawiki-pg with this directory mounted at `/var/www/html/extensions/KpiId`,
`MW_EXTENSIONS=PluggableAuth,OpenIDConnect,KpiId` and a test application from KPI ID's test environment
(`KPIID_PROVIDER_URL=https://auth.cloud.kpi.ua`, applications at https://manage-auth.cloud.kpi.ua). Keep the
client secret in an env file outside the repository. After editing PHP in a running container run
`apachectl -k graceful`: OPcache revalidates only every 60 seconds.
