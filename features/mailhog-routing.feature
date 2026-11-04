Feature: Mailhog routing on multisite, alias and HTTPS sites
  The mailhog container reuses the nginx VIRTUAL_HOST value, so /ee-admin/mailhog/ follows subdomain wildcards and alias domains, and HTTPS sites serve it over TLS.
  HTTPS uses --ssl=self: no public DNS or Let's Encrypt issuance is needed for this.

  Scenario: Subdomain multisite subsites and alias domains serve /ee-admin/mailhog/
    Given the site "bh-mailhog-9.test" exists with flags "--type=wp --mu=subdom --alias-domains=bh-mailhog-9-alias.test --env=production"
    And mailhog is enabled on site "bh-mailhog-9.test"
    # The generated value lists *.bh-mailhog-9.test twice (site-type-wp adds it once for subdom and again from alias_domains), so compare the entries, not the string.
    Then the "mailhog" service of site "bh-mailhog-9.test" should have env "VIRTUAL_HOST" listing "bh-mailhog-9.test,*.bh-mailhog-9.test,bh-mailhog-9-alias.test"
    And I wait up to 30 seconds until a GET request to "http://bh-mailhog-9.test/ee-admin/mailhog/" returns 200
    And the response body should contain "MailHog"
    When I send a GET request to "http://sub1.bh-mailhog-9.test/ee-admin/mailhog/"
    Then the response code should be 200
    And the response body should contain "MailHog"
    When I send a GET request to "http://bh-mailhog-9-alias.test/ee-admin/mailhog/"
    Then the response code should be 200
    And the response body should contain "MailHog"
    When I send a GET request to "http://sub1.bh-mailhog-9.test/ee-admin/mailhog/" from the public IP
    Then the response code should be 401
    And the response header "WWW-Authenticate" should contain "Mailhog"

  Scenario: HTTPS sites redirect /ee-admin/mailhog/ to HTTPS and serve it there behind auth
    Given the site "bh-mailhog-10.test" exists with flags "--type=wp --ssl=self --env=production"
    And mailhog is enabled on site "bh-mailhog-10.test"
    Then I wait up to 30 seconds until a GET request to "https://bh-mailhog-10.test/ee-admin/mailhog/" returns 200
    And the response body should contain "MailHog"
    When I send a GET request to "http://bh-mailhog-10.test/ee-admin/mailhog/"
    Then the response code should be 301
    And the response header "Location" should contain "https://bh-mailhog-10.test/ee-admin/mailhog/"
    When I send a GET request to "https://bh-mailhog-10.test/ee-admin/mailhog/" from the public IP
    Then the response code should be 401
    When I send a GET request to "https://bh-mailhog-10.test/ee-admin/mailhog/" from the public IP with the admin-tools credentials
    Then the response code should be 200
    And the response body should contain "MailHog"
