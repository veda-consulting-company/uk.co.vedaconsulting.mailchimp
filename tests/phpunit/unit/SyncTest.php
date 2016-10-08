<?php
/**
 * Test CRM_Mailchimp_Sync.
 */

class SyncTest extends \PHPUnit_Framework_TestCase {

  /**
   *
   */
  public function testUpdateMailchimpFromCiviLogic() {
    $cases = [
      // Test email changes.
      [
        'label' => 'Changed email should be sent.',
        'merge_fields' => ['FNAME', 'LNAME'],
        'civi' =>      ['first_name'=>'x', 'last_name'=>'y', 'email' => 'new@example.com', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'expected' =>  [ 'email_address' => 'new@example.com' ],
      ],
      [
        'label' => 'Changed email cAsE ignored.',
        'merge_fields' => ['FNAME', 'LNAME'],
        'civi' =>      ['first_name'=>'x', 'last_name'=>'y', 'email' => 'DesparatelyUnique5321@example.com', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'desparatelyunique5321@example.com', 'interests' => ''],
        'expected' =>  [],
      ],

      [
        'label' => 'Test no changes (although this case should never actually be used.)',
        'merge_fields' => ['FNAME', 'LNAME'],
        'civi' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'expected' => [],
      ],
      // First names...
      [
        'label' => 'Test change first name',
        'merge_fields' => ['FNAME', 'LNAME'],
        'civi' => ['first_name'=>'New', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'expected' => ['merge_fields' => ['FNAME' => 'New']],
      ],
      [
        'label' => 'Test provide first name',
        'merge_fields' => ['FNAME', 'LNAME'],
        'civi' => ['first_name'=>'Provided', 'last_name'=>'x', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'', 'last_name'=>'x', 'email' => 'z', 'interests' => ''],
        'expected' => ['merge_fields' => ['FNAME' => 'Provided']],
      ],
      [
        'label' => 'Test noclobber first name',
        'merge_fields' => ['FNAME', 'LNAME'],
        'civi' => ['first_name'=>'', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'expected' => [],
      ],
      // Same for last name...
      [
        'label' => 'Test change last name',
        'merge_fields' => ['FNAME', 'LNAME'],
        'civi' => ['first_name'=>'x', 'last_name'=>'New', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'expected' => ['merge_fields' => ['LNAME' => 'New']],
      ],
      [
        'label' => 'Test provide last name',
        'merge_fields' => ['FNAME', 'LNAME'],
        'civi' => ['first_name'=>'x', 'last_name'=>'Provided', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'', 'email' => 'z', 'interests' => ''],
        'expected' => ['merge_fields' => ['LNAME' => 'Provided']],
      ],
      [
        'label' => 'Test noclobber last name',
        'merge_fields' => ['FNAME', 'LNAME'],
        'civi' => ['first_name'=>'x', 'last_name'=>'', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'expected' => [],
      ],
      // Checks for lists using NAME instead of FNAME, LNAME
      [
        'label' => 'NAME merge field only: Test no changes (although this case should never actually be used.)',
        'merge_fields' => ['NAME'],
        'civi' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'expected' => [],
      ],
      // First names...
      [
        'label' => 'NAME merge field only: Test change first name',
        'merge_fields' => ['NAME'],
        'civi' => ['first_name'=>'New', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'expected' => ['merge_fields' => ['NAME' => 'New y']],
      ],
      [
        'label' => 'NAME merge field only: Test provide first name',
        'merge_fields' => ['NAME'],
        'civi' => ['first_name'=>'Provided', 'last_name'=>'x', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'', 'last_name'=>'x', 'email' => 'z', 'interests' => ''],
        'expected' => ['merge_fields' => ['NAME' => 'Provided x']],
      ],
      [
        'label' => 'NAME merge field only: Test noclobber first name',
        'merge_fields' => ['NAME'],
        'civi' => ['first_name'=>'', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'expected' => [],
      ],
      // Same for last name...
      [
        'label' => 'NAME merge field only: Test change last name',
        'merge_fields' => ['NAME'],
        'civi' => ['first_name'=>'x', 'last_name'=>'New', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'expected' => ['merge_fields' => ['NAME' => 'x New']],
      ],
      [
        'label' => 'NAME merge field only: Test provide last name',
        'merge_fields' => ['NAME'],
        'civi' => ['first_name'=>'x', 'last_name'=>'Provided', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'', 'email' => 'z', 'interests' => ''],
        'expected' => ['merge_fields' => ['NAME' => 'x Provided']],
      ],
      [
        'label' => 'NAME merge field only: Test noclobber last name',
        'merge_fields' => ['NAME'],
        'civi' => ['first_name'=>'x', 'last_name'=>'', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'expected' => [],
      ],
      // Check trim() is used.
      [
        'label' => 'NAME merge fields: Test does not add spaces if first name missing.',
        'merge_fields' => ['NAME'],
        'civi' => ['first_name'=>'', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'', 'last_name'=>'', 'email' => 'z', 'interests' => ''],
        'expected' => ['merge_fields' => ['NAME' => 'y']],
      ],
      [
        'label' => 'NAME merge fields: Test does not add spaces if last name missing.',
        'merge_fields' => ['NAME'],
        'civi' => ['first_name'=>'x', 'last_name'=>'', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'', 'last_name'=>'', 'email' => 'z', 'interests' => ''],
        'expected' => ['merge_fields' => ['NAME' => 'x']],
      ],
      [
        'label' => 'NAME merge fields: Test does not update name to nothing.',
        'merge_fields' => ['NAME'],
        'civi' => ['first_name'=>'', 'last_name'=>'', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'', 'last_name'=>'', 'email' => 'z', 'interests' => ''],
        'expected' => [],
      ],
      // Checks for lists using NAME as well as FNAME, LNAME
      [
        'label' => 'NAME, FNAME, LNAME merge fields: Test no changes (although this case should never actually be used.)',
        'merge_fields' => ['NAME', 'FNAME', 'LNAME'],
        'civi' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'expected' => [],
      ],
      // First names...
      [
        'label' => 'NAME, FNAME, LNAME merge fields: Test change first name',
        'merge_fields' => ['NAME', 'FNAME', 'LNAME'],
        'civi' => ['first_name'=>'New', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'expected' => ['merge_fields' => ['NAME' => 'New y', 'FNAME' => 'New']],
      ],
      [
        'label' => 'NAME, FNAME, LNAME merge fields: Test provide first name',
        'merge_fields' => ['NAME', 'FNAME', 'LNAME'],
        'civi' => ['first_name'=>'Provided', 'last_name'=>'x', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'', 'last_name'=>'x', 'email' => 'z', 'interests' => ''],
        'expected' => ['merge_fields' => ['NAME' => 'Provided x', 'FNAME' => 'Provided']],
      ],
      [
        'label' => 'NAME, FNAME, LNAME merge fields: Test noclobber first name',
        'merge_fields' => ['NAME', 'FNAME', 'LNAME'],
        'civi' => ['first_name'=>'', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'expected' => [],
      ],
      // Same for last name...
      [
        'label' => 'NAME, FNAME, LNAME merge fields: Test change last name',
        'merge_fields' => ['NAME', 'FNAME', 'LNAME'],
        'civi' => ['first_name'=>'x', 'last_name'=>'New', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'expected' => ['merge_fields' => ['NAME' => 'x New', 'LNAME' => 'New']],
      ],
      [
        'label' => 'NAME, FNAME, LNAME merge fields: Test provide last name',
        'merge_fields' => ['NAME', 'FNAME', 'LNAME'],
        'civi' => ['first_name'=>'x', 'last_name'=>'Provided', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'', 'email' => 'z', 'interests' => ''],
        'expected' => ['merge_fields' => ['NAME' => 'x Provided', 'LNAME' => 'Provided']],
      ],
      [
        'label' => 'NAME, FNAME, LNAME merge fields: Test noclobber last name',
        'merge_fields' => ['NAME', 'FNAME', 'LNAME'],
        'civi' => ['first_name'=>'x', 'last_name'=>'', 'email' => 'z', 'interests' => ''],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'expected' => [],
      ],
      // Interests
      [
        'label' => 'Test Interest changes for adding new person with no interests.',
        'merge_fields' => ['FNAME', 'LNAME'],
        'civi' => ['first_name'=>'x', 'last_name'=>'', 'email' => 'z', 'interests' => 'a:0:{}'],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'expected' => [],
      ],
      [
        'label' => 'Test Interest changes for adding new person with interests.',
        'merge_fields' => ['FNAME', 'LNAME'],
        'civi' => ['first_name'=>'x', 'last_name'=>'', 'email' => 'z', 'interests' => 'a:1:{s:10:"aabbccddee";b:1;}'],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => ''],
        'expected' => ['interests' => ['aabbccddee'=>TRUE]],
      ],
      [
        'label' => 'Test Interest changes for existing person with same interests.', 
        'merge_fields' => ['FNAME', 'LNAME'],
        'civi' => ['first_name'=>'x', 'last_name'=>'', 'email' => 'z', 'interests' => 'a:1:{s:10:"aabbccddee";b:1;}'],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => 'a:1:{s:10:"aabbccddee";b:1;}'],
        'expected' => [],
      ],
      [
        'label' => 'Test Interest changes for existing person with different interests.', 
        'merge_fields' => ['FNAME', 'LNAME'],
        'civi' => ['first_name'=>'x', 'last_name'=>'', 'email' => 'z', 'interests' => 'a:1:{s:10:"aabbccddee";b:1;}'],
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y', 'email' => 'z', 'interests' => 'a:1:{s:10:"aabbccddee";b:0;}'],
        'expected' => ['interests' => ['aabbccddee'=>TRUE]],
      ],
    ];

    foreach ($cases as $case) {
      extract($case);
      $merge_fields = array_flip($merge_fields);
      $result = CRM_Mailchimp_Sync::updateMailchimpFromCiviLogic($merge_fields, $civi, $mailchimp);
      $this->assertEquals($expected, $result, "FAILED: $label");
    }
  }
  /**
   *
   */
  public function testUpdateCiviFromMailchimpContactLogic() {
    $cases = [
      [
        'label'     => 'Test no changes',
        'mailchimp' => ['first_name'=>'x', 'last_name'=>'y'],
        'civi'      => ['first_name'=>'x', 'last_name'=>'y'],
        'expected' => [],
      ],
      // First names...
      [
        'label'     => 'Test first name changes',
        'mailchimp' => ['first_name'=>'a', 'last_name'=>'y'],
        'civi'      => ['first_name'=>'x', 'last_name'=>'y'],
        'expected'  => ['first_name'=>'a'],
      ],
      [
        'label'     => 'Test first name provide',
        'mailchimp' => ['first_name'=>'a', 'last_name'=>'y'],
        'civi'      => ['first_name'=>'',  'last_name'=>'y'],
        'expected'  => ['first_name'=>'a'],
      ],
      [
        'label'     => 'Test first name no clobber',
        'mailchimp' => ['first_name'=>'', 'last_name'=>'y'],
        'civi'      => ['first_name'=>'x',  'last_name'=>'y'],
        'expected'  => [],
      ],
      // Last names..
      [
        'label'     => 'Test last name changes',
        'mailchimp' => ['last_name'=>'a', 'first_name'=>'y'],
        'civi'      => ['last_name'=>'x', 'first_name'=>'y'],
        'expected'  => ['last_name'=>'a'],
      ],
      [
        'label'     => 'Test last name provide',
        'mailchimp' => ['last_name'=>'a', 'first_name'=>'y'],
        'civi'      => ['last_name'=>'',  'first_name'=>'y'],
        'expected'  => ['last_name'=>'a'],
      ],
      [
        'label'     => 'Test last name no clobber',
        'mailchimp' => ['last_name'=>'', 'first_name'=>'y'],
        'civi'      => ['last_name'=>'x',  'first_name'=>'y'],
        'expected'  => [],
      ],
    ];

    foreach ($cases as $case) {
      extract($case);
      $result = CRM_Mailchimp_Sync::updateCiviFromMailchimpContactLogic($mailchimp, $civi);
      $this->assertEquals($expected, $result, "FAILED: $label");
    }
  }
}
