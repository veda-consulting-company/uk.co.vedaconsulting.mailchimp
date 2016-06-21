<?php
$classes_root =  implode(DIRECTORY_SEPARATOR,[dirname(dirname(__DIR__)), 'CRM', 'Mailchimp', '']);
require $classes_root . 'Utils.php';

class UtilsTest extends \PHPUnit_Framework_TestCase {

  /**
   * Tests CRM_Mailchimp_Utils::splitGroupTitles.
   */
  public function testGroupTitleSplitting() {
    $groups = [
      1 => ['civigroup_title' => 'sponsored walk'],
      2 => ['civigroup_title' => 'sponsored walk, 2015'],
      3 => ['civigroup_title' => 'Never used'],
      ];

    $tests = [
      // Basics:
      'aye,sponsored walk' => [1],
      'aye,sponsored walk,bee' => [1],
      'sponsored walk,bee' => [1],
      'sponsored walk,sponsored walk, 2015' => [1,2],
      // Check that it's substring-safe - this should only match group 1
      'sponsored walk' => [1],
      // Check both work.
      // This test checks the algorithm for looking for long group titles first.
      // If we didn't do this then this test would return both groups, or the
      // shorter group.
      'sponsored walk, 2015' => [2],
      ];
    foreach ($tests as $input => $expected) {
      $result = CRM_Mailchimp_Utils::splitGroupTitles($input, $groups);
      sort($result);
      $this->assertEquals($expected, $result, "Test case '$input' failed");
    }
  }

}
