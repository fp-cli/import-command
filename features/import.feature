Feature: Import content.

  Scenario: Importing requires plugin installation
    Given a FP install

    When I try `fp import file.xml --authors=create`
    Then STDERR should contain:
      """
      Error: WordPress Importer needs to be installed. Try 'fp plugin install wordpress-importer --activate'.
      """

  @require-fp-5.2 @require-mysql
  Scenario: Basic export then import
    Given a FP install
    And I run `fp site empty --yes`
    And I run `fp post generate --post_type=post --count=4`
    And I run `fp post generate --post_type=page --count=3`
    When I run `fp post list --post_type=any --format=count`
    Then STDOUT should be:
      """
      7
      """

    When I run `fp export`
    Then save STDOUT 'Writing to file %s' as {EXPORT_FILE}

    When I run `fp site empty --yes`
    Then STDOUT should not be empty

    When I run `fp post list --post_type=any --format=count`
    Then STDOUT should be:
      """
      0
      """

    When I run `fp plugin install wordpress-importer --activate`
    Then STDERR should not contain:
      """
      Warning:
      """

    When I run `fp import {EXPORT_FILE} --authors=skip`
    Then STDOUT should not be empty

    When I run `fp post list --post_type=any --format=count`
    Then STDOUT should be:
      """
      7
      """

    When I run `fp import {EXPORT_FILE} --authors=skip --skip=image_resize`
    Then STDOUT should not be empty

  @require-fp-5.2 @require-mysql
  Scenario: Export and import a directory of files
    Given a FP install
    And I run `mkdir export-posts`
    And I run `mkdir export-pages`
    And I run `fp site empty --yes`

    When I run `fp post generate --count=50`
    And I run `fp post generate --post_type=page --count=50`
    And I run `fp post list --post_type=post,page --format=count`
    Then STDOUT should be:
      """
      100
      """

    When I run `fp export --dir=export-posts --post_type=post`
    And I run `fp export --dir=export-pages --post_type=page`
    Then STDOUT should not be empty

    When I run `fp site empty --yes`
    Then STDOUT should not be empty

    When I run `fp post list --post_type=post,page --format=count`
    Then STDOUT should be:
      """
      0
      """

    When I run `find export-* -type f | wc -l`
    Then STDOUT should contain:
      """
      2
      """

    When I run `fp plugin install wordpress-importer --activate`
    Then STDERR should not contain:
      """
      Warning:
      """

    When I run `fp import export-posts --authors=skip --skip=image_resize`
    And I run `fp import export-pages --authors=skip --skip=image_resize`
    Then STDOUT should not be empty

    When I run `fp post list --post_type=post,page --format=count`
    Then STDOUT should be:
      """
      100
      """

  @require-fp-5.2 @require-mysql
  Scenario: Export and import a directory of files with .wxr and .xml extensions.
    Given a FP install
    And I run `mkdir export`
    And I run `fp site empty --yes`
    And I run `fp post generate --count=1`
    And I run `fp post generate --post_type=page --count=1`

    When I run `fp post list --post_type=post,page --format=count`
    Then STDOUT should be:
      """
      2
      """

    When I run `fp export --dir=export --post_type=post --filename_format={site}.wordpress.{date}.{n}.xml`
    Then STDOUT should not be empty
    When I run `fp export --dir=export --post_type=page --filename_format={site}.wordpress.{date}.{n}.wxr`
    Then STDOUT should not be empty

    When I run `fp site empty --yes`
    Then STDOUT should not be empty

    When I run `fp post list --post_type=post,page --format=count`
    Then STDOUT should be:
      """
      0
      """

    When I run `find export -type f | wc -l`
    Then STDOUT should contain:
      """
      2
      """

    When I run `fp plugin install wordpress-importer --activate`
    Then STDERR should be empty

    When I run `fp import export --authors=skip --skip=image_resize`
    Then STDOUT should not be empty
    And STDERR should be empty

    When I run `fp post list --post_type=post,page --format=count`
    Then STDOUT should be:
      """
      2
      """

  @require-fp-5.2 @require-mysql
  Scenario: Export and import page and referencing menu item
    Given a FP install
    And I run `fp site empty --yes`
    And I run `fp post generate --count=1`
    And I run `fp post generate --post_type=page --count=1`
    And I run `mkdir export`

    # NOTE: The Hello World page ID is 2.
    When I run `fp menu create "My Menu"`
    And I run `fp menu item add-post my-menu 2`
    And I run `fp menu item list my-menu --format=count`
    Then STDOUT should be:
      """
      1
      """

    When I run `fp export --dir=export`
    Then STDOUT should not be empty

    When I run `fp site empty --yes`
    Then STDOUT should not be empty

    When I run `fp menu create "My Menu"`
    Then STDOUT should not be empty

    When I run `fp post list --post_type=page --format=count`
    Then STDOUT should be:
      """
      0
      """

    When I run `fp post list --post_type=nav_menu_item --format=count`
    Then STDOUT should be:
      """
      0
      """

    When I run `find export -type f | wc -l`
    Then STDOUT should contain:
      """
      1
      """

    When I run `fp plugin install wordpress-importer --activate`
    Then STDERR should not contain:
      """
      Warning:
      """

    When I run `fp import export --authors=skip --skip=image_resize`
    Then STDOUT should not be empty

    When I run `fp post list --post_type=page --format=count`
    Then STDOUT should be:
      """
      1
      """

    When I run `fp post list --post_type=nav_menu_item --format=count`
    Then STDOUT should be:
      """
      1
      """

    When I run `fp menu item list my-menu --fields=object --format=csv`
    Then STDOUT should contain:
      """
      page
      """

    When I run `fp menu item list my-menu --fields=object_id --format=csv`
    Then STDOUT should contain:
      """
      2
      """

  @require-fp-5.2 @require-mysql
  Scenario: Export and import page and referencing menu item in separate files
    Given a FP install
    And I run `fp site empty --yes`
    And I run `fp post generate --count=1`
    And I run `fp post generate --post_type=page --count=1`
    And I run `mkdir export`

    # NOTE: The Hello World page ID is 2.
    When I run `fp menu create "My Menu"`
    And I run `fp menu item add-post my-menu 2`
    And I run `fp menu item list my-menu --format=count`
    Then STDOUT should be:
      """
      1
      """

    When I run `fp export --dir=export --post_type=page --filename_format=0.page.xml`
    And I run `fp export --dir=export --post_type=nav_menu_item --filename_format=1.menu.xml`
    Then STDOUT should not be empty

    When I run `fp site empty --yes`
    Then STDOUT should not be empty

    When I run `fp menu create "My Menu"`
    Then STDOUT should not be empty

    When I run `fp post list --post_type=page --format=count`
    Then STDOUT should be:
      """
      0
      """

    When I run `fp post list --post_type=nav_menu_item --format=count`
    Then STDOUT should be:
      """
      0
      """

    When I run `find export -type f | wc -l`
    Then STDOUT should contain:
      """
      2
      """

    When I run `fp plugin install wordpress-importer --activate`
    Then STDERR should not contain:
      """
      Warning:
      """

    When I run `fp import export --authors=skip --skip=image_resize`
    Then STDOUT should not be empty

    When I run `fp post list --post_type=page --format=count`
    Then STDOUT should be:
      """
      1
      """

    When I run `fp post list --post_type=nav_menu_item --format=count`
    Then STDOUT should be:
      """
      1
      """

    When I run `fp menu item list my-menu --fields=object --format=csv`
    Then STDOUT should contain:
      """
      page
      """

    When I run `fp menu item list my-menu --fields=object_id --format=csv`
    Then STDOUT should contain:
      """
      2
      """

  @require-fp-5.2 @require-mysql
  Scenario: Indicate current file when importing
    Given a FP install
    And I run `fp plugin install --activate wordpress-importer`

    When I run `fp export --filename_format=wordpress.{n}.xml`
    Then save STDOUT 'Writing to file %s' as {EXPORT_FILE}

    When I run `fp site empty --yes`
    Then STDOUT should not be empty

    When I run `fp import {EXPORT_FILE} --authors=skip`
    Then STDOUT should contain:
      """
      (in file wordpress.000.xml)
      """

  @require-fp-5.2
  Scenario: Handling of non-existing files and directories
    Given a FP install
    And I run `fp plugin install --activate wordpress-importer`
    And I run `fp export`
    And save STDOUT 'Writing to file %s' as {EXPORT_FILE}
    And an empty 'empty_test_directory' directory

    When I try `fp import non_existing_relative_file_path.xml --authors=skip`
    Then STDERR should contain:
      """
      Warning:
      """
    And the return code should be 1

    When I try `fp import non_existing_relative_file_path.xml {EXPORT_FILE} --authors=skip`
    Then STDERR should contain:
      """
      Warning:
      """
    And the return code should be 0

    When I try `fp import empty_test_directory --authors=skip`
    Then STDERR should contain:
      """
      Warning:
      """
    And the return code should be 1

    When I try `fp import empty_test_directory non_existing_relative_file_path.xml --authors=skip`
    Then STDERR should contain:
      """
      Warning:
      """
    And the return code should be 1
