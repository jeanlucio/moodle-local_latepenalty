@local @local_latepenalty
Feature: Late Penalty access control
  As a site administrator
  I need the Late Penalty plugin to respect Moodle capability-based access

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | topics |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name         |
      | assign   | C1     | Assignment 1 |

  Scenario: Editing teacher sees the Late penalty section in assignment settings
    Given I log in as "teacher1"
    When I am on the "Assignment 1" "assign activity editing" page
    Then I should see "Late penalty"
    And I should see "Enable progressive penalty?"

  Scenario: Teacher sees the late penalty report link in course navigation
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    Then I should see "Late penalty report"

  Scenario: Student does not see the late penalty report link
    Given I log in as "student1"
    When I am on "Course 1" course homepage
    Then I should not see "Late penalty report"
