Feature: Mailhog enable, disable and status on a WordPress site
  Lifecycle of the per-site MailHog container, its DB flag (sites.mailhog_enabled), the postfix relayhost it sets (config/postfix/main.cf is the postfix config volume), site-name resolution, and idempotency of each subcommand.

  Background:
    Given the site "bh-mailhog-1.test" exists with flags "--type=wp --env=production"

  # Keep first: the site is new here (BeforeFeature removed leftovers).
  Scenario: A new WordPress site outside a dev environment has mailhog down
    When I run 'bin/ee mailhog status bh-mailhog-1.test'
    Then the command should succeed
    And STDOUT should be "Mailhog is DOWN for bh-mailhog-1.test site."
    And the site "bh-mailhog-1.test" should have "mailhog_enabled" set to "0"
    And the "mailhog" service of site "bh-mailhog-1.test" should not be running
    And the "postfix" service of site "bh-mailhog-1.test" should be running
    And the file "config/postfix/main.cf" of site "bh-mailhog-1.test" should not match "/^relayhost = mailhog:1025$/m"

  Scenario: Enabling mailhog starts the container, relays postfix to it and records it
    Given mailhog is disabled on site "bh-mailhog-1.test"
    When I run 'bin/ee mailhog enable bh-mailhog-1.test'
    Then the command should succeed
    And STDOUT should contain "Success: Mailhog enabled for bh-mailhog-1.test site"
    And the "mailhog" service of site "bh-mailhog-1.test" should be running
    And the "mailhog" service of site "bh-mailhog-1.test" should have env "VIRTUAL_HOST=bh-mailhog-1.test"
    And the "mailhog" service of site "bh-mailhog-1.test" should have env "VIRTUAL_PATH=/ee-admin/mailhog/"
    And the "mailhog" service of site "bh-mailhog-1.test" should have env "VIRTUAL_PORT=8025"
    And the "mailhog" service of site "bh-mailhog-1.test" should have label "io.easyengine.site=bh-mailhog-1.test"
    And the "postfix" service of site "bh-mailhog-1.test" should be running
    And the file "config/postfix/main.cf" of site "bh-mailhog-1.test" should match "/^relayhost = mailhog:1025$/m"
    And the site "bh-mailhog-1.test" should have "mailhog_enabled" set to "1"
    And the global admin-tools auth should exist
    When I execute "postconf -h relayhost" in the "postfix" service of site "bh-mailhog-1.test"
    Then STDOUT should be "mailhog:1025"

  Scenario: Status reports UP when mailhog is enabled
    Given mailhog is enabled on site "bh-mailhog-1.test"
    When I run 'bin/ee mailhog status bh-mailhog-1.test'
    Then the command should succeed
    And STDOUT should be "Mailhog is UP for bh-mailhog-1.test site."
    And STDERR should be empty

  Scenario: Enabling mailhog twice fails and changes nothing
    Given mailhog is enabled on site "bh-mailhog-1.test"
    When I run 'bin/ee mailhog enable bh-mailhog-1.test'
    Then the exit code should be 1
    And STDERR should contain "Error: Mailhog is already up."
    And STDOUT should not contain "Mailhog enabled for"
    And the "mailhog" service of site "bh-mailhog-1.test" should be running
    And the site "bh-mailhog-1.test" should have "mailhog_enabled" set to "1"
    And the file "config/postfix/main.cf" of site "bh-mailhog-1.test" should match "/^relayhost = mailhog:1025$/m"

  Scenario: --force re-runs enable when mailhog is already up
    Given mailhog is enabled on site "bh-mailhog-1.test"
    When I run 'bin/ee mailhog enable bh-mailhog-1.test --force'
    Then the command should succeed
    And STDOUT should contain "Global auth exists on admin-tools. Use `ee auth list global` to view credentials."
    And STDOUT should contain "Success: Mailhog enabled for bh-mailhog-1.test site"
    And the "mailhog" service of site "bh-mailhog-1.test" should be running
    And the site "bh-mailhog-1.test" should have "mailhog_enabled" set to "1"
    And the file "config/postfix/main.cf" of site "bh-mailhog-1.test" should match "/^relayhost = mailhog:1025$/m"

  Scenario: --force enables mailhog when it is down
    Given mailhog is disabled on site "bh-mailhog-1.test"
    When I run 'bin/ee mailhog enable bh-mailhog-1.test --force'
    Then the command should succeed
    And STDOUT should contain "Success: Mailhog enabled for bh-mailhog-1.test site"
    And the "mailhog" service of site "bh-mailhog-1.test" should be running
    And the site "bh-mailhog-1.test" should have "mailhog_enabled" set to "1"

  Scenario: Disabling mailhog stops the container, clears the relayhost and records it
    Given mailhog is enabled on site "bh-mailhog-1.test"
    When I run 'bin/ee mailhog disable bh-mailhog-1.test'
    Then the command should succeed
    And STDOUT should contain "Success: Mailhog disabled for bh-mailhog-1.test site"
    And the "mailhog" service of site "bh-mailhog-1.test" should not be running
    And the "postfix" service of site "bh-mailhog-1.test" should be running
    And the file "config/postfix/main.cf" of site "bh-mailhog-1.test" should match "/^relayhost =\s*$/m"
    And the site "bh-mailhog-1.test" should have "mailhog_enabled" set to "0"
    When I execute "postconf -h relayhost" in the "postfix" service of site "bh-mailhog-1.test"
    Then the command should succeed
    And STDOUT should be empty

  Scenario: Status reports DOWN after mailhog is disabled
    Given mailhog is disabled on site "bh-mailhog-1.test"
    When I run 'bin/ee mailhog status bh-mailhog-1.test'
    Then the command should succeed
    And STDOUT should be "Mailhog is DOWN for bh-mailhog-1.test site."
    And STDERR should be empty

  Scenario: Disabling mailhog twice fails and changes nothing
    Given mailhog is disabled on site "bh-mailhog-1.test"
    When I run 'bin/ee mailhog disable bh-mailhog-1.test'
    Then the exit code should be 1
    And STDERR should contain "Error: Mailhog is already down."
    And STDOUT should not contain "Mailhog disabled for"
    And the "mailhog" service of site "bh-mailhog-1.test" should not be running
    And the site "bh-mailhog-1.test" should have "mailhog_enabled" set to "0"

  Scenario: Repeated enable/disable cycles leave a consistent state
    Given mailhog is disabled on site "bh-mailhog-1.test"
    When I run 'bin/ee mailhog enable bh-mailhog-1.test'
    Then the command should succeed
    When I run 'bin/ee mailhog disable bh-mailhog-1.test'
    Then the command should succeed
    When I run 'bin/ee mailhog enable bh-mailhog-1.test'
    Then the command should succeed
    When I run 'bin/ee mailhog disable bh-mailhog-1.test'
    Then the command should succeed
    When I run 'bin/ee mailhog enable bh-mailhog-1.test'
    Then the command should succeed
    When I run 'bin/ee mailhog status bh-mailhog-1.test'
    Then STDOUT should be "Mailhog is UP for bh-mailhog-1.test site."
    And the site "bh-mailhog-1.test" should have "mailhog_enabled" set to "1"
    And the file "config/postfix/main.cf" of site "bh-mailhog-1.test" should match "/^relayhost = mailhog:1025$/m"

  Scenario Outline: A trailing slash on the site name is accepted by <subcommand>
    Given mailhog is <state> on site "bh-mailhog-1.test"
    When I run 'bin/ee mailhog <subcommand> bh-mailhog-1.test/'
    Then the command should succeed
    And STDOUT should contain "<output>"
    And the site "bh-mailhog-1.test" should have "mailhog_enabled" set to "<flag>"

    Examples:
      | state    | subcommand | output                                      | flag |
      | disabled | enable     | Mailhog enabled for bh-mailhog-1.test site  | 1    |
      | enabled  | status     | Mailhog is UP for bh-mailhog-1.test site.   | 1    |
      | enabled  | disable    | Mailhog disabled for bh-mailhog-1.test site | 0    |

  Scenario Outline: <subcommand> without a site name works from inside the site directory
    Given mailhog is <state> on site "bh-mailhog-1.test"
    And I am in the directory of site "bh-mailhog-1.test"
    When I run 'bin/ee mailhog <subcommand>'
    Then the command should succeed
    And STDOUT should contain "<output>"
    And the site "bh-mailhog-1.test" should have "mailhog_enabled" set to "<flag>"

    Examples:
      | state    | subcommand | output                                      | flag |
      | disabled | status     | Mailhog is DOWN for bh-mailhog-1.test site. | 0    |
      | disabled | enable     | Mailhog enabled for bh-mailhog-1.test site  | 1    |
      | enabled  | status     | Mailhog is UP for bh-mailhog-1.test site.   | 1    |
      | enabled  | disable    | Mailhog disabled for bh-mailhog-1.test site | 0    |

  Scenario: A wrong site name from inside a site directory suggests the right one
    Given I am in the directory of site "bh-mailhog-1.test"
    When I run 'bin/ee mailhog status bh-mailhog-nope.test'
    Then the exit code should be 1
    And STDERR should contain "bh-mailhog-nope.test is not a valid site-name. Did you mean `ee mailhog status bh-mailhog-1.test`?"
