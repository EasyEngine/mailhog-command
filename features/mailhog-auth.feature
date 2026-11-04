Feature: Mailhog UI access control
  /ee-admin/mailhog/ is protected by the global admin-tools auth (htpasswd default_admin_tools, realm "Restricted <host> Mailhog"), which `ee mailhog enable` creates when no global auth exists.
  Requests to the host's public IP are not whitelisted, so HTTP auth applies; requests to 127.0.0.1 arrive as 10.1.0.1 and are whitelisted.

  Background:
    Given the site "bh-mailhog-3.test" exists with flags "--type=wp --env=production"

  Scenario: The global admin-tools credentials are listed after enabling mailhog
    Given mailhog is enabled on site "bh-mailhog-3.test"
    When I run 'bin/ee auth list global --format=json'
    Then the command should succeed
    And STDOUT should be valid json
    And the global admin-tools auth should exist

  Scenario: Without credentials the public IP gets 401 with the Mailhog realm
    Given mailhog is enabled on site "bh-mailhog-3.test"
    And I wait up to 30 seconds until a GET request to "http://bh-mailhog-3.test/ee-admin/mailhog/" returns 200
    When I send a GET request to "http://bh-mailhog-3.test/ee-admin/mailhog/" from the public IP
    Then the response code should be 401
    And the response header "WWW-Authenticate" should contain "Restricted bh-mailhog-3.test Mailhog"
    And the response body should not contain "MailHog"

  Scenario: Wrong credentials are rejected
    Given mailhog is enabled on site "bh-mailhog-3.test"
    And I wait up to 30 seconds until a GET request to "http://bh-mailhog-3.test/ee-admin/mailhog/" returns 200
    When I send a GET request to "http://bh-mailhog-3.test/ee-admin/mailhog/" from the public IP with credentials "easyengine" and "bh-wrong-password"
    Then the response code should be 401

  Scenario: The admin-tools credentials open the MailHog UI and API from the public IP
    Given mailhog is enabled on site "bh-mailhog-3.test"
    And I wait up to 30 seconds until a GET request to "http://bh-mailhog-3.test/ee-admin/mailhog/" returns 200
    When I send a GET request to "http://bh-mailhog-3.test/ee-admin/mailhog/" from the public IP with the admin-tools credentials
    Then the response code should be 200
    And the response body should contain "MailHog"
    When I send a GET request to "http://bh-mailhog-3.test/ee-admin/mailhog/api/v2/messages" from the public IP with the admin-tools credentials
    Then the response code should be 200
    And the response body should be valid json

  Scenario: Whitelisted local requests need no credentials
    Given mailhog is enabled on site "bh-mailhog-3.test"
    Then I wait up to 30 seconds until a GET request to "http://bh-mailhog-3.test/ee-admin/mailhog/" returns 200
    And the response body should contain "MailHog"

  Scenario: The brief's Host-header style reaches the same protected location
    Given mailhog is enabled on site "bh-mailhog-3.test"
    And I wait up to 30 seconds until a GET request to "http://bh-mailhog-3.test/ee-admin/mailhog/" returns 200
    Then a GET request to "http://{PUBLIC_IP}/ee-admin/mailhog/" with Host "bh-mailhog-3.test" should return 401
    And a GET request to "http://127.0.0.1/ee-admin/mailhog/" with Host "bh-mailhog-3.test" should return 200

  Scenario: Site HTTP auth credentials do not open MailHog
    Given mailhog is enabled on site "bh-mailhog-3.test"
    When I run 'bin/ee auth create bh-mailhog-3.test --user=bh-site-user --pass=bh-site-pass'
    Then the command should succeed
    When I send a GET request to "http://bh-mailhog-3.test/" from the public IP with credentials "bh-site-user" and "bh-site-pass"
    Then the response code should be 200
    When I send a GET request to "http://bh-mailhog-3.test/ee-admin/mailhog/" from the public IP with credentials "bh-site-user" and "bh-site-pass"
    Then the response code should be 401
    When I send a GET request to "http://bh-mailhog-3.test/ee-admin/mailhog/" from the public IP with the admin-tools credentials
    Then the response code should be 200
    And the response body should contain "MailHog"
    When I run 'bin/ee auth delete bh-mailhog-3.test'
    Then the command should succeed

  Scenario: With mailhog disabled, /ee-admin/mailhog/ stays behind auth and no longer reaches MailHog
    Given mailhog is disabled on site "bh-mailhog-3.test"
    And I wait up to 30 seconds until a GET request to "http://bh-mailhog-3.test/ee-admin/mailhog/" does not contain "MailHog"
    When I send a GET request to "http://bh-mailhog-3.test/ee-admin/mailhog/" from the public IP
    Then the response code should be 401
    When I send a GET request to "http://bh-mailhog-3.test/ee-admin/mailhog/" from the public IP with the admin-tools credentials
    Then the response body should not contain "MailHog"
