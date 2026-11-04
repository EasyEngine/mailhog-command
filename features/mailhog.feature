Feature: Mailhog command surface and argument validation
  `ee mailhog` manages the per-site MailHog container of WordPress sites.
  These scenarios need no site: they fail before any container is touched.

  Scenario: ee mailhog without a subcommand lists its subcommands
    When I run 'bin/ee mailhog'
    Then the command should succeed
    And STDOUT should contain "usage: ee mailhog disable [<site-name>]"
    And STDOUT should contain "or: ee mailhog enable [<site-name>] [--force]"
    And STDOUT should contain "or: ee mailhog status [<site-name>]"

  Scenario Outline: ee help mailhog <subcommand> documents its synopsis
    When I run 'bin/ee help mailhog <subcommand>'
    Then the command should succeed
    And STDOUT should contain "<synopsis>"
    And STDOUT should contain "<description>"

    Examples:
      | subcommand | synopsis                                  | description                           |
      | enable     | ee mailhog enable [<site-name>] [--force] | Force enabling of mailhog for a site. |
      | disable    | ee mailhog disable [<site-name>]          | Disables mailhog on given site.       |
      | status     | ee mailhog status [<site-name>]           | Outputs status of mailhog for a site. |

  Scenario: An unknown mailhog subcommand is rejected
    When I run 'bin/ee mailhog restart'
    Then the command should fail
    And STDERR should contain "'restart' is not a registered subcommand of 'mailhog'"

  Scenario Outline: ee mailhog <subcommand> without a site name outside any site directory fails
    When I run 'bin/ee mailhog <subcommand>'
    Then the exit code should be 1
    And STDERR should contain "Could not find the site you wish to run mailhog <subcommand> command on."
    And STDERR should contain "`ee mailhog <subcommand> <site-name>`"

    Examples:
      | subcommand |
      | enable     |
      | disable    |
      | status     |

  # For enable this also runs init_global_admin_tools_auth() first (before the site checks), which creates the global admin-tools auth on a machine that has none.
  Scenario Outline: ee mailhog <subcommand> on an unknown site fails
    When I run 'bin/ee mailhog <subcommand> bh-mailhog-unknown.test'
    Then the exit code should be 1
    And STDERR should contain "Could not find the site you wish to run mailhog <subcommand> command on."

    Examples:
      | subcommand |
      | enable     |
      | disable    |
      | status     |

  Scenario Outline: ee mailhog <subcommand> rejects the unknown flag <flag>
    When I run 'bin/ee mailhog <subcommand> bh-mailhog-unknown.test <flag>'
    Then the exit code should be 1
    And STDERR should contain "Parameter errors:"
    And STDERR should contain "unknown <flag> parameter"

    Examples:
      | subcommand | flag    |
      | enable     | --foo   |
      | disable    | --force |
      | status     | --force |

  Scenario Outline: ee mailhog <subcommand> takes a single site name
    When I run 'bin/ee mailhog <subcommand> bh-mailhog-a.test bh-mailhog-b.test'
    Then the exit code should be 1
    And STDERR should contain "Too many positional arguments: bh-mailhog-b.test"

    Examples:
      | subcommand |
      | enable     |
      | disable    |
      | status     |
