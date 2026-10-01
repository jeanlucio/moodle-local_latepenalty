@local @local_latepenalty
Feature: Late Penalty discounts late work and explains its deadline
  In order to apply late penalties fairly
  As a teacher
  I need late grades discounted, the deadline in use shown, and the original grades back when I change my mind

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
      | student2 | Student   | Two      | student2@example.com |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | topics |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity | course | name  | idnumber | grade | duedate                 | assignsubmission_onlinetext_enabled | submissiondrafts |
      | assign   | C1     | Essay | essay1   | 100   | ##2 days ago +1 hour##  | 1                                   | 0                |
    And the following "local_latepenalty > rules" exist:
      | activity | enabled | daily_penalty | max_penalty |
      | essay1   | 1       | 10            | 50          |

  Scenario: A late submission is discounted in the gradebook
    Given the following "mod_assign > submissions" exist:
      | assign | user     | onlinetext |
      | Essay  | student1 | My answer  |
    And the following "grade grades" exist:
      | gradeitem | user     | grade |
      | Essay     | student1 | 100   |
    When I am on the "Course 1" "grades > User report > View" page logged in as "student1"
    Then the following should exist in the "user-grade" table:
      | Grade item | Grade |
      | Essay      | 80    |

  @javascript
  Scenario: The gradebook shows the core late penalty indicator
    Given Late Penalty stores penalties as deducted marks
    And the following "mod_assign > submissions" exist:
      | assign | user     | onlinetext |
      | Essay  | student1 | My answer  |
    And the following "grade grades" exist:
      | gradeitem | user     | grade |
      | Essay     | student1 | 100   |
    When I am on the "Course 1" "grades > Grader report > View" page logged in as "teacher1"
    Then the "data-bs-original-title" attribute of ".penalty-indicator-icon" "css_element" should contain "Late penalty applied -20.00 marks"

  Scenario: The report shows each student's deadline and where it comes from
    Given the following "mod_assign > extensions" exist:
      | assign | user     | extensionduedate |
      | Essay  | student2 | ##20 hours ago## |
    And the following "mod_assign > submissions" exist:
      | assign | user     | onlinetext  |
      | Essay  | student1 | My answer   |
      | Essay  | student2 | Your answer |
    And the following "grade grades" exist:
      | gradeitem | user     | grade |
      | Essay     | student1 | 100   |
      | Essay     | student2 | 100   |
    And I am on the "Course 1" course page logged in as teacher1
    When I follow "Late penalty report"
    Then I should see "Due date"
    And I should see "Extension"
    And I should see "80.00"
    And I should see "90.00"

  Scenario: The form shows the deadline used and offers help
    Given the following "activities" exist:
      | activity | course | name  | idnumber | duedate |
      | assign   | C1     | Draft | draft1   | 0       |
    And the following "local_latepenalty > rules" exist:
      | activity | enabled | daily_penalty | max_penalty |
      | draft1   | 1       | 10            | 50          |
    When I am on the "Essay" "assign activity editing" page logged in as teacher1
    Then I should see "Deadline used for the penalty:"
    And I should see "(Due date)"
    And "Help with Enable progressive penalty?" "icon" should exist
    And "Help with Recalculate penalties when deadline changes" "icon" should exist
    And "Help with Recalculate penalties when daily rate or maximum changes" "icon" should exist
    And I am on the "Draft" "assign activity editing" page
    And I should see "No deadline: no late penalty is applied."

  Scenario: Scale-graded activities are never discounted
    Given the following "activities" exist:
      | activity | course | name   | idnumber | grade | duedate                |
      | assign   | C1     | Poster | poster1  | -1    | ##2 days ago +1 hour## |
    And the following "local_latepenalty > rules" exist:
      | activity | enabled | daily_penalty | max_penalty |
      | poster1  | 1       | 10            | 50          |
    When I am on the "Poster" "assign activity editing" page logged in as teacher1
    Then I should see "Late Penalty only discounts numeric grades."
    And I should not see "Deadline used for the penalty:"

  Scenario: The keep-best option is offered only where the grading method is unknown
    Given the following "activities" exist:
      | activity | course | name | idnumber |
      | lti      | C1     | Tool | tool1    |
    When I am on the "Tool" "lti activity editing" page logged in as teacher1
    Then I should see "Do not let a new late attempt lower the grade"
    And I am on the "Essay" "assign activity editing" page
    And I should not see "Do not let a new late attempt lower the grade"

  @javascript
  Scenario: Disabling the rule warns first and gives the original grades back
    Given the following "mod_assign > submissions" exist:
      | assign | user     | onlinetext |
      | Essay  | student1 | My answer  |
    And the following "grade grades" exist:
      | gradeitem | user     | grade |
      | Essay     | student1 | 100   |
    And I am on the "Essay" "assign activity editing" page logged in as teacher1
    And I expand all fieldsets
    And I should not see "When you save, the grades this rule discounted go back to their original value."
    When I set the field "Enable progressive penalty?" to "0"
    Then I should see "When you save, the grades this rule discounted go back to their original value."
    And I press "Save and return to course"
    And I am on the "Course 1" "grades > User report > View" page logged in as "student1"
    And the following should exist in the "user-grade" table:
      | Grade item | Grade |
      | Essay      | 100   |

  Scenario: Removing the due date gives the original grades back
    Given the following "mod_assign > submissions" exist:
      | assign | user     | onlinetext |
      | Essay  | student1 | My answer  |
    And the following "grade grades" exist:
      | gradeitem | user     | grade |
      | Essay     | student1 | 100   |
    And I am on the "Essay" "assign activity editing" page logged in as teacher1
    And I set the field "duedate[enabled]" to "0"
    And I press "Save and return to course"
    When I am on the "Course 1" "grades > User report > View" page logged in as "student1"
    Then the following should exist in the "user-grade" table:
      | Grade item | Grade |
      | Essay      | 100   |

  @javascript
  Scenario: With the core assignment penalty on, Late Penalty steps aside
    Given the core assignment penalty is enabled for Late Penalty
    And the following "activities" exist:
      | activity | course | name    | idnumber | grade | duedate                | gradepenalty |
      | assign   | C1     | Project | project1 | 100   | ##2 days ago +1 hour## | 1            |
    And the following "local_latepenalty > rules" exist:
      | activity | enabled | daily_penalty | max_penalty |
      | project1 | 1       | 10            | 50          |
    When I am on the "Project" "assign activity editing" page logged in as teacher1
    And I expand all fieldsets
    Then I should see "Late Penalty does not act on it."
    And the "Enable progressive penalty?" "field" should be disabled

  Scenario: A quiz due date is used as the deadline
    Given the quiz due date is available to Late Penalty
    And the following "question categories" exist:
      | contextlevel | reference | name           |
      | Course       | C1        | Test questions |
    And the following "questions" exist:
      | questioncategory | qtype     | name | questiontext   |
      | Test questions   | truefalse | TF1  | First question |
    And the following "activities" exist:
      | activity | course | name   | idnumber | grade | duedate                |
      | quiz     | C1     | Quiz 1 | quiz1    | 100   | ##2 days ago +1 hour## |
    And quiz "Quiz 1" contains the following questions:
      | question | page |
      | TF1      | 1    |
    And the following "local_latepenalty > rules" exist:
      | activity | enabled | daily_penalty | max_penalty |
      | quiz1    | 1       | 10            | 50          |
    And user "student1" has attempted "Quiz 1" with responses:
      | slot | response |
      | 1    | True     |
    When I am on the "Course 1" "grades > User report > View" page logged in as "student1"
    Then the following should exist in the "user-grade" table:
      | Grade item | Grade |
      | Quiz 1     | 80    |
