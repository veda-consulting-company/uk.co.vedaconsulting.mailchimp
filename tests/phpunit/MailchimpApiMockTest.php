<?php

use Civi\Test\HeadlessInterface;
use \Prophecy\Argument;

/**
 * These tests run with a mocked Mailchimp API.
 *
 * They test that expected calls are made (or not made) based on changes in
 * CiviCRM.
 *
 * It does not depend on a live Mailchimp account. However it is not a unit test
 * because it does depend on and make changes to the CiviCRM database.
 *
 * It is useful to mock the Maichimp API because
 * - It removes a dependency, so test results are more predictable.
 * - It is much faster to run
 * - It can be run without a Mailchimp account/api_key, and makes no changes to
 *   a mailchimp account, so could be seen as safer.
 *
 * @group headless
 */
class MailchimpApiMockTest extends CRM_Mailchimp_IntegrationTestBase implements HeadlessInterface {

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }


  /**
   * If set false then the test method ended cleanly, which saves some teardown/setup
   * It is set to in setUp, so can only get set false by a successful test
   * that leaves the fixture in the same state as it was at the start.
   */
  public static $fixture_should_be_reset = TRUE;

  /**
   * Dummy mailchimp fixture never changes.
   */
  public static function setUpBeforeClass() {
    static::$test_list_id = 'dummylistid';
    static::$test_interest_category_id = 'categoryid';
    static::$test_interest_id_1 = 'interestId1';
    static::$test_interest_id_2 = 'interestId2';
  }
  /**
   * Create fixture in CiviCRM.
   */
  public function setUp() {
    if (static::$fixture_should_be_reset) {
      static::createCiviCrmFixtures();
    }
    static::$fixture_should_be_reset = TRUE;
    // Ensure this is at its default state.
    CRM_Mailchimp_Utils::$post_hook_enabled = TRUE;
  }
  /**
   * Remove the test list, if one was successfully set up.
   */
  public static function tearDownAfterClass() {
    static::tearDownCiviCrmFixtures();
    // Reset the API.
    CRM_Mailchimp_Utils::resetAllCaches();
  }

  /**
   * Reset the fixture's contact and group records.
   */
  public function tearDown() {
    if (static::$fixture_should_be_reset) {
      static::tearDownCiviCrmFixtureContacts();
    }
  }

  /**
   * Checks the right calls are made by the getMCInterestGroupings.
   *
   * This is a dependency of some other tests because it also caches the result,
   * which means that we don't have to duplicate prophecies for this behaviour
   * in other tests.
   *
   * @group interests
   */
  public function testGetMCInterestGroupings() {

    // Get Mock API.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());

    // Creating a sync object requires some calls to Mailchimp's API to find out
    // details about the list and interest groupings. These are cached during
    // runtime.

    $api_prophecy->get("/lists/dummylistid/interest-categories", Argument::any())
      ->shouldBeCalled()
      ->willReturn(json_decode('{"http_code":200,"data":{"categories":[{"id":"categoryid","title":"'. static::MC_INTEREST_CATEGORY_TITLE . '"}]}}'));

    $api_prophecy->get("/lists/dummylistid/interest-categories/categoryid/interests", Argument::any())
      ->shouldBeCalled()
      ->willReturn(json_decode('{"http_code":200,"data":{"interests":[{"id":"interestId1","name":"' . static::MC_INTEREST_NAME_1 . '"},{"id":"interestId2","name":"' . static::MC_INTEREST_NAME_2 . '"}]}}'));

    $interests = CRM_Mailchimp_Utils::getMCInterestGroupings('dummylistid');
    $this->assertEquals([ 'categoryid' => [
      'id' => 'categoryid',
      'name' => static::MC_INTEREST_CATEGORY_TITLE,
      'interests' => [
        'interestId1' => [ 'id' => 'interestId1', 'name' => static::MC_INTEREST_NAME_1 ],
        'interestId2' => [ 'id' => 'interestId2', 'name' => static::MC_INTEREST_NAME_2 ],
      ],
    ]], $interests);

    // Also ensure we have this in cache:
    $api_prophecy->get("/lists", Argument::any())
      ->shouldBeCalled()
      ->willReturn(json_decode('{"http_code":200,"data":{"lists":[{"id":"dummylistid","name":"'. static::MC_TEST_LIST_NAME . '"}]}}'));
    CRM_Mailchimp_Utils::getMCListName('dummylistid');
  }

  /**
   * Tests the mapping of CiviCRM group memberships to an array of Mailchimp
   * interest Ids => Bool.
   *
   * @depends testGetMCInterestGroupings
   * @group interests
   */
  public function testGetComparableInterestsFromCiviCrmGroups() {

    $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
    $g = static::C_TEST_MEMBERSHIP_GROUP_NAME;
    $i = static::C_TEST_INTEREST_GROUP_NAME_1;
    $j = static::C_TEST_INTEREST_GROUP_NAME_2;
    $cases = [
      // In both membership and interest1
      "$g,$i" => ['interestId1'=>TRUE,'interestId2'=>FALSE],
      // Just in membership group.
      "$g" => ['interestId1'=>FALSE,'interestId2'=>FALSE],
      // In interest1 only.
      "$i" => ['interestId1'=>TRUE,'interestId2'=>FALSE],
      // In lots!
      "$j,other list name,$g,$i,and another" => ['interestId1'=>TRUE,'interestId2'=>TRUE],
      // In both and other non MC groups.
      "other list name,$g,$i,and another" => ['interestId1'=>TRUE,'interestId2'=>FALSE],
      // In none, just other non MC groups.
      "other list name,and another" => ['interestId1'=> FALSE,'interestId2'=>FALSE],
      // In no groups.
      "" => ['interestId1'=> FALSE,'interestId2'=>FALSE],
      ];
    foreach ($cases as $input=>$expected) {
      $ints = $sync->getComparableInterestsFromCiviCrmGroups($input, 'push');
      $this->assertEquals($expected, $ints, "mapping failed for test '$input'");
    }

    // We didn't change the fixture.
    static::$fixture_should_be_reset = FALSE;
  }

  /**
   * Tests the mapping of CiviCRM group memberships to an array of Mailchimp
   * interest Ids => Bool.
   *
   * @depends testGetMCInterestGroupings
   * @group interests
   */
  public function testGetComparableInterestsFromMailchimp() {

    $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
    $cases = [
      // 'Normal' tests
      [ (object) ['interestId1' => TRUE, 'interestId2'=>TRUE], ['interestId1'=>TRUE, 'interestId2'=>TRUE]],
      [ (object) ['interestId1' => FALSE, 'interestId2'=>TRUE], ['interestId1'=>FALSE, 'interestId2'=>TRUE]],
      // Test that if Mailchimp omits an interest grouping we've mapped it's
      // considered false. This wil be the case if someone deletes an interest
      // on Mailchimp but not the mapped group in Civi.
      [ (object) ['interestId1' => TRUE], ['interestId1'=>TRUE, 'interestId2'=>FALSE]],
      // Test that non-mapped interests are ignored.
      [ (object) ['interestId1' => TRUE, 'foo' => TRUE], ['interestId1'=>TRUE, 'interestId2'=>FALSE]],
      ];
    foreach ($cases as $i=>$_) {
      list($input, $expected) = $_;
      $ints = $sync->getComparableInterestsFromMailchimp($input, 'push');
      $this->assertEquals($expected, $ints, "mapping failed for test '$i'");
    }

    // We didn't change the fixture.
    static::$fixture_should_be_reset = FALSE;
  }

  /**
   * Tests the mapping of group and interest names as posted by Mailchimp
   * Webhook to CiviCRM Group IDs.
   *
   * CiviCRM groups via ... provides Foo,Bar,Baz
   * Mailchimp Webhook POST provides Foo, Bar, Baz
   *
   * @depends testGetMCInterestGroupings
   * @group interests
   */
  public function testSplitMailchimpWebhookGroupsToCiviGroupIds() {
    $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
    $i = static::MC_INTEREST_NAME_1;
    $j = static::MC_INTEREST_NAME_2;

    $cases = [
      // Interest 1 only.
      "$i" => [static::$civicrm_group_id_interest_1],
      // Interest 1 and 2 only.
      "$i, $j" => [static::$civicrm_group_id_interest_1, static::$civicrm_group_id_interest_2],
      // Many interests!
      "$j, another interest, $i, and another" => [static::$civicrm_group_id_interest_1, static::$civicrm_group_id_interest_2],
      // Reversed order and other interests.
      "other list name, $j, $i, and another" => [static::$civicrm_group_id_interest_1, static::$civicrm_group_id_interest_2],
      // No relevant local interests, just other non MC groups.
      "other list name,and another" => [],
      // In no groups.
      "" => [],
    ];
    foreach ($cases as $input=>$expected) {
      $ints = $sync->SplitMailchimpWebhookGroupsToCiviGroupIds($input);
      $this->assertEquals($expected, $ints, "mapping failed for test '$input'");
    }

    // We didn't change the fixture.
    static::$fixture_should_be_reset = FALSE;
  }

  /**
   * Checks that we are unable to instantiate a CRM_Mailchimp_Sync object with
   * an invalid List.
   *
   * @expectedException InvalidArgumentException
   * @expectedExceptionMessage Failed to find mapped membership group for list 'invalidlistid'
   * @depends testGetMCInterestGroupings
   */
  public function testSyncMustHaveMembershipGroup() {

    // Get Mock API.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());

    // We're not goint to affect the fixture.
    static::$fixture_should_be_reset = FALSE;

    $sync = new CRM_Mailchimp_Sync("invalidlistid");

  }
  /**
   * Check the right calls are made to the Mailchimp API.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testPostHookForMembershipListChanges() {

    // Get Mock API.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());

    // handy copy.
    $subscriber_hash = static::$civicrm_contact_1['subscriber_hash'];

    //
    // Test:
    //
    // If someone is added to the CiviCRM group, then we should expect them to
    // get subscribed.

    // Prepare the mock for the updateMailchimpFromCiviSingleContact
    // We expect that a PUT request is sent to Mailchimp.
    $api_prophecy->put("/lists/dummylistid/members/$subscriber_hash",
      Argument::that(function($_){
        return $_['status'] == 'subscribed'
          && $_['interests']['interestId1'] === FALSE
          && $_['interests']['interestId2'] === FALSE
          && count($_['interests']) == 2;
      }))
      ->shouldBeCalled();

    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_membership,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Added",
    ]);


    //
    // Test:
    //
    // If someone is removed or deleted from the CiviCRM group they should get
    // removed from Mailchimp.

    // Prepare the mock for the updateMailchimpFromCiviSingleContact - this should get called
    // twice.
    $api_prophecy->patch("/lists/dummylistid/members/$subscriber_hash", ['status' => 'unsubscribed'])
      ->shouldbecalledTimes(2);

    // Test 'removed':
    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_membership,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Removed",
    ]);

    // Test 'deleted':
    $result = civicrm_api3('GroupContact', 'delete', [
      'group_id' => static::$civicrm_group_id_membership,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
    ]);


    // If we got here OK, then the fixture is unchanged.
    static::$fixture_should_be_reset = FALSE;

  }
  /**
   * Check the right calls are made to the Mailchimp API as result of
   * adding/removing/deleting someone from an group linked to an interest
   * grouping.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testPostHookForInterestGroupChanges() {

    // Get Mock API.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());

    $subscriber_hash = static::$civicrm_contact_1['subscriber_hash'];

    //
    // Test:
    //
    // Because this person is NOT on the membership list, nothing we do to their
    // interest group membership should result in a Mailchimp update.
    //
    // Prepare the mock for the updateMailchimpFromCiviSingleContact
    $api_prophecy->put("/lists/dummylistid/members/$subscriber_hash", Argument::any())->shouldNotBeCalled();

    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_interest_1,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Added",
    ]);
    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_interest_1,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Removed",
    ]);
    $result = civicrm_api3('GroupContact', 'delete', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_interest_1,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
    ]);

    //
    // Test:
    //
    // Add them to the membership group, then these interest changes sould
    // result in an update.

    // Create a new prophecy since we used the last one to assert something had
    // not been called.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());

    // Prepare the mock for the updateMailchimpFromCiviSingleContact
    // We expect that a PUT request is sent to Mailchimp.
    $api_prophecy->put("/lists/dummylistid/members/$subscriber_hash",
      Argument::that(function($_){
        return $_['status'] == 'subscribed'
          && $_['interests']['interestId1'] === FALSE
          && $_['interests']['interestId2'] === FALSE
          && count($_['interests']) == 2;
      }))
      ->shouldBeCalled();

    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_membership,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Added",
    ]);

    // Use new prophecy
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->put("/lists/dummylistid/members/$subscriber_hash", Argument::any())->shouldBeCalledTimes(3);

    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_interest_1,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Added",
    ]);
    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_interest_1,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Removed",
    ]);
    $result = civicrm_api3('GroupContact', 'delete', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_interest_1,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
    ]);

  }
  /**
   * Checks that multiple updates do not trigger syncs.
   *
   * We run the testGetMCInterestGroupings first as it caches data this depends
   * on.
   * @depends testGetMCInterestGroupings
   */
  public function testPostHookDoesNotRunForBulkUpdates() {

    // Get Mock API.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());

    $api_prophecy->put()->shouldNotBeCalled();
    $api_prophecy->patch()->shouldNotBeCalled();
    $api_prophecy->get()->shouldNotBeCalled();
    $api_prophecy->post()->shouldNotBeCalled();
    $api_prophecy->delete()->shouldNotBeCalled();

    // Array of ContactIds - provide 2.
    $objectRef = [static::$civicrm_contact_1['contact_id'], 1];
    mailchimp_civicrm_post('create', 'GroupContact', $objectId=static::$civicrm_group_id_membership, $objectRef );

    // We did not change anything if we get here.
    static::$fixture_should_be_reset = FALSE;
  }
  /**
   * Tests the selection of email address.
   *
   * 1. Check initial email is picked up.
   * 2. Check that a bulk one is preferred, if exists.
   * 3. Check that a primary one is used bulk is on hold.
   * 4. Check that a primary one is used if no bulk one.
   * 5. Check that secondary, not bulk, not primary one is NOT used.
   * 6. Check that a not bulk, not primary one is used if all else fails.
   * 7. Check contact not selected if all emails on hold
   * 8. Check contact not selected if opted out
   * 9. Check contact not selected if 'do not email' is set
   * 10. Check contact not selected if deceased.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testCollectCiviUsesRightEmail() {

    $subscriber_hash = static::$civicrm_contact_1['subscriber_hash'];

    // Prepare the mock for the subscription the post hook will do.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->put("/lists/dummylistid/members/$subscriber_hash", Argument::any());
    $this->joinMembershipGroup(static::$civicrm_contact_1);
    $sync = new CRM_Mailchimp_Sync(static::$test_list_id);

    //
    // Test 1:
    //
    $sync->collectCiviCrm('push');
    // Should have one person in it.
    $this->assertEquals(1, $sync->countCiviCrmMembers());
    $dao = CRM_Core_DAO::executeQuery("SELECT email FROM tmp_mailchimp_push_c");
    $dao->fetch();
    // Check email is what we'd expect.
    $this->assertEquals(static::$civicrm_contact_1['email'], $dao->email);

    //
    // Test 2:
    //
    // Now add another email, this one is bulk.
    // Nb. adding a bulk email removes the is_bulkmail flag from other email
    // records.
    $second_email = civicrm_api3('Email', 'create', [
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'email' => static::$civicrm_contact_2['email'],
      'is_bulkmail' => 1,
      'sequential' => 1,
      ]);
    if (empty($second_email['id'])) {
      throw new Exception("Well this shouldn't happen. No Id for created email.");
    }
    $sync->collectCiviCrm('push');
    // Should have one person in it.
    $this->assertEquals(1, $sync->countCiviCrmMembers());
    $dao = CRM_Core_DAO::executeQuery("SELECT email FROM tmp_mailchimp_push_c");
    $dao->fetch();
    // Check email is what we'd expect.
    $this->assertEquals(static::$civicrm_contact_2['email'], $dao->email);

    //
    // Test 3:
    //
    // Set the bulk one to on hold.
    //
    civicrm_api3('Email', 'create', [
      'id' => $second_email['id'],
      // the API requires email to be passed, otherwise it deletes the record!
      'email' => $second_email['values'][0]['email'],
      'on_hold' => 1,
    ]);
    $sync->collectCiviCrm('push');
    $this->assertEquals(1, $sync->countCiviCrmMembers());
    $dao = CRM_Core_DAO::executeQuery("SELECT email FROM tmp_mailchimp_push_c");
    $dao->fetch();
    // Check email is what we'd expect.
    $this->assertEquals(static::$civicrm_contact_1['email'], $dao->email);

    //
    // Test 4:
    //
    // Delete the bulk one; should now fallback to primary.
    //
    civicrm_api3('Email', 'delete', ['id' => $second_email['id']]);
    $sync->collectCiviCrm('push');
    // Should have one person in it.
    $this->assertEquals(1, $sync->countCiviCrmMembers());
    $dao = CRM_Core_DAO::executeQuery("SELECT email FROM tmp_mailchimp_push_c");
    $dao->fetch();
    // Check email is what we'd expect.
    $this->assertEquals(static::$civicrm_contact_1['email'], $dao->email);

    //
    // Test 5:
    //
    // Add a not bulk, not primary one. This should NOT get used.
    //
    $second_email = civicrm_api3('Email', 'create', [
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'email' => static::$civicrm_contact_2['email'],
      'is_bulkmail' => 0,
      'is_primary' => 0,
      'sequential' => 1,
      ]);
    if (empty($second_email['id'])) {
      throw new Exception("Well this shouldn't happen. No Id for created email.");
    }
    $sync->collectCiviCrm('push');
    $this->assertEquals(1, $sync->countCiviCrmMembers());
    $dao = CRM_Core_DAO::executeQuery("SELECT email FROM tmp_mailchimp_push_c");
    $dao->fetch();
    // Check email is what we'd expect.
    $this->assertEquals(static::$civicrm_contact_1['email'], $dao->email);

    //
    // Test 6:
    //
    // Check that an email is selected, even if there's no primary and no bulk.
    //
    // Find the primary email and delete it.
    $result = civicrm_api3('Email', 'get', [
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'email' => static::$civicrm_contact_1['email'],
      'api.Email.delete' => ['id' => '$value.id']
    ]);

    $sync->collectCiviCrm('push');
    // Should have one person in it.
    $this->assertEquals(1, $sync->countCiviCrmMembers());
    $dao = CRM_Core_DAO::executeQuery("SELECT email FROM tmp_mailchimp_push_c");
    $dao->fetch();
    // Check email is what we'd expect.
    $this->assertEquals(static::$civicrm_contact_2['email'], $dao->email);

    //
    // Test 7
    //
    // Check that if all emails are on hold, user is not selected.
    //
    civicrm_api3('Email', 'create', [
      'id' => $second_email['id'],
      // the API requires email to be passed, otherwise it deletes the record!
      'email' => $second_email['values'][0]['email'],
      'on_hold' => 1
    ]);
    $sync->collectCiviCrm('push');
    $this->assertEquals(0, $sync->countCiviCrmMembers());

    //
    // Test 8
    //
    // Check that even with a bulk, primary email, contact is not selected if
    // they have opted out.
    civicrm_api3('Email', 'create', [
      'id' => $second_email['id'],
      // the API requires email to be passed, otherwise it deletes the record!
      'email' => $second_email['values'][0]['email'],
      'on_hold' => 0,
    ]);
    civicrm_api3('Contact', 'create', [
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'is_opt_out' => 1,
    ]);
    $sync->collectCiviCrm('push');
    $this->assertEquals(0, $sync->countCiviCrmMembers());


    //
    // Test 9
    //
    // Check that even with a bulk, primary email, contact is not selected if
    // they have do_not_email
    civicrm_api3('Contact', 'create', [
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'is_opt_out' => 0,
      'do_not_email' => 1,
    ]);
    $sync->collectCiviCrm('push');
    $this->assertEquals(0, $sync->countCiviCrmMembers());


    //
    // Test 10
    //
    // Check that even with a bulk, primary email, contact is not selected if
    // they is_deceased
    civicrm_api3('Contact', 'create', [
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'do_not_email' => 0,
      'is_deceased' => 1,
    ]);
    $sync->collectCiviCrm('push');
    $this->assertEquals(0, $sync->countCiviCrmMembers());

  }
  /**
   * Tests the copying of names from Mailchimp to temp table.
   *
   * 1. Check FNAME and LNAME are copied to first_name and last_name.
   * 2. Repeat check 1 but with presence of populated NAME field also.
   * 3. Check first and last _name fields are populated from NAME field
   *    if NAME not empty and FNAME and LNAME are empty.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testCollectMailchimpParsesNames() {

    // Prepare the mock for the subscription the post hook will do.

    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->get("/lists/dummylistid/members", Argument::any())
      ->shouldBeCalled()
      ->willReturn(
        json_decode(json_encode([
          'http_code' => 200,
          'data' => [
            'total_items' => 5,
            'members' => [
              [ // "normal" case - FNAME and LNAME fields present.
                'email_address' => '1@example.com',
                'interests' => [],
                'merge_fields' => [
                  'FNAME' => 'Foo',
                  'LNAME' => 'Bar',
                ],
              ],
              [ // ALSO has NAME field - which should be ignored if we have vals in FNAME, LNAME
                'email_address' => '2@example.com',
                'interests' => [],
                'merge_fields' => [
                  'FNAME' => 'Foo',
                  'LNAME' => 'Bar',
                  'NAME'  => 'Some other name',
                ],
              ],
              [ // Present: FNAME, LNAME, NAME, but empty FNAME, LNAME - should use NAME
                'email_address' => '3@example.com',
                'interests' => [],
                'merge_fields' => [
                  'FNAME' => '',
                  'LNAME' => '',
                  'NAME'  => 'Foo Bar',
                ],
              ],
              [ // Only a NAME merge field - should extract first and last names from it.
                'email_address' => '4@example.com',
                'interests' => [],
                'merge_fields' => [
                  'NAME'  => 'Foo Bar',
                ],
              ],
            ]
          ]])));

    $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
    $sync->collectMailchimp('pull');

    // Test expected results.
    $dao = CRM_Core_DAO::executeQuery("SELECT * FROM tmp_mailchimp_push_m;");
    while ($dao->fetch()) {
      $this->assertEquals(
        ['Foo', 'Bar'],
        [$dao->first_name, $dao->last_name],
        "Error on $dao->email");
    }
    $dao->free();
  }
  /**
   * Check that list problems are spotted.
   *
   * 1. Test for missing webhooks.
   * 2. Test for error if the list is not found at Mailchimp.
   * 3. Test for network error.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testCheckGroupsConfig() {
    //
    // Test 1
    //
    // The default mock list does not have any webhooks set.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->get('/lists/dummylistid/webhooks');
    $groups = CRM_Mailchimp_Utils::getGroupsToSync([static::$civicrm_group_id_membership]);
    $warnings = CRM_Mailchimp_Utils::checkGroupsConfig($groups);
    $this->assertEquals(1, count($warnings));
    $this->assertContains(ts('Need to create a webhook'), $warnings[0]);


    //
    // Test 2
    //
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->get('/lists/dummylistid/webhooks')
      ->will(function($args) {
        // Need to mock a 404 response.
        $this->response = (object) ['http_code' => 404, 'data' => []];
        $this->request = (object) ['method' => 'GET'];
        throw new CRM_Mailchimp_RequestErrorException($this->reveal(), "Not found");
      });
    $groups = CRM_Mailchimp_Utils::getGroupsToSync([static::$civicrm_group_id_membership]);
    $warnings = CRM_Mailchimp_Utils::checkGroupsConfig($groups);
    $this->assertEquals(1, count($warnings));
    $this->assertContains(ts('The Mailchimp list that this once worked with has been deleted'), $warnings[0]);

    //
    // Test 3
    //
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->get('/lists/dummylistid/webhooks')
      ->will(function($args) {
        // Need to mock a network error
        $this->response = (object) ['http_code' => 500, 'data' => []];
        throw new CRM_Mailchimp_NetworkErrorException($this->reveal(), "Someone unplugged internet");
      });
    $groups = CRM_Mailchimp_Utils::getGroupsToSync([static::$civicrm_group_id_membership]);
    $warnings = CRM_Mailchimp_Utils::checkGroupsConfig($groups);
    $this->assertEquals(1, count($warnings));
    $this->assertContains(ts('Problems (possibly temporary)'), $warnings[0]);
    $this->assertContains(ts('Someone unplugged internet'), $warnings[0]);


    // We did not change anything on the fixture.
    static::$fixture_should_be_reset = FALSE;
  }
  /**
   * Check that config is updated as expected.
   *
   * 1. Webhook created where non exists.
   * 2. Webhook untouched if ok
   * 3. Webhook deleted, new one created if different.
   * 4. Webhooks untouched if multiple
   * 5. As 1 but in dry-run
   * 6. As 2 but in dry-run
   * 7. As 3 but in dry-run
   *
   *
   * @depends testGetMCInterestGroupings
   */
  public function testConfigureList() {
    //
    // Test 1
    //
    // The default mock list does not have any webhooks set, test one gets
    // created.
    //
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->get('/lists/dummylistid/webhooks')->shouldBeCalled();
    $api_prophecy->post('/lists/dummylistid/webhooks', Argument::any())->shouldBeCalled();
    $warnings = CRM_Mailchimp_Utils::configureList(static::$test_list_id);
    $this->assertEquals(1, count($warnings));
    $this->assertContains(ts('Created a webhook at Mailchimp'), $warnings[0]);

    //
    // Test 2
    //
    // If it's all correct, nothing to do.
    //
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->get('/lists/dummylistid/webhooks')->shouldBeCalled()->willReturn(
      json_decode(json_encode([
        'http_code' => 200,
        'data' => [
          'webhooks' => [
            [
              'id' => 'dummywebhookid',
              'url' => CRM_Mailchimp_Utils::getWebhookUrl(),
              'events' => [
                'subscribe' => TRUE,
                'unsubscribe' => TRUE,
                'profile' => TRUE,
                'cleaned' => TRUE,
                'upemail' => TRUE,
                'campaign' => FALSE,
              ],
              'sources' => [
                'user' => TRUE,
                'admin' => TRUE,
                'api' => FALSE,
              ],
            ]
        ]]])));
    $api_prophecy->post()->shouldNotBeCalled();
    $warnings = CRM_Mailchimp_Utils::configureList(static::$test_list_id);
    $this->assertEquals(0, count($warnings));

    //
    // Test 3
    //
    // If something's different, note and change.
    //
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->get('/lists/dummylistid/webhooks')->shouldBeCalled()->willReturn(
      json_decode(json_encode([
        'http_code' => 200,
        'data' => [
          'webhooks' => [
            [
              'id' => 'dummywebhookid',
              'url' => 'http://example.com', // WRONG
              'events' => [
                'subscribe' => FALSE, // WRONG
                'unsubscribe' => TRUE,
                'profile' => TRUE,
                'cleaned' => TRUE,
                'upemail' => TRUE,
                'campaign' => FALSE,
              ],
              'sources' => [
                'user' => TRUE,
                'admin' => TRUE,
                'api' => TRUE, // WRONG
              ],
            ]
        ]]])));
    $api_prophecy->delete('/lists/dummylistid/webhooks/dummywebhookid')->shouldBeCalled();
    $api_prophecy->post('/lists/dummylistid/webhooks', Argument::any())->shouldBeCalled();
    $warnings = CRM_Mailchimp_Utils::configureList(static::$test_list_id);
    $this->assertEquals(3, count($warnings));
    $this->assertContains('Changed webhook URL from http://example.com to', $warnings[0]);
    $this->assertContains('Changed webhook source api', $warnings[1]);
    $this->assertContains('Changed webhook event subscribe', $warnings[2]);

    //
    // Test 4
    //
    // If multiple webhooks configured, leave it alone.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->get('/lists/dummylistid/webhooks')->shouldBeCalled()->willReturn(
      json_decode(json_encode([
        'http_code' => 200,
        'data' => [
          'webhooks' => [1, 2],
        ]])));
    $api_prophecy->delete()->shouldNotBeCalled();
    $api_prophecy->post()->shouldNotBeCalled();
    $warnings = CRM_Mailchimp_Utils::configureList(static::$test_list_id);
    $this->assertEquals(1, count($warnings));
    $this->assertContains('Mailchimp list dummylistid has more than one webhook configured.', $warnings[0]);

    //
    // Test 5
    //
    // The default mock list does not have any webhooks set, test one gets
    // created.
    //
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->get('/lists/dummylistid/webhooks')->shouldBeCalled();
    $api_prophecy->delete()->shouldNotBeCalled();
    $api_prophecy->post()->shouldNotBeCalled();
    $warnings = CRM_Mailchimp_Utils::configureList(static::$test_list_id, TRUE);
    $this->assertEquals(1, count($warnings));
    $this->assertContains(ts('Need to create a webhook at Mailchimp'), $warnings[0]);

    //
    // Test 6
    //
    // If it's all correct, nothing to do.
    //
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->get('/lists/dummylistid/webhooks')->shouldBeCalled()->willReturn(
      json_decode(json_encode([
        'http_code' => 200,
        'data' => [
          'webhooks' => [
            [
              'id' => 'dummywebhookid',
              'url' => CRM_Mailchimp_Utils::getWebhookUrl(),
              'events' => [
                'subscribe' => TRUE,
                'unsubscribe' => TRUE,
                'profile' => TRUE,
                'cleaned' => TRUE,
                'upemail' => TRUE,
                'campaign' => FALSE,
              ],
              'sources' => [
                'user' => TRUE,
                'admin' => TRUE,
                'api' => FALSE,
              ],
            ]
        ]]])));
    $api_prophecy->delete()->shouldNotBeCalled();
    $api_prophecy->post()->shouldNotBeCalled();
    $warnings = CRM_Mailchimp_Utils::configureList(static::$test_list_id, TRUE);
    $this->assertEquals(0, count($warnings));

    //
    // Test 7
    //
    // If something's different, note and change.
    //
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->get('/lists/dummylistid/webhooks')->shouldBeCalled()->willReturn(
      json_decode(json_encode([
        'http_code' => 200,
        'data' => [
          'webhooks' => [
            [
              'id' => 'dummywebhookid',
              'url' => 'http://example.com', // WRONG
              'events' => [
                'subscribe' => FALSE, // WRONG
                'unsubscribe' => TRUE,
                'profile' => TRUE,
                'cleaned' => TRUE,
                'upemail' => TRUE,
                'campaign' => FALSE,
              ],
              'sources' => [
                'user' => TRUE,
                'admin' => TRUE,
                'api' => TRUE, // WRONG
              ],
            ]
        ]]])));
    $api_prophecy->delete()->shouldNotBeCalled();
    $api_prophecy->post()->shouldNotBeCalled();
    $warnings = CRM_Mailchimp_Utils::configureList(static::$test_list_id, TRUE);
    $this->assertEquals(3, count($warnings));
    $this->assertContains('Need to change webhook URL from http://example.com to', $warnings[0]);
    $this->assertContains('Need to change webhook source api', $warnings[1]);
    $this->assertContains('Need to change webhook event subscribe', $warnings[2]);

    // We did not change anything on the fixture.
    static::$fixture_should_be_reset = FALSE;
  }
  /**
   * Tests the slow/one-off contact identifier.
   *
   * 1. unique email match.
   * 2. email exists twice, but on the same contact
   * 3. email exists multiple times, on multiple contacts
   *    but only one contact has the same last name.
   * 4. email exists multiple times, on multiple contacts with same last name
   *    but only one contact has the same first name.
   * 5. email exists multiple times, on multiple contacts with same last name
   *    and first name. Returning *either* contact is OK.
   * 6. email exists multiple times, on multiple contacts with same last name
   *    and first name. But only one contact is in the group.
   * 7. email exists multiple times, on multiple contacts with same last name
   *    and first name and both contacts on the group.
   * 8. email exists multiple times, on multiple contacts with same last name
   *    and different first names and both contacts on the group.
   * 9. email exists multiple times, on multiple contacts with same last name
   *    but there's one contact on the group with the wrong first name and one
   *    contact off the group with the right first name.
   * 10. email exists multiple times, on multiple contacts not on the group
   *    and none of them has the right last name but one has right first name -
   *    should be picked.
   * 11. email exists multiple times, on multiple contacts not on the group
   *    and none of them has the right last or first name
   *
   * @depends testGetMCInterestGroupings
   */
  public function testGuessContactIdSingle() {

    // Mock the API
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->put();
    $api_prophecy->get();

    //
    // 1. unique email match.
    //
    $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
    $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], static::$civicrm_contact_1['first_name'], static::$civicrm_contact_1['last_name']);
    $this->assertEquals(static::$civicrm_contact_1['contact_id'], $c);

    //
    // 2. email exists twice, but on the same contact
    //
    $second_email = civicrm_api3('Email', 'create', [
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'email' => static::$civicrm_contact_1['email'],
      'is_billing' => 1,
      'sequential' => 1,
      ]);
    $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], static::$civicrm_contact_1['first_name'], static::$civicrm_contact_1['last_name']);
    $this->assertEquals(static::$civicrm_contact_1['contact_id'], $c);

    //
    // 3. email exists multiple times, on multiple contacts
    // but only one contact has the same last name.
    //
    // Give the second email to the 2nd contact.
    $r = civicrm_api3('Email', 'create', [
      'id' => $second_email['id'],
      'contact_id' => static::$civicrm_contact_2['contact_id'],
      'email' => static::$civicrm_contact_1['email'],
      ]);
    $c1 = static::$civicrm_contact_1;
    $c2 = static::$civicrm_contact_2;
    $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], static::$civicrm_contact_1['first_name'], static::$civicrm_contact_1['last_name']);
    $this->assertEquals(static::$civicrm_contact_1['contact_id'], $c);

    //
    // 4. email exists multiple times, on multiple contacts with same last name
    // but only one contact has the same first name.
    //
    // Rename second contact's last name
    $r = civicrm_api3('Contact', 'create', [
      'contact_id' => $c2['contact_id'],
      'last_name'  => $c1['last_name'],
      ]);
    $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], static::$civicrm_contact_1['first_name'], static::$civicrm_contact_1['last_name']);
    $this->assertEquals(static::$civicrm_contact_1['contact_id'], $c);

    //
    // 5. email exists multiple times, on multiple contacts with same last name
    // and first name. Returning *either* contact is OK.
    //
    // Rename second contact's first name
    $r = civicrm_api3('Contact', 'create', [
      'contact_id' => $c2['contact_id'],
      'first_name'  => $c1['first_name'],
      ]);
    $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], static::$civicrm_contact_1['first_name'], static::$civicrm_contact_1['last_name']);
    $this->assertContains($c, [$c1['contact_id'], $c2['contact_id']]);


    //
    // 6. email exists multiple times, on multiple contacts with same last name
    // and first name. But only one contact is in the group.
    //
    $this->joinMembershipGroup($c1);
    $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], static::$civicrm_contact_1['first_name'], static::$civicrm_contact_1['last_name']);
    $this->assertEquals(static::$civicrm_contact_1['contact_id'], $c);

    //
    // 7. email exists multiple times, on multiple contacts with same last name
    // and first name and both contacts on the group.
    //
    $this->joinMembershipGroup($c2);
    $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], static::$civicrm_contact_1['first_name'], static::$civicrm_contact_1['last_name']);
    $this->assertEquals(static::$civicrm_contact_1['contact_id'], $c);

    //
    // 8. email exists multiple times, on multiple contacts with same last name
    // and different first names and both contacts on the group.
    //
    civicrm_api3('Contact', 'create', ['contact_id' => $c2['contact_id'], 'first_name'  => $c2['first_name']]);
    $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], static::$civicrm_contact_1['first_name'], static::$civicrm_contact_1['last_name']);
    $this->assertEquals(static::$civicrm_contact_1['contact_id'], $c);

    //
    // 9. email exists multiple times, on multiple contacts with same last name
    // but there's one contact on the group with the wrong first name and one
    // contact off the group with the right first name.
    //
    // It should go to the contact on the group.
    //
    // Remove contact 1 (has right names) from group, leaving contact 2.
    $this->removeGroup($c1, static::$civicrm_group_id_membership, TRUE);
    civicrm_api3('Contact', 'create', ['contact_id' => $c2['contact_id'], 'first_name'  => $c2['first_name']]);
    $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], static::$civicrm_contact_1['first_name'], static::$civicrm_contact_1['last_name']);
    $this->assertEquals(static::$civicrm_contact_2['contact_id'], $c);


    //
    // 10. email exists multiple times, on multiple contacts not on the group
    // and none of them has the right last name but one has right first name -
    // should be picked.
    //
    // This is a grudge - we're just going on email and first name, which is not
    // lots, but we really want to avoid not being able to match someone up as
    // then we lose any chance of managing this contact/subscription.
    //
    // Remove contact 2 from group, none now on the group.
    $this->removeGroup($c2, static::$civicrm_group_id_membership, TRUE);
    $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], static::$civicrm_contact_1['first_name'], 'thisnameiswrong');
    $this->assertEquals(static::$civicrm_contact_1['contact_id'], $c);


    //
    // 11. email exists multiple times, on multiple contacts not on the group
    // and none of them has the right last or first name
    //
    try {
      $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], 'wrongfirstname', 'thisnameiswrong');
      $this->fail("Expected a CRM_Mailchimp_DuplicateContactsException to be thrown.");
    }
    catch (CRM_Mailchimp_DuplicateContactsException $e) {}

  }
  /**
   * Tests the slow/one-off contact identifier when limited to contacts in the
   * group.
   *
   * 1. unique email match but contact not in group - should return NUlL
   * 2. unique email match and contact not in group - should identify
   * 3. email exists twice, but on the same contact who is not in the
   *    membership group.
   *
   * 2. email exists twice, but on the same contact
   * 3. email exists multiple times, on multiple contacts
   *    but only one contact has the same last name.
   * 4. email exists multiple times, on multiple contacts with same last name
   *    but only one contact has the same first name.
   * 5. email exists multiple times, on multiple contacts with same last name
   *    and first name. Returning *either* contact is OK.
   * 6. email exists multiple times, on multiple contacts with same last name
   *    and first name. But only one contact is in the group.
   * 7. email exists multiple times, on multiple contacts with same last name
   *    and first name and both contacts on the group.
   * 8. email exists multiple times, on multiple contacts with same last name
   *    and different first names and both contacts on the group.
   * 9. email exists multiple times, on multiple contacts with same last name
   *    but there's one contact on the group with the wrong first name and one
   *    contact off the group with the right first name.
   * 10. email exists multiple times, on multiple contacts not on the group
   *    and none of them has the right last name but one has right first name -
   *    should be picked.
   * 11. email exists multiple times, on multiple contacts not on the group
   *    and none of them has the right last or first name
   *
   * @depends testGetMCInterestGroupings
   */
  public function testGuessContactIdSingleMembershipGroupOnly() {

    $c1 = static::$civicrm_contact_1;
    $c2 = static::$civicrm_contact_2;
    // Mock the API
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->put();
    $api_prophecy->get();

    //
    // 1. unique email match but contact is not in group.
    //
    $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
    $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], static::$civicrm_contact_1['first_name'], static::$civicrm_contact_1['last_name'], TRUE);
    $this->assertNull($c);

    //
    // 2. unique email match and contact not in group - should identify
    //
    // Add c1 to the membership group.
    $this->joinMembershipGroup($c1);
    $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], static::$civicrm_contact_1['first_name'], static::$civicrm_contact_1['last_name'], TRUE);
    $this->assertEquals($c1['contact_id'], $c);

    //
    // 3. email exists twice, but on the same contact who is not in the
    // membership group.
    //
    $this->removeGroup($c1, static::$civicrm_group_id_membership, TRUE);
    $second_email = civicrm_api3('Email', 'create', [
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'email' => static::$civicrm_contact_1['email'],
      'is_billing' => 1,
      'sequential' => 1,
      ]);
    $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], static::$civicrm_contact_1['first_name'], static::$civicrm_contact_1['last_name'], TRUE);
    $this->assertNull($c);

    //
    // 4. email exists several times but none of these contacts are in the
    // group.
    civicrm_api3('Email', 'create', [
      'contact_id' => static::$civicrm_contact_2['contact_id'],
      'email' => static::$civicrm_contact_1['email'],
      'is_billing' => 1,
      'sequential' => 1,
      ]);
    $c = $sync->guessContactIdSingle(static::$civicrm_contact_1['email'], static::$civicrm_contact_1['first_name'], static::$civicrm_contact_1['last_name'], TRUE);
    $this->assertNull($c);

  }
  /**
   * Tests the removeInSync method.
   *
   */
  public function testRemoveInSync() {
    // Create empty tables.
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
    CRM_Mailchimp_Sync::createTemporaryTableForCiviCRM();

    // Prepare the mock for the subscription the post hook will do.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->get('/lists/dummylistid/interest-categories', Argument::any() )
      ->willReturn(json_decode('{"http_code":200,"data":{"categories":[{"id":"categoryid","title":"'. static::MC_INTEREST_CATEGORY_TITLE . '"}]}}'));
    $api_prophecy->get("/lists/dummylistid/interest-categories/categoryid/interests", Argument::any())
      ->willReturn(json_decode('{"http_code":200,"data":{"interests":[{"id":"interestId1","name":"' . static::MC_INTEREST_NAME_1 . '"},{"id":"interestId2","name":"' . static::MC_INTEREST_NAME_2 . '"}]}}'));
    $api_prophecy->get('/lists', ['fields' => 'lists.id,lists.name','count'=>10000])
      ->willReturn(json_decode(json_encode([
          'http_code' => 200,
          'data' => [ 'lists' => [] ],
        ])));
    $sync = new CRM_Mailchimp_Sync(static::$test_list_id);

    // Test 1.
    //
    // Delete records from both tables when there's a cid_guess--contact link
    // and the hash is the same.
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_c (email, hash, contact_id) VALUES
      ('found@example.com', 'aaaaaaaaaaaaaaaa', 1),
      ('red-herring@example.com', 'aaaaaaaaaaaaaaaa', 2);");
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email, hash, cid_guess) VALUES
      ('found@example.com', 'aaaaaaaaaaaaaaaa', 1),
      ('notfound@example.com', 'aaaaaaaaaaaaaaaa', 2);");

    $result = $sync->removeInSync('pull');
    $this->assertEquals(2, $result);
    $this->assertEquals(0, $sync->countMailchimpMembers());
    $this->assertEquals(0, $sync->countCiviCrmMembers());

    // Test 2.
    //
    // Check different hashes stops removals.
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_c (email, hash, contact_id) VALUES
      ('found@example.com', 'different', 1),
      ('red-herring@example.com', 'different', 2);");
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email, hash, cid_guess) VALUES
      ('found@example.com', 'aaaaaaaaaaaaaaaa', 1),
      ('notfound@example.com', 'aaaaaaaaaaaaaaaa', 2);");

    $result = $sync->removeInSync('pull');
    $this->assertEquals(0, $result);
    $this->assertEquals(2, $sync->countMailchimpMembers());
    $this->assertEquals(2, $sync->countCiviCrmMembers());

    // Test 3.
    //
    // Check nothing removed if no cid-contact match.
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
    CRM_Mailchimp_Sync::createTemporaryTableForCiviCRM();
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_c (email, hash, contact_id) VALUES
      ('found@example.com', 'aaaaaaaaaaaaaaaa', 1),
      ('red-herring@example.com', 'aaaaaaaaaaaaaaaa', 2);");
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email, hash, cid_guess) VALUES
      ('found@example.com', 'aaaaaaaaaaaaaaaa', 0),
      ('notfound@example.com', 'aaaaaaaaaaaaaaaa', NULL);");

    $result = $sync->removeInSync('pull');
    $this->assertEquals(0, $result);
    $this->assertEquals(2, $sync->countMailchimpMembers());
    $this->assertEquals(2, $sync->countCiviCrmMembers());

    // Test 4.
    //
    // Check duplicate civi contact deleted.
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
    CRM_Mailchimp_Sync::createTemporaryTableForCiviCRM();
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_c (email, hash, contact_id) VALUES
      ('duplicate@example.com', 'Xaaaaaaaaaaaaaaa', 1),
      ('duplicate@example.com', 'Yaaaaaaaaaaaaaaa', 2);");
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email, hash, cid_guess) VALUES
      ('duplicate@example.com', 'bbbbbbbbbbbbbbbb', 1);");

    $result = $sync->removeInSync('push');
    $this->assertEquals(1, $result);
    $this->assertEquals(1, $sync->countMailchimpMembers());
    $this->assertEquals(1, $sync->countCiviCrmMembers());


    // Test 5.
    //
    // Check duplicate civi contact NOT deleted when in pull mode.
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
    CRM_Mailchimp_Sync::createTemporaryTableForCiviCRM();
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_c (email, hash, contact_id) VALUES
      ('duplicate@example.com', 'Xaaaaaaaaaaaaaaa', 1),
      ('duplicate@example.com', 'Yaaaaaaaaaaaaaaa', 2);");
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email, hash, cid_guess) VALUES
      ('duplicate@example.com', 'bbbbbbbbbbbbbbbb', 1);");

    $result = $sync->removeInSync('pull');
    $this->assertEquals(0, $result);
    $this->assertEquals(1, $sync->countMailchimpMembers());
    $this->assertEquals(2, $sync->countCiviCrmMembers());


    // Test 5: one contact should be removed because it's in sync, the other
    // because it's a duplicate.
    //
    // Check duplicate civi contact deleted.
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
    CRM_Mailchimp_Sync::createTemporaryTableForCiviCRM();
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_c (email, hash, contact_id) VALUES
      ('duplicate@example.com', 'aaaaaaaaaaaaaaaa', 1),
      ('duplicate@example.com', 'Yaaaaaaaaaaaaaaa', 2);");
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email, hash, cid_guess) VALUES
      ('duplicate@example.com', 'aaaaaaaaaaaaaaaa', 1);");

    $result = $sync->removeInSync('push');
    $this->assertEquals(2, $result);
    $this->assertEquals(0, $sync->countMailchimpMembers());
    $this->assertEquals(0, $sync->countCiviCrmMembers());


    CRM_Mailchimp_Sync::dropTemporaryTables();
  }
  /**
   * Test the webhook checks the key matches.
   *
   * @expectedException RuntimeException
   * @expectedExceptionMessage Invalid security key.
   */
  public function testWebhookInvalidKey() {
    // We do not change anything on the fixture.
    static::$fixture_should_be_reset = FALSE;
    $w = new CRM_Mailchimp_Page_WebHook();
    list($code, $response) = $w->processRequest('wrongkey', '', []);
  }
  /**
   * Test the webhook checks the key exists locally.
   *
   * @expectedException RuntimeException
   * @expectedExceptionMessage Invalid security key.
   */
  public function testWebhookMissingLocalKey() {
    // We do not change anything on the fixture.
    static::$fixture_should_be_reset = FALSE;
    $w = new CRM_Mailchimp_Page_WebHook();
    list($code, $response) = $w->processRequest(NULL, 'akey', []);
  }
  /**
   * Test the webhook checks the key exists in request.
   *
   * @expectedException RuntimeException
   * @expectedExceptionMessage Invalid security key.
   */
  public function testWebhookMissingRequestKey() {
    // We do not change anything on the fixture.
    static::$fixture_should_be_reset = FALSE;
    $w = new CRM_Mailchimp_Page_WebHook();
    list($code, $response) = $w->processRequest('akey', NULL, []);
  }
  /**
   * Test the webhook checks the key is not empty.
   *
   * @expectedException RuntimeException
   * @expectedExceptionMessage Invalid security key.
   */
  public function testWebhookMissingKeys() {
    // We do not change anything on the fixture.
    static::$fixture_should_be_reset = FALSE;
    $w = new CRM_Mailchimp_Page_WebHook();
    list($code, $response) = $w->processRequest('', '', []);
  }
  /**
   * Test the webhook checks the key matches.
   *
   * @expectedException RuntimeException
   * @expectedExceptionMessage Invalid security key.
   */
  public function testWebhookWrongKeys() {
    // We do not change anything on the fixture.
    static::$fixture_should_be_reset = FALSE;
    $w = new CRM_Mailchimp_Page_WebHook();
    list($code, $response) = $w->processRequest('a', 'b', []);
  }
  /**
   * Test the webhook configured incorrectly.
   *
   * @expectedException RuntimeException
   * @expectedExceptionMessageRegExp /The list 'dummylistid' is not configured correctly at Mailchimp/
   */
  public function testWebhookWrongConfig() {
    // We do not change anything on the fixture.
    static::$fixture_should_be_reset = FALSE;

    // Make mock API that will return a webhook with the sources.API setting
    // set, which is wrong.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $url = CRM_Mailchimp_Utils::getWebhookUrl();
    $api_prophecy->get("/lists/dummylistid/webhooks", Argument::any())
      ->shouldBeCalled()
      ->willReturn(json_decode('{"http_code":200,"data":{"webhooks":[{"url":"' . $url . '","sources":{"api":true}}]}}'));
    $w = new CRM_Mailchimp_Page_WebHook();
    list($code, $response) = $w->processRequest('a', 'a', [
      'type' => 'subscribe',
      'data' => ['list_id' => 'dummylistid'],
    ]);
  }
  /**
   * Test the 'cleaned' webhook fails if the email cannot be found.
   *
   * @expectedException RuntimeException
   * @expectedExceptionMessage Email unknown
   * @expectedExceptionCode 200
   * @depends testGetMCInterestGroupings
   */
  public function testWebhookCleanedIfEmailNotFound() {
    $api_prophecy = $this->prepMockForWebhookConfig();
    $w = new CRM_Mailchimp_Page_WebHook();

    list($code, $response) = $w->processRequest('key', 'key', [
      'type' => 'cleaned',
      'data' => [
        'list_id'     => 'dummylistid',
        'email'       => 'different-' . static::$civicrm_contact_1['email'],
        'reason'      => 'hard',
        'campaign_id' => 'dummycampaignid',
      ]]);
  }
  /**
   * Test the 'cleaned' webhook fails abuse but not subscribed.
   *
   * @expectedException RuntimeException
   * @expectedExceptionMessage Email unknown
   * @expectedExceptionCode 200
   * @depends testGetMCInterestGroupings
   */
  public function testWebhookCleanedAbuseButEmailNotSubscribed() {
    $api_prophecy = $this->prepMockForWebhookConfig();
    $w = new CRM_Mailchimp_Page_WebHook();

    list($code, $response) = $w->processRequest('key', 'key', [
      'type' => 'cleaned',
      'data' => [
        'list_id'     => 'dummylistid',
        'email'       =>  static::$civicrm_contact_1['email'],
        'reason'      => 'abuse',
        'campaign_id' => 'dummycampaignid',
      ]]);
  }
  /**
   * Test the 'cleaned' webhook removes puts an email on hold regardless of
   * membership, if it's a 'hard' one.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testWebhookCleanedHardPutsOnHold() {
    $api_prophecy = $this->prepMockForWebhookConfig();
    $w = new CRM_Mailchimp_Page_WebHook();

    list($code, $response) = $w->processRequest('key', 'key', [
      'type' => 'cleaned',
      'data' => [
        'list_id'     => 'dummylistid',
        'email'       => static::$civicrm_contact_1['email'],
        'reason'      => 'hard',
        'campaign_id' => 'dummycampaignid',
      ]]);

    // Email should still exist.
    $result = civicrm_api3('Email', 'getsingle', ['email' => static::$civicrm_contact_1['email']]);
    // And it should be on hold.
    $this->assertEquals(1, $result['on_hold']);
  }
  /**
   * Test the 'cleaned' webhook removes puts an email on hold.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testWebhookCleanedAbusePutsOnHold() {
    $api_prophecy = $this->prepMockForWebhookConfig();
    $w = new CRM_Mailchimp_Page_WebHook();
    $this->joinMembershipGroup(static::$civicrm_contact_1, TRUE);

    list($code, $response) = $w->processRequest('key', 'key', [
      'type' => 'cleaned',
      'data' => [
        'list_id'     => 'dummylistid',
        'email'       => static::$civicrm_contact_1['email'],
        'reason'      => 'abuse',
        'campaign_id' => 'dummycampaignid',
      ]]);

    // Email should still exist.
    $result = civicrm_api3('Email', 'getsingle', ['email' => static::$civicrm_contact_1['email']]);
    // And it should be on hold.
    $this->assertEquals(1, $result['on_hold']);
  }
  /**
   * Test the 'cleaned' 'hard' webhook removes puts an email found several times
   * on hold.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testWebhookCleanedHardPutsOnHoldMultiple() {
    $api_prophecy = $this->prepMockForWebhookConfig();
    $w = new CRM_Mailchimp_Page_WebHook();

    // Add the same email to contact 2.
    civicrm_api3('Email', 'create', [
      'email' => static::$civicrm_contact_1['email'],
      'contact_id' => static::$civicrm_contact_2['contact_id'],
      'on_hold' => 0,
    ]);

    list($code, $response) = $w->processRequest('key', 'key', [
      'type' => 'cleaned',
      'data' => [
        'list_id'     => 'dummylistid',
        'email'       => static::$civicrm_contact_1['email'],
        'reason'      => 'hard',
        'campaign_id' => 'dummycampaignid',
      ]]);

    $result = civicrm_api3('Email', 'get', ['email' => static::$civicrm_contact_1['email']]);
    $this->assertEquals(2, $result['count']);
    foreach ($result['values'] as $email) {
      // And it should be on hold.
      $this->assertEquals(1, $email['on_hold']);
    }
  }
  /**
   * Test the 'subscribe' webhook works for adding a new contact.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testWebhookSubscribeNew() {

    // Remove contact 1 from database.
    $this->assertGreaterThan(0, static::$civicrm_contact_1['contact_id']);
    $result = civicrm_api3('Contact', 'delete', ['id' => static::$civicrm_contact_1['contact_id'], 'skip_undelete' => 1]);

    $api_prophecy = $this->prepMockForWebhookConfig();
    $w = new CRM_Mailchimp_Page_WebHook();
    list($code, $response) = $w->processRequest('key', 'key', [
      'type' => 'subscribe',
      'data' => [
        'list_id' => 'dummylistid',
        'merges' => [
          'FNAME' => static::$civicrm_contact_1['first_name'],
          'LNAME' => static::$civicrm_contact_1['last_name'],
          'INTERESTS' => '',
          ],
        'email' => static::$civicrm_contact_1['email'],
      ]]);
    $this->assertEquals(200, $code);
    // We ought to be able to find the contact.
    $result = civicrm_api3('Contact', 'getsingle', [
      'first_name' => static::$civicrm_contact_1['first_name'],
      'last_name' => static::$civicrm_contact_1['last_name'],
      ]);
    $this->assertGreaterThan(0, $result['contact_id']);
    static::$civicrm_contact_1['contact_id'] = $result['contact_id'];
    $this->assertEquals(static::$civicrm_contact_1['email'], $result['email']);
  }
  /**
   * Test the 'subscribe' webhook works for editing an existing contact.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testWebhookSubscribeExistingContact() {

    $api_prophecy = $this->prepMockForWebhookConfig();
    $w = new CRM_Mailchimp_Page_WebHook();
    list($code, $response) = $w->processRequest('key', 'key', [
      'type' => 'subscribe',
      'data' => [
        'list_id' => 'dummylistid',
        'merges' => [
          'FNAME' => static::$civicrm_contact_1['first_name'],
          'LNAME' => static::$civicrm_contact_1['last_name'],
          'INTERESTS' => '',
          ],
        'email' => static::$civicrm_contact_1['email'],
      ]]);
    $this->assertEquals(200, $code);
    // Check there is only one matching contact.
    $result = civicrm_api3('Contact', 'getsingle', [
      'first_name' => static::$civicrm_contact_1['first_name'],
      'last_name' => static::$civicrm_contact_1['last_name'],
      'return' => 'contact_id,group',
      ]);

    // We need the membership group...
    $this->assertContactIsInGroup($result['contact_id'], static::$civicrm_group_id_membership);

    // Check that we have not duplicated emails.
    $result = civicrm_api3('Email', 'get', ['email' => static::$civicrm_contact_1['email']]);
    $this->assertEquals(1, $result['count']);
  }
  /**
   * Test the 'subscribe' webhook works to change names and interest groups.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testWebhookSubscribeExistingChangesData() {

    static::joinMembershipGroup(static::$civicrm_contact_1, TRUE);
    // Give contact interest 1 but not 2.
    static::joinGroup(static::$civicrm_contact_1, static::$civicrm_group_id_interest_1, TRUE);

    $api_prophecy = $this->prepMockForWebhookConfig();
    $w = new CRM_Mailchimp_Page_WebHook();
    list($code, $response) = $w->processRequest('key', 'key', [
      'type' => 'subscribe',
      'data' => [
        'list_id' => 'dummylistid',
        'merges' => [
          // Replace first name
          'FNAME' => static::$civicrm_contact_2['first_name'],
          // Mailchimp does not have last name: should NOT be replaced
          'LNAME' => '',
          // Mailchimp thinks interst 2 not 1.
          'INTERESTS' => static::MC_INTEREST_NAME_2,
          ],
        'email' => static::$civicrm_contact_1['email'],
      ]]);
    $this->assertEquals(200, $code);
    // Check that we have not duplicated emails.
    $result = civicrm_api3('Email', 'getsingle', ['email' => static::$civicrm_contact_1['email']]);
    // Check there is still only one matching contact for this last name.
    $result = civicrm_api3('Contact', 'getsingle', ['last_name' => static::$civicrm_contact_1['last_name']]);
    // Load contact 1
    $result = civicrm_api3('Contact', 'getsingle', ['id' => static::$civicrm_contact_1['contact_id']]);
    // Check that the first name *was* changed.
    $this->assertEquals(static::$civicrm_contact_2['first_name'], $result['first_name']);
    // Check that the last name was *not* changed.
    $this->assertEquals(static::$civicrm_contact_1['last_name'], $result['last_name']);
    // Check they're still in the membership group.
    $this->assertContactIsInGroup($result['contact_id'], static::$civicrm_group_id_membership);
    // Check they're now *not* in interest 2
    $this->assertContactIsNotInGroup($result['contact_id'], static::$civicrm_group_id_interest_1);
    // Check they're now in interest 2
    $this->assertContactIsInGroup($result['contact_id'], static::$civicrm_group_id_interest_2);
  }
  /**
   * Test the 'profile' webhook uses a 10s delay.
   *
   * The profile webhook simply calls subscribe after 10s.
   * We just test that's happening. Dull test.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testWebhookProfile() {

    $api_prophecy = $this->prepMockForWebhookConfig();
    $w = new CRM_Mailchimp_Page_WebHook();
    $start = microtime(TRUE);
    list($code, $response) = $w->processRequest('key', 'key', [
      'type' => 'profile',
      'data' => [
        'list_id' => 'dummylistid',
        'merges' => [
          'FNAME' => static::$civicrm_contact_1['first_name'],
          'LNAME' => static::$civicrm_contact_1['last_name'],
          'INTERESTS' => '',
          ],
        'email' => static::$civicrm_contact_1['email'],
      ]]);
    $this->assertEquals(200, $code);

    // Ensure a 10s delay was used.
    $this->assertGreaterThan(10, microtime(TRUE) - $start);
  }
  /**
   * Test the 'upemail' webhook changes an email.
   *
   * The contact fixture is set up with one email set to both primary and bulk.
   * A change in email should change this single email address.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testWebhookUpemailChangesExistingBulk() {
    $this->joinMembershipGroup(static::$civicrm_contact_1, TRUE);
    $api_prophecy = $this->prepMockForWebhookConfig();
    $w = new CRM_Mailchimp_Page_WebHook();
    $new_email = 'new-' . static::$civicrm_contact_1['email'];
    list($code, $response) = $w->processRequest('key', 'key', [
      'type' => 'upemail',
      'data' => [
        'list_id' => 'dummylistid',
        'new_email' => $new_email,
        'old_email' => static::$civicrm_contact_1['email'],
      ]]);
    $this->assertEquals(200, $code);

    // Check we no longer have the original email.
    $result = civicrm_api3('Email', 'get', ['email' => static::$civicrm_contact_1['email']]);
    $this->assertEquals(0, $result['count']);
    // Check we do have the new email, once.
    $result = civicrm_api3('Email', 'getsingle', ['email' => $new_email]);

  }
  /**
   * Test the 'upemail' webhook adds a new bulk email if current email is not
   * bulk.
   *
   * Un-set the bulk status on the fixture contact's only email. The webhook
   * should then leave that one alone and create a 2nd, bulk email.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testWebhookUpemailCreatesBulk() {
    $this->joinMembershipGroup(static::$civicrm_contact_1, TRUE);
    $api_prophecy = $this->prepMockForWebhookConfig();
    $w = new CRM_Mailchimp_Page_WebHook();
    $new_email = 'new-' . static::$civicrm_contact_1['email'];

    // Remove bulk flag.
    $result = civicrm_api3('Email', 'getsingle', ['email' => static::$civicrm_contact_1['email']]);
    $result = civicrm_api3('Email', 'create', [
      'id' => $result['id'],
      'contact_id' => $result['contact_id'],
      // Note without passing the email, CiviCRM will merrily delete the email
      // rather than just updating the existing record. Hmmm. Thanks.
      'email' => static::$civicrm_contact_1['email'],
      'is_bulkmail' => FALSE
    ]);

    list($code, $response) = $w->processRequest('key', 'key', [
      'type' => 'upemail',
      'data' => [
        'list_id' => 'dummylistid',
        'new_email' => $new_email,
        'old_email' => static::$civicrm_contact_1['email'],
      ]]);
    $this->assertEquals(200, $code);

    // Check we still have the original email.
    $result = civicrm_api3('Email', 'getsingle', ['email' => static::$civicrm_contact_1['email']]);
    // Check we also have the new email.
    $result = civicrm_api3('Email', 'getsingle', ['email' => $new_email]);
    // Ensure the new email was given to the right contact.
    $this->assertEquals(static::$civicrm_contact_1['contact_id'], $result['contact_id']);
    // Ensure the new email was set to bulk.
    $this->assertEquals(1, $result['is_bulkmail']);

  }
  /**
   * Test the 'upemail' webhook changes existing bulk email.
   *
   * Give contact 1 a different Primary email address.
   * The bulk one should be updated.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testWebhookUpemailChangesBulk() {
    $this->joinMembershipGroup(static::$civicrm_contact_1, TRUE);
    $api_prophecy = $this->prepMockForWebhookConfig();
    $w = new CRM_Mailchimp_Page_WebHook();
    $new_email = 'new-' . static::$civicrm_contact_1['email'];

    // Create a 2nd email on contact 1, as the primary.
    civicrm_api3('Email', 'create', [
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      // Use the email from 2nd test contact.
      'email' => static::$civicrm_contact_2['email'],
      'is_bulkmail' => 0,
      'is_primary' => 1,
    ]);
    // Check that worked - CiviCRM's API should have removed the is_primary flag
    // from the original email.
    $bulk_email_record = civicrm_api3('Email', 'getsingle', ['email' => static::$civicrm_contact_1['email']]);
    $this->assertEquals(0, $bulk_email_record['is_primary']);
    $this->assertEquals(1, $bulk_email_record['is_bulkmail']);

    list($code, $response) = $w->processRequest('key', 'key', [
      'type' => 'upemail',
      'data' => [
        'list_id' => 'dummylistid',
        'new_email' => $new_email,
        'old_email' => $bulk_email_record['email'],
      ]]);
    $this->assertEquals(200, $code);

    // Check we still have the primary email we added.
    $result = civicrm_api3('Email', 'getsingle', ['email' => static::$civicrm_contact_2['email'],
      'contact_id' => static::$civicrm_contact_1['contact_id'],
    ]);
    // Check we also have the new email.
    $result = civicrm_api3('Email', 'getsingle', ['email' => $new_email]);
    // Ensure the new email is still on the right contact.
    $this->assertEquals(static::$civicrm_contact_1['contact_id'], $result['contact_id']);
    // Ensure the new email is still set to bulk.
    $this->assertEquals(1, $result['is_bulkmail']);

  }
  /**
   * Test the 'upemail' webhook only changes emails of subscribed contacts.
   *
   * @expectedException RuntimeException
   * @expectedExceptionCode 200
   * @expectedExceptionMessage Contact unknown
   * @depends testGetMCInterestGroupings
   */
  public function testWebhookUpemailOnlyChangesSubscribedContacts() {
    $api_prophecy = $this->prepMockForWebhookConfig();
    $w = new CRM_Mailchimp_Page_WebHook();

    $new_email = 'new-' . static::$civicrm_contact_1['email'];
    list($code, $response) = $w->processRequest('key', 'key', [
      'type' => 'upemail',
      'data' => [
        'list_id' => 'dummylistid',
        'new_email' => $new_email,
        'old_email' => static::$civicrm_contact_1['email'],
      ]]);
    $this->assertEquals(200, $code);
  }
  /**
   * Test the 'upemail' webhook fails if the old email cannot be found.
   *
   * @expectedException RuntimeException
   * @expectedExceptionCode 200
   * @expectedExceptionMessage Contact unknown
   * @depends testGetMCInterestGroupings
   */
  public function testWebhookUpemailFailsIfEmailNotFound() {
    $this->joinMembershipGroup(static::$civicrm_contact_1, TRUE);
    $api_prophecy = $this->prepMockForWebhookConfig();
    $w = new CRM_Mailchimp_Page_WebHook();

    list($code, $response) = $w->processRequest('key', 'key', [
      'type' => 'upemail',
      'data' => [
        'list_id' => 'dummylistid',
        'new_email' => static::$civicrm_contact_1['email'],
        'old_email' => 'different-' . static::$civicrm_contact_1['email'],
      ]]);
  }
  /**
   * Test the 'unsubscribe' webhook works for editing an existing contact.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testWebhookUnsubscribeExistingContact() {

    static::joinMembershipGroup(static::$civicrm_contact_1, TRUE);

    $api_prophecy = $this->prepMockForWebhookConfig();
    $w = new CRM_Mailchimp_Page_WebHook();
    list($code, $response) = $w->processRequest('key', 'key', [
      'type' => 'unsubscribe',
      'data' => [
        'list_id' => 'dummylistid',
        'merges' => [
          'FNAME' => static::$civicrm_contact_1['first_name'],
          'LNAME' => static::$civicrm_contact_1['last_name'],
          ],
        'email' => static::$civicrm_contact_1['email'],
      ]]);
    $this->assertEquals(200, $code);
    $this->assertContactIsNotInGroup(
      static::$civicrm_contact_1['contact_id'],
      static::$civicrm_group_id_membership,
      "Contact was not correctly removed from CiviCRM membership group");
  }
  /**
   * Test the 'unsubscribe' webhook does nothing for unknown emails.
   *
   * Contact is not in group by default, so this should do nothing.
   * We're really just testing that no exceptions are thrown.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testWebhookUnsubscribeForUnknownContact() {

    $api_prophecy = $this->prepMockForWebhookConfig();
    $w = new CRM_Mailchimp_Page_WebHook();
    list($code, $response) = $w->processRequest('key', 'key', [
      'type' => 'unsubscribe',
      'data' => [
        'list_id' => 'dummylistid',
        'merges' => [
          'FNAME' => static::$civicrm_contact_1['first_name'],
          'LNAME' => static::$civicrm_contact_1['last_name'],
          ],
        'email' => static::$civicrm_contact_1['email'],
      ]]);
    $this->assertEquals(200, $code);

  }
  /**
   * Sets a mock Mailchimp API that will pass the webhook is configured
   * correctly test.
   *
   * This code is used in many methods.
   *
   * @return Prophecy.
   */
  protected function prepMockForWebhookConfig() {
    // Make mock API that will return a webhook with the sources.API setting
    // set, which is wrong.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $url = CRM_Mailchimp_Utils::getWebhookUrl();
    $api_prophecy->get("/lists/dummylistid/webhooks", Argument::any())
      ->shouldBeCalled()
      ->willReturn(json_decode('{"http_code":200,"data":{"webhooks":[{"url":"' . $url . '","sources":{"api":false}}]}}'));
    return $api_prophecy;
  }
}
