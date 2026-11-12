# KpiId

A MediaWiki extension for signing in through [KPI ID](https://auth.kpi.ua), the single sign-on of Igor Sikorsky
Kyiv Polytechnic Institute. Only people whose EMPLOYEE_ID an administrator has put on a wiki account get in, and
they get into that account. KPI ID never creates accounts.

It builds on [PluggableAuth](https://www.mediawiki.org/wiki/Extension:PluggableAuth) and
[OpenID Connect](https://www.mediawiki.org/wiki/Extension:OpenID_Connect) and configures both itself.

## How it works

- Each allowed account carries one EMPLOYEE_ID, stored in `user_properties` under `kpiid-employee-id`. Only
  the `KpiId:assign` maintenance script sets it: the preferences and the options API cannot change or reset it.
- KPI ID returns the person's EMPLOYEE_IDs in the `employee_ids` claim (scope `employee_ids`). A sign-in is
  allowed only when exactly one account holds one of them, and only into that account.
- The first sign-in links the KPI ID subject to the account (OpenID Connect's `openid_connect` table). Every
  later sign-in is checked again, so removing the EMPLOYEE_ID from an account takes its access away.
- Anyone else gets a message telling them to ask a wiki administrator.
- On a wiki that people must sign in to read, the "login required" page shows KPI ID's sign-in button, the one
  its [button wizard](https://auth.kpi.ua/uk/button-wizard) hands out: the extension wraps that page's sign-in
  link (`kpiid-loginreqpagetext`) in the button's element and loads `kpi-id-signin.js` from the KPI ID issuer.
  The button leads to `Special:UserLogin` with the page to return to; without the script the plain link stays.
- The sign-in uses the authorization code flow with PKCE (`S256`).

## Requirements

- MediaWiki 1.42 or later.
- PluggableAuth 7.5.0 and OpenID Connect 8.3.0 or later, with their Composer dependencies installed.

## Installation

Put the extension into `extensions/KpiId` and load it after its dependencies:

```php
wfLoadExtension( 'PluggableAuth' );
wfLoadExtension( 'OpenIDConnect' );
wfLoadExtension( 'KpiId' );
```

OpenID Connect needs its Composer dependencies (`composer install --no-dev` in its directory). If one of the
three is missing, MediaWiki stops at startup with an error that names it.

[kpiua/mediawiki-pg](https://github.com/kpi-ua/mediawiki-pg) ships none of them: an image built on it adds all
three, pinned to commits, and loads them with `MW_EXTENSIONS=...,PluggableAuth,OpenIDConnect,KpiId`. The
extension adds no tables, but run `update.php` once after enabling OpenID Connect for its `openid_connect`
table.

## Configuration

Register an application in KPI ID with the redirect URI
`https://<wiki host><$wgArticlePath with Special:PluggableAuthLogin>`, e.g.
`https://wiki.example.kpi.ua/index.php/Special:PluggableAuthLogin`. It is always the English `Special:` form,
also on a wiki in another language. The application needs the scopes `openid`, `profile` and `employee_ids`.

Each setting is read from `LocalSettings.php` first, then from the environment:

| Setting | Environment variable | Default |
| --- | --- | --- |
| `$wgKpiIdClientId` | `KPIID_CLIENT_ID` | none: KPI ID stays off |
| `$wgKpiIdClientSecret` | `KPIID_CLIENT_SECRET` | none: KPI ID stays off |
| `$wgKpiIdProviderUrl` | `KPIID_PROVIDER_URL` | `https://auth.kpi.ua` |

Use `https://auth.cloud.kpi.ua` for the KPI ID test environment.

The extension sets `$wgPluggableAuth_Config['KPI ID']` and the OpenID Connect options it relies on
(`MigrateUsersByUserName`, `MigrateUsersByEmail`, `PreferredUsernameProcessor`); do not set them yourself.
PluggableAuth's own switches stay yours. By default PluggableAuth hides the password form
(`$wgPluggableAuth_EnableLocalLogin = false`), so plan how administrators and bots sign in before you enable
the extension. [Bot passwords](https://www.mediawiki.org/wiki/Manual:Bot_passwords) keep working through the
API.

## Giving access

```bash
php maintenance/run.php KpiId:assign --user 'Name' --employee-id 12345
php maintenance/run.php KpiId:assign --user 'Name' --remove
php maintenance/run.php KpiId:assign --list
```

An EMPLOYEE_ID can belong to one account only; the script refuses a second one. To give access to someone
without a wiki account, create the account first (`createAndPromote.php` or `Special:CreateAccount`).

In the kpiua/mediawiki-pg container the settings file is chosen by the entrypoint, so pass it to a maintenance
script run through `docker exec`:

```bash
docker exec -e MW_CONFIG_FILE=/etc/mediawiki/LocalSettings.php <container> php maintenance/run.php KpiId:assign --list
```

## Logs

Refused sign-ins are logged to the `KpiId` channel with the EMPLOYEE_ID and the number of matching accounts.
PluggableAuth and OpenID Connect log the rest of the flow to their own channels.

## Development

```bash
composer install
composer test   # parallel-lint, minus-x, phpcs (MediaWiki rules), phpunit
composer fix    # minus-x and phpcbf
```

The unit tests cover the claim parsing and the protection of the stored EMPLOYEE_ID and run without MediaWiki.
The sign-in itself is tested by hand against KPI ID's test environment.

See [CONTRIBUTING.md](CONTRIBUTING.md) to contribute.

## License

[MPL-2.0](LICENSE)
