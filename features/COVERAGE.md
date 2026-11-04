# mailhog-command Behat coverage

Suite for `ee mailhog enable|disable|status`. Sites are named `bh-mailhog-<n>.test`; `@BeforeFeature` and `@AfterFeature` delete only sites with the `bh-mailhog-` prefix. HTTP checks resolve the site name to 127.0.0.1 (whitelisted by the global admin-tools auth, arrives as 10.1.0.1) or to the public IP (`hostname -I`, override with `BEHAT_PUBLIC_IP`), where auth applies.

## Inventory

### Commands, arguments and flags

| # | Item | Covered by |
|---|---|---|
| C1 | `ee mailhog` (no subcommand) prints usage of the 3 subcommands | mailhog.feature: "ee mailhog without a subcommand lists its subcommands" |
| C2 | `ee help mailhog enable/disable/status` synopsis and docs | mailhog.feature: "ee help mailhog <subcommand> documents its synopsis" (3 examples) |
| C3 | Unknown subcommand | mailhog.feature: "An unknown mailhog subcommand is rejected" |
| C4 | `enable [<site-name>]` | mailhog-lifecycle.feature: "Enabling mailhog starts the container, relays postfix to it and records it" and most others |
| C5 | `enable --force` when up | mailhog-lifecycle.feature: "--force re-runs enable when mailhog is already up" |
| C6 | `enable --force` when down | mailhog-lifecycle.feature: "--force enables mailhog when it is down" |
| C7 | `disable [<site-name>]` | mailhog-lifecycle.feature: "Disabling mailhog stops the container, clears the relayhost and records it" |
| C8 | `status [<site-name>]` UP / DOWN output and exit 0 | mailhog-lifecycle.feature: "Status reports UP when mailhog is enabled", "Status reports DOWN after mailhog is disabled", "A new WordPress site outside a dev environment has mailhog down" |
| C9 | Unknown flags (`--foo`, `--force` on disable/status) | mailhog.feature: "ee mailhog <subcommand> rejects the unknown flag <flag>" (3 examples) |
| C10 | More than one positional argument | mailhog.feature: "ee mailhog <subcommand> takes a single site name" (3 examples) |
| C11 | Global `--env=<env>` (site create auto-enables mailhog for develop/development/local/staging) | mailhog-site-integration.feature: "Creating a WordPress site with --env=<env> enables mailhog" (local, develop), "Creating a WordPress site with --env=production does not enable mailhog" |
| C12 | Global `--skip-tty` | used by the ee shell mail step (mailhog-mail.feature); not asserted on its own: it belongs to core |
| C13 | Global `--custom-compose` with mailhog | not covered: needs a custom compose file per site and belongs to core's compose helper; noted as a gap |
| C14 | Global `--quiet`, `--debug`, `--[no-]color` | not covered: core behaviour, not mailhog-specific |

### Site-name resolution (`auto_site_name()` + `Site::find()`)

| # | Item | Covered by |
|---|---|---|
| N1 | No site name outside any site directory | mailhog.feature: "ee mailhog <subcommand> without a site name outside any site directory fails" (3 examples) |
| N2 | Unknown site name | mailhog.feature: "ee mailhog <subcommand> on an unknown site fails" (3 examples) |
| N3 | Trailing slash (`site.test/`) | mailhog-lifecycle.feature: "A trailing slash on the site name is accepted by <subcommand>" (3 examples) |
| N4 | No site name from inside the site directory | mailhog-lifecycle.feature: "<subcommand> without a site name works from inside the site directory" (4 examples) |
| N5 | Wrong site name from inside a site directory ("Did you mean") | mailhog-lifecycle.feature: "A wrong site name from inside a site directory suggests the right one" |
| N6 | `http://` / `https://` URL form | not covered yet: `auto_site_name()` returns the URL form unchanged, so the lookup fails (its scenario comes back with the fix) |
| N7 | Disabled site ("does not exist / is not enabled") | mailhog-site-integration.feature: "mailhog <subcommand> refuses a disabled site" (3 examples) |

### Behaviour and side effects

| # | Item | Covered by |
|---|---|---|
| B1 | enable: `docker-compose up -d mailhog` (container running, env `VIRTUAL_HOST`, `VIRTUAL_PATH=/ee-admin/mailhog/`, `VIRTUAL_PORT=8025`, label `io.easyengine.site`) | mailhog-lifecycle.feature: "Enabling mailhog starts the container, ..." |
| B2 | enable: `postconf -e 'relayhost = mailhog:1025'` (main.cf on the `config_postfix` volume and the live `postconf -h`) + postfix restart | mailhog-lifecycle.feature: "Enabling mailhog starts the container, ..." |
| B3 | enable: `sites.mailhog_enabled = 1` | mailhog-lifecycle.feature: enable, --force, cycles |
| B4 | enable: global admin-tools auth exists afterwards (`ee auth list global`, htpasswd file) | mailhog-lifecycle.feature: "Enabling mailhog starts the container, ..."; mailhog-auth.feature: "The global admin-tools credentials are listed after enabling mailhog" |
| B5 | enable: "Global auth exists on admin-tools" hint when auth exists | mailhog-lifecycle.feature: "--force re-runs enable when mailhog is already up" |
| B6 | enable: creates the admin-tools auth (random password, htpasswd, whitelist of the frontend subnet) when none exists | not covered: needs the global admin-tools auth removed first; no ee command removes only that row (`ee auth delete global` errors when only the admin-tools row exists), and wiping it would change the machine's admin-tools password |
| B7 | enable twice: "Mailhog is already up." exit 1, no change | mailhog-lifecycle.feature: "Enabling mailhog twice fails and changes nothing" |
| B8 | disable: `docker-compose stop mailhog`, relayhost cleared, postfix restarted, `mailhog_enabled = 0` | mailhog-lifecycle.feature: "Disabling mailhog stops the container, ..." |
| B9 | disable twice: "Mailhog is already down." exit 1, no change | mailhog-lifecycle.feature: "Disabling mailhog twice fails and changes nothing" |
| B10 | Repeated enable/disable cycles | mailhog-lifecycle.feature: "Repeated enable/disable cycles leave a consistent state" |
| B11 | HTML sites (no mailhog service): "does not have support" for all 3 subcommands, DB untouched | mailhog-site-integration.feature: "HTML sites have no mailhog service, so mailhog <subcommand> fails" (3 examples) |
| B11b | PHP sites have the same mailhog service as WordPress: enable, status, disable, relayhost, UI | mailhog-site-integration.feature: "PHP sites support mailhog enable, status and disable" |
| B11c | `enable --force` skips the support check (html site gets success and `mailhog_enabled = 1`) | not covered yet: `--force` also skips the site-type support check (its scenario comes back with the fix) |
| B11d | enable sets up/reports the global admin-tools auth before checking the site (unknown, html) | not covered yet: the auth is set up before the site is validated (its scenario comes back with the fix) |
| B12 | State drift: container stopped outside ee | not covered yet: `status`/`disable`/`enable` look only at the container, never at `sites.mailhog_enabled` (its scenario comes back with the fix) |
| B12b | Reverse drift: disabled container started outside ee (or by `restart: always` on a daemon restart) | not covered yet: same cause as B12 (its scenario comes back with the fix) |
| B13 | Return values ignored: postfix stopped during enable | not covered yet: enable ignores the postconf, compose and restart results (its scenario comes back with the fix) |
| B14 | Return value of `docker-compose up mailhog` ignored (image pull failure) | not covered: needs a broken image or registry; same cause as B13 |
| B15 | `EE::error()` inside enable exits, so the step processor never rolls back | not covered: no failure point reachable without breaking the stack |

### Proxy, UI, auth and mail

| # | Item | Covered by |
|---|---|---|
| P1 | MailHog UI served at `/ee-admin/mailhog/` | mailhog-mail.feature: "The MailHog UI is served at /ee-admin/mailhog/ once mailhog is enabled" |
| P2 | MailHog API (`api/v2/messages`) through the proxy, valid JSON | mailhog-mail.feature: "The MailHog API answers with JSON"; mailhog-auth.feature (public IP with credentials) |
| P3 | Site root still served next to the mailhog path | mailhog-mail.feature: "The site itself is still served next to /ee-admin/mailhog/" |
| P4 | Public IP without credentials: 401, realm "Restricted <host> Mailhog" | mailhog-auth.feature: "Without credentials the public IP gets 401 with the Mailhog realm"; Host-header variant |
| P5 | Wrong credentials: 401 | mailhog-auth.feature: "Wrong credentials are rejected" |
| P6 | Admin-tools credentials: 200 UI and API | mailhog-auth.feature: "The admin-tools credentials open the MailHog UI and API from the public IP" |
| P7 | Whitelisted local requests: 200 without credentials | mailhog-auth.feature: "Whitelisted local requests need no credentials" |
| P8 | Site HTTP auth does not grant MailHog (admin-tools file wins) | mailhog-auth.feature: "Site HTTP auth credentials do not open MailHog" |
| P9 | After disable: path no longer reaches MailHog and stays behind auth | mailhog-mail.feature: "After disabling mailhog, ..."; mailhog-auth.feature: "With mailhog disabled, ..." |
| P10 | Subdomain multisite (`*.site`) and alias domains (VIRTUAL_HOST compared by entries: site-type-wp lists `*.site` twice for subdom sites) | mailhog-routing.feature: "Subdomain multisite subsites and alias domains serve /ee-admin/mailhog/" |
| P11 | HTTPS site (`--ssl=self`): HTTP 301 to HTTPS, HTTPS 200, auth on HTTPS | mailhog-routing.feature: "HTTPS sites redirect /ee-admin/mailhog/ to HTTPS and serve it there behind auth" |
| M1 | `wp_mail()` via `ee shell` captured by MailHog (subject, recipient) | mailhog-mail.feature: "wp_mail() sent through ee shell is captured by MailHog" |
| M2 | Several mails captured | mailhog-mail.feature: "Several mails are all captured" |
| M3 | `wp_mail()` still succeeds with mailhog disabled | mailhog-mail.feature: "wp_mail() still succeeds with mailhog disabled" |
| M4 | Capture after disable + re-enable | mailhog-mail.feature: "Mail is captured again after mailhog is disabled and re-enabled" |
| M5 | Mail sent while disabled is not captured later | not covered: postfix defers it and may retry into MailHog after re-enable (queue timing), so no stable assertion |

### Integration with other commands

| # | Item | Covered by |
|---|---|---|
| I1 | `ee site disable` keeps `mailhog_enabled`; `ee site enable` re-enables mailhog (`--force`) and the relay | mailhog-site-integration.feature: "Re-enabling a site brings mailhog back when it was enabled" |
| I2 | `ee site enable` keeps mailhog down when it was disabled | mailhog-site-integration.feature: "Re-enabling a site keeps mailhog down when it was disabled" |
| I3 | `ee site refresh` resets postfix then re-applies the mailhog relay | mailhog-mail.feature: "ee site refresh re-applies the mailhog relayhost after resetting postfix" |
| I4 | `ee site refresh` keeps mailhog down when disabled | mailhog-site-integration.feature: "ee site refresh keeps mailhog down when it was disabled" |
| I5 | `ee site restart` skips mailhog (state unchanged, capture keeps working) | mailhog-site-integration.feature: "ee site restart does not change the mailhog state (<state>)" (2 examples); mailhog-mail.feature: "ee site restart keeps mailhog up and capturing" |
| I6 | `ee site delete` removes the mailhog container, every other site container and the row | mailhog-site-integration.feature: "Deleting a site removes its mailhog container" |
| I7 | `ee site clone` re-runs `ee mailhog enable` on the clone | not covered: belongs to site-command's clone suite (needs the clone setup) |
| I8 | `ee auth delete global` regenerates the admin-tools auth when a site has mailhog | not covered: destructive to the machine's global credentials; belongs to auth-command, whose suite covers the admin-tools/global auth interplay (fixed in EasyEngine/auth-command#60) |

Coverage estimate: 64 inventory items, 49 covered, 6 not covered yet (known bugs, their scenarios come back with the fixes), C12 exercised but not asserted, 8 not covered with reasons above: about 77%. Excluding the core-owned global flags (C12 to C14), 49 of 61 mailhog-specific items, about 80%.

## Features and scenarios

| Feature | Sites | Scenarios (expanded) |
|---|---|---|
| mailhog.feature | none | 17 |
| mailhog-lifecycle.feature | bh-mailhog-1.test (wp) | 18 |
| mailhog-mail.feature | bh-mailhog-2.test (wp) | 10 |
| mailhog-auth.feature | bh-mailhog-3.test (wp) | 8 |
| mailhog-site-integration.feature | bh-mailhog-4/7/8.test (wp), bh-mailhog-5.test (html), bh-mailhog-6.test (php) | 16 |
| mailhog-routing.feature | bh-mailhog-9.test (wp subdom + alias bh-mailhog-9-alias.test), bh-mailhog-10.test (wp, `--ssl=self`) | 2 |
| Total | 10 sites | 71 (0 `@le`, 0 `@remote`, 0 `@destructive`) |

No `@le` scenario: HTTPS routing is checked with `--ssl=self`, which needs no public DNS or issuance. No `@destructive` scenario: nothing here changes global services or config; the only global side effect is that `ee mailhog enable` creates the global admin-tools auth if the machine has none (it is kept afterwards, as ee itself does).

Known bugs (scenarios held back until fixed): drift between container state and `sites.mailhog_enabled` (both directions); `http(s)://` site names (`auto_site_name()` in site-command, the same cause as in admin-tools, log and shell); enable ignores the postconf/compose/restart results; the global admin-tools auth is set up before the site check; `--force` skips the site-type support check. No `@known-bug` scenarios ship here: the 8 scenario rows written for these bugs are held back until their fixes land and then return as regular scenarios.

Estimated runtime: about 22 to 27 minutes (8 WordPress sites at 1 to 1.5 minutes each, 2 light sites, about 160 ee invocations; waits are polls capped at 30 to 60 seconds).

## Context changes compared with site-command's FeatureContext

- `I run` patterns are anchored (`/^I run '(.*)'$/` and `/^I run "(.*)"$/`): the upstream `/I run '(.*)'|"(.*)"/` matched every step containing double quotes, which made any quoted step ambiguous.
- Cleanup deletes only prefixed sites, before and after each feature, instead of the upstream fixed site list.
- `$commands` is declared (dynamic properties are deprecated since PHP 8.2), and `EE_ROOT_DIR` is only defined when core has not defined it yet.
- New shared steps (last-command assertions, sqlite reads, label-based container lookup, CURLOPT_RESOLVE HTTP helpers with polling, file checks) live in a marked section; mailhog-specific steps are in a second section.
- Upstream steps fixed: "There should be :n containers with labels" compared a string with an int using `===` and threw on a match, so it never failed; "Following containers of site :site should be removed" looked for compose v1 names (`<site>_<service>_1`) and always passed. Both now use label filters; the site delete scenario uses them.
- A failed scenario restarts the containers it stopped with "I stop ... directly", so later scenarios do not inherit a stopped postfix.

`vendor/bin/behat --dry-run --strict` reports no undefined or ambiguous steps. Its exit code is 1 even for the upstream site-command suite, because Behat 3.8 counts dry-run's skipped steps as failures under `--strict`.
