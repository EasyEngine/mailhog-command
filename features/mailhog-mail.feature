Feature: Mail capture through mailhog
  With mailhog enabled, mail sent by WordPress (php -> msmtp -> postfix -> relayhost mailhog:1025) lands in MailHog, whose UI and API are served at /ee-admin/mailhog/ by nginx-proxy.
  Requests here go to 127.0.0.1, which the global admin-tools auth whitelists; access control is covered in mailhog-auth.feature.

  Background:
    Given the site "bh-mailhog-2.test" exists with flags "--type=wp --env=production"

  Scenario: The MailHog UI is served at /ee-admin/mailhog/ once mailhog is enabled
    Given mailhog is enabled on site "bh-mailhog-2.test"
    Then I wait up to 30 seconds until a GET request to "http://bh-mailhog-2.test/ee-admin/mailhog/" returns 200
    And the response body should contain "MailHog"

  Scenario: The MailHog API answers with JSON
    Given mailhog is enabled on site "bh-mailhog-2.test"
    Then I wait up to 30 seconds until a GET request to "http://bh-mailhog-2.test/ee-admin/mailhog/api/v2/messages" returns 200
    And the response body should be valid json
    And the response body should contain "total"

  Scenario: The site itself is still served next to /ee-admin/mailhog/
    Given mailhog is enabled on site "bh-mailhog-2.test"
    Then I wait up to 30 seconds until a GET request to "http://bh-mailhog-2.test/" returns 200
    And the response body should not contain "MailHog"

  Scenario: wp_mail() sent through ee shell is captured by MailHog
    Given mailhog is enabled on site "bh-mailhog-2.test"
    When I send a mail to "bh-rcpt@example.test" with subject "bh-mailhog-capture-1" from site "bh-mailhog-2.test" via ee shell
    Then the command should succeed
    And STDOUT should contain "true"
    And I wait up to 60 seconds until mailhog of site "bh-mailhog-2.test" has a mail with subject "bh-mailhog-capture-1"
    And the response body should contain "bh-rcpt@example.test"

  Scenario: Several mails are all captured
    Given mailhog is enabled on site "bh-mailhog-2.test"
    When I send a mail to "bh-rcpt-a@example.test" with subject "bh-mailhog-multi-a" from site "bh-mailhog-2.test" via ee shell
    Then the command should succeed
    When I send a mail to "bh-rcpt-b@example.test" with subject "bh-mailhog-multi-b" from site "bh-mailhog-2.test" via ee shell
    Then the command should succeed
    And I wait up to 60 seconds until mailhog of site "bh-mailhog-2.test" has a mail with subject "bh-mailhog-multi-a"
    And I wait up to 60 seconds until mailhog of site "bh-mailhog-2.test" has a mail with subject "bh-mailhog-multi-b"
    And the response body should contain "bh-rcpt-a@example.test"
    And the response body should contain "bh-rcpt-b@example.test"

  Scenario: After disabling mailhog, /ee-admin/mailhog/ is no longer served by MailHog
    Given mailhog is enabled on site "bh-mailhog-2.test"
    And I wait up to 30 seconds until a GET request to "http://bh-mailhog-2.test/ee-admin/mailhog/" returns 200
    When I run 'bin/ee mailhog disable bh-mailhog-2.test'
    Then the command should succeed
    # One request can hang for the 30 s curl timeout right after the container stops (seen once on PHP 7.4), so allow a few.
    And I wait up to 90 seconds until a GET request to "http://bh-mailhog-2.test/ee-admin/mailhog/" does not contain "MailHog"
    And the file "config/postfix/main.cf" of site "bh-mailhog-2.test" should match "/^relayhost =\s*$/m"

  Scenario: wp_mail() still succeeds with mailhog disabled
    Given mailhog is disabled on site "bh-mailhog-2.test"
    When I send a mail to "bh-rcpt@example.test" with subject "bh-mailhog-disabled-1" from site "bh-mailhog-2.test" via ee shell
    Then the command should succeed
    And STDOUT should contain "true"

  Scenario: Mail is captured again after mailhog is disabled and re-enabled
    Given mailhog is disabled on site "bh-mailhog-2.test"
    When I run 'bin/ee mailhog enable bh-mailhog-2.test'
    Then the command should succeed
    When I send a mail to "bh-rcpt@example.test" with subject "bh-mailhog-reenabled-1" from site "bh-mailhog-2.test" via ee shell
    Then the command should succeed
    And I wait up to 60 seconds until mailhog of site "bh-mailhog-2.test" has a mail with subject "bh-mailhog-reenabled-1"

  Scenario: ee site restart keeps mailhog up and capturing
    Given mailhog is enabled on site "bh-mailhog-2.test"
    When I run 'bin/ee site restart bh-mailhog-2.test'
    Then the command should succeed
    And the "mailhog" service of site "bh-mailhog-2.test" should be running
    When I send a mail to "bh-rcpt@example.test" with subject "bh-mailhog-restart-1" from site "bh-mailhog-2.test" via ee shell
    Then the command should succeed
    And I wait up to 60 seconds until mailhog of site "bh-mailhog-2.test" has a mail with subject "bh-mailhog-restart-1"

  Scenario: ee site refresh re-applies the mailhog relayhost after resetting postfix
    Given mailhog is enabled on site "bh-mailhog-2.test"
    When I run 'bin/ee site refresh bh-mailhog-2.test'
    Then the command should succeed
    And STDOUT should contain "Mailhog enabled for bh-mailhog-2.test site"
    And the "mailhog" service of site "bh-mailhog-2.test" should be running
    And the site "bh-mailhog-2.test" should have "mailhog_enabled" set to "1"
    And the file "config/postfix/main.cf" of site "bh-mailhog-2.test" should match "/^relayhost = mailhog:1025$/m"
    When I send a mail to "bh-rcpt@example.test" with subject "bh-mailhog-refresh-1" from site "bh-mailhog-2.test" via ee shell
    Then the command should succeed
    And I wait up to 60 seconds until mailhog of site "bh-mailhog-2.test" has a mail with subject "bh-mailhog-refresh-1"
