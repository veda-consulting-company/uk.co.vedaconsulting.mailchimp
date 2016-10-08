<?php
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

  /**
   * Tests CRM_Mailchimp_Utils::splitGroupTitlesFromMailchimp.
   *
   * Similar data as above except that:
   *
   * - it's the Mailchimp `interest_name` that's used for comparison.
   *
   * - titles from the Mailchimp API are comma-space separated and commas in
   *   names are escaped with `\`
   *
   */
  public function testGroupTitleSplittingFromMailchimp() {
    $groups = [
      1 => ['is_mc_update_grouping' => 1, 'interest_name' => 'sponsored walk'],
      2 => ['is_mc_update_grouping' => 1, 'interest_name' => 'sponsored walk, 2015'],
      3 => ['is_mc_update_grouping' => 1, 'interest_name' => 'Never used'],
      3 => ['is_mc_update_grouping' => 0, 'interest_name' => 'Invalid - not is_mc_update_grouping'],
      ];

    $tests = [
      // Basics:
      'aye, sponsored walk' => [1],
      'aye, sponsored walk, bee' => [1],
      'sponsored walk, bee' => [1],
      'sponsored walk, sponsored walk\\, 2015' => [1,2],
      // Check that it's substring-safe - this should only match group 1
      'sponsored walk' => [1],
      // Check both work.
      // This test checks the algorithm for looking for long group titles first.
      // If we didn't do this then this test would return both groups, or the
      // shorter group.
      'sponsored walk\\, 2015' => [2],
      // Check it's filtering out is_mc_update_grouping == 0
      'Invalid - not is_mc_update_grouping' => [],
      ];
    foreach ($tests as $input => $expected) {
      $result = CRM_Mailchimp_Utils::splitGroupTitlesFromMailchimp($input, $groups);
      sort($result);
      $this->assertEquals($expected, $result, "Test case '$input' failed");
    }
  }

}
