@mod @mod_lightboxgallery
Feature: Add a lightboxgallery
  In order to let users view a gallery
  As a teacher
  I need to add a lightboxgallery and upload images

  @_file_upload @javascript
  Scenario: Add a lightboxgallery and add images to it
    Given the following "users" exist:
      | username | firstname | lastname | email |
      | teacher1 | Teacher | 1 | teacher1@example.com |
      | student1 | Student | 1 | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1 | 0 |
    And the following "course enrolments" exist:
      | user | course | role |
      | teacher1 | C1 | editingteacher |
      | student1 | C1 | student |
    And the following "activities" exist:
      | activity        | name | course | intro        | idnumber | ispublic |
      | lightboxgallery | LBG  | C1     | Test gallery | lbg1     | 0        |
    When I log in as "teacher1"
    And I am on the "LBG" "lightboxgallery activity" page
    Then I should see "Test gallery"
    And I follow "Add images"
    And I upload "mod/lightboxgallery/tests/behat/fixtures/mkmap.png" file to "File" filemanager
    And I click on "Add images" "button" in the "#fgroup_id_buttonar" "css_element"
    And I log in as "student1"
    And I am on the "LBG" "lightboxgallery activity" page
    And I should see "Test gallery"
    And I should see "mkmap.png"
    And I follow "mkmap.png"
    Then I should see "mkmap.png"
    And "//img[contains(@src, 'mkmap.png')]" "xpath_element" should exist

  @_file_upload @javascript
  Scenario: Add a caption to a lightboxgallery image
    Given the following "users" exist:
      | username | firstname | lastname | email |
      | teacher1 | Teacher | 1 | teacher1@example.com |
      | student1 | Student | 1 | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1 | 0 |
    And the following "course enrolments" exist:
      | user | course | role |
      | teacher1 | C1 | editingteacher |
      | student1 | C1 | student |
    And the following "activities" exist:
      | activity        | name | course | intro        | idnumber | ispublic |
      | lightboxgallery | LBG  | C1     | Test gallery | lbg1     | 0        |
    And the "multilang" filter is "on"
    And the "multilang" filter applies to "content and headings"
    When I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    And I am on the "LBG" "lightboxgallery activity" page
    Then I should see "Test gallery"
    And I follow "Add images"
    And I upload "mod/lightboxgallery/tests/behat/fixtures/mkmap.png" file to "File" filemanager
    And I click on "Add images" "button" in the "#fgroup_id_buttonar" "css_element"
    And I set the field "tab" to "Caption"
    And I should see "mkmap.png"
    And I set the field with xpath "//textarea" to "<span class=\"multilang\" lang=\"de\">Fußzeile</span><span class=\"multilang\" lang=\"en\">Caption</span>"
    And I press "Update"
    And I am on the "LBG" "lightboxgallery activity" page
    And I turn editing mode on
    And I should see "Caption"
    But I should not see "Fußzeile"
    And I log in as "student1"
    And I am on the "LBG" "lightboxgallery activity" page
    And I should see "Test gallery"
    And I should see "Caption"
    But I should not see "Fußzeile"
    And I follow "Caption"
    And I should see "Caption"
    But I should not see "Fußzeile"
    And "//img[contains(@src, 'mkmap.png')]" "xpath_element" should exist
