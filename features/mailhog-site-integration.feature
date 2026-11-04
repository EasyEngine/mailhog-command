Feature: Mailhog and the site lifecycle
  How mailhog interacts with other ee commands and site types: HTML sites (no mailhog service), PHP sites (same service as WordPress), site create on dev environments, site disable/enable/refresh/restart/delete, and state drift behind ee's back.

  # Keep first: it creates bh-mailhog-4.test, which the later scenarios reuse.
  Scenario: Creating a WordPress site with --env=production does not enable mailhog
    When I run 'bin/ee site create bh-mailhog-4.test --type=wp --env=production'
    Then the command should succeed
    And STDOUT should not contain "Enabling mailhog"
    And the site "bh-mailhog-4.test" should have "mailhog_enabled" set to "0"
    And the "mailhog" service of site "bh-mailhog-4.test" should not be running

  Scenario Outline: Creating a WordPress site with --env=<env> enables mailhog
    When I run 'bin/ee site create <site> --type=wp --env=<env>'
    Then the command should succeed
    And STDOUT should contain "Enabling mailhog as this is a <env> server."
    And STDOUT should contain "Mailhog enabled for <site> site"
    And the site "<site>" should have "mailhog_enabled" set to "1"
    And the "mailhog" service of site "<site>" should be running
    And the file "config/postfix/main.cf" of site "<site>" should match "/^relayhost = mailhog:1025$/m"

    Examples:
      | env     | site              |
      | local   | bh-mailhog-7.test |
      | develop | bh-mailhog-8.test |

  Scenario Outline: HTML sites have no mailhog service, so mailhog <subcommand> fails
    Given the site "bh-mailhog-5.test" exists with flags "--type=html"
    When I run 'bin/ee mailhog <subcommand> bh-mailhog-5.test'
    Then the exit code should be 1
    And STDERR should contain "bh-mailhog-5.test site does not have support to enable/disable mailhog."
    And the site "bh-mailhog-5.test" should have "mailhog_enabled" set to "0"
    And the "mailhog" service of site "bh-mailhog-5.test" should not exist

    Examples:
      | subcommand |
      | enable     |
      | disable    |
      | status     |

  # PHP sites get the same mailhog service as WordPress sites (site-type-php Site_PHP_Docker), so the whole lifecycle works there too.
  Scenario: PHP sites support mailhog enable, status and disable
    Given the site "bh-mailhog-6.test" exists with flags "--type=php"
    And mailhog is disabled on site "bh-mailhog-6.test"
    When I run 'bin/ee mailhog enable bh-mailhog-6.test'
    Then the command should succeed
    And STDOUT should contain "Success: Mailhog enabled for bh-mailhog-6.test site"
    And the "mailhog" service of site "bh-mailhog-6.test" should be running
    And the site "bh-mailhog-6.test" should have "mailhog_enabled" set to "1"
    And the file "config/postfix/main.cf" of site "bh-mailhog-6.test" should match "/^relayhost = mailhog:1025$/m"
    And I wait up to 30 seconds until a GET request to "http://bh-mailhog-6.test/ee-admin/mailhog/" returns 200
    And the response body should contain "MailHog"
    When I run 'bin/ee mailhog status bh-mailhog-6.test'
    Then STDOUT should be "Mailhog is UP for bh-mailhog-6.test site."
    When I run 'bin/ee mailhog disable bh-mailhog-6.test'
    Then the command should succeed
    And the "mailhog" service of site "bh-mailhog-6.test" should not be running
    And the site "bh-mailhog-6.test" should have "mailhog_enabled" set to "0"
    And the file "config/postfix/main.cf" of site "bh-mailhog-6.test" should match "/^relayhost =\s*$/m"
    When I run 'bin/ee mailhog status bh-mailhog-6.test'
    Then STDOUT should be "Mailhog is DOWN for bh-mailhog-6.test site."

  Scenario Outline: mailhog <subcommand> refuses a disabled site
    Given the site "bh-mailhog-4.test" exists with flags "--type=wp --env=production"
    And the site "bh-mailhog-4.test" is disabled
    When I run 'bin/ee mailhog <subcommand> bh-mailhog-4.test'
    Then the exit code should be 1
    And STDERR should contain "Site bh-mailhog-4.test does not exist / is not enabled."

    Examples:
      | subcommand |
      | enable     |
      | disable    |
      | status     |

  Scenario: Re-enabling a site brings mailhog back when it was enabled
    Given the site "bh-mailhog-4.test" exists with flags "--type=wp --env=production"
    And mailhog is enabled on site "bh-mailhog-4.test"
    When I run 'bin/ee site disable bh-mailhog-4.test'
    Then the command should succeed
    And the "mailhog" service of site "bh-mailhog-4.test" should not be running
    And the site "bh-mailhog-4.test" should have "mailhog_enabled" set to "1"
    When I run 'bin/ee site enable bh-mailhog-4.test'
    Then the command should succeed
    And STDOUT should contain "Mailhog enabled for bh-mailhog-4.test site"
    And the "mailhog" service of site "bh-mailhog-4.test" should be running
    And the file "config/postfix/main.cf" of site "bh-mailhog-4.test" should match "/^relayhost = mailhog:1025$/m"
    When I run 'bin/ee mailhog status bh-mailhog-4.test'
    Then STDOUT should be "Mailhog is UP for bh-mailhog-4.test site."

  Scenario: Re-enabling a site keeps mailhog down when it was disabled
    Given the site "bh-mailhog-4.test" exists with flags "--type=wp --env=production"
    And mailhog is disabled on site "bh-mailhog-4.test"
    When I run 'bin/ee site disable bh-mailhog-4.test'
    Then the command should succeed
    When I run 'bin/ee site enable bh-mailhog-4.test'
    Then the command should succeed
    And STDOUT should not contain "Mailhog enabled for"
    And the "mailhog" service of site "bh-mailhog-4.test" should not be running
    And the site "bh-mailhog-4.test" should have "mailhog_enabled" set to "0"
    When I run 'bin/ee mailhog status bh-mailhog-4.test'
    Then STDOUT should be "Mailhog is DOWN for bh-mailhog-4.test site."

  Scenario: ee site refresh keeps mailhog down when it was disabled
    Given the site "bh-mailhog-4.test" exists with flags "--type=wp --env=production"
    And mailhog is disabled on site "bh-mailhog-4.test"
    When I run 'bin/ee site refresh bh-mailhog-4.test'
    Then the command should succeed
    And STDOUT should not contain "Mailhog enabled for"
    And the "mailhog" service of site "bh-mailhog-4.test" should not be running
    And the site "bh-mailhog-4.test" should have "mailhog_enabled" set to "0"
    And the file "config/postfix/main.cf" of site "bh-mailhog-4.test" should not match "/^relayhost = mailhog:1025$/m"

  Scenario Outline: ee site restart does not change the mailhog state (<state>)
    Given the site "bh-mailhog-4.test" exists with flags "--type=wp --env=production"
    And mailhog is <state> on site "bh-mailhog-4.test"
    When I run 'bin/ee site restart bh-mailhog-4.test'
    Then the command should succeed
    And the "mailhog" service of site "bh-mailhog-4.test" should <running>
    And the site "bh-mailhog-4.test" should have "mailhog_enabled" set to "<flag>"

    Examples:
      | state    | running        | flag |
      | enabled  | be running     | 1    |
      | disabled | not be running | 0    |

  Scenario: Deleting a site removes its mailhog container
    Given the site "bh-mailhog-4.test" exists with flags "--type=wp --env=production"
    And mailhog is enabled on site "bh-mailhog-4.test"
    When I run 'bin/ee site delete bh-mailhog-4.test --yes'
    Then the command should succeed
    And the site "bh-mailhog-4.test" should not exist
    And the "mailhog" service of site "bh-mailhog-4.test" should not exist
    And the "postfix" service of site "bh-mailhog-4.test" should not exist
    And Following containers of site "bh-mailhog-4.test" should be removed:
      | container |
      | mailhog   |
      | postfix   |
      | nginx     |
      | php       |
    And There should be 0 containers with labels
      """
      io.easyengine.site=bh-mailhog-4.test
      """
