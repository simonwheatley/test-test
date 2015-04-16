Feature: First Test
  In order to check WordPress is available
  As anonymous user
  I must be able to see content

  @javascript
  Scenario: I can see "Hello World"
    Given I am on "/"
    Then I should see "Hello World"
