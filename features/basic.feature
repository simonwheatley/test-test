Feature: Accessing WordPress site
  As a WordPress developer
  In order to know this Travis thing is working
  I'd like to check the WordPress homepage is visible

  @javascript @insulated
  Scenario: Visiting the homepage
    Given I am on "/"
    Then I should see "Proudly powered by WordPress"
