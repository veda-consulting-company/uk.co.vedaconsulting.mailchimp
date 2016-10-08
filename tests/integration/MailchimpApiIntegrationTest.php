<?php
/**
 * @file
 * Tests that the systems work together as expected.
 *
 */

require 'integration-test-bootstrap.php';

class MailchimpApiIntegrationTest extends CRM_Mailchimp_IntegrationTestBase {
  /**
   * Connect to API and create test fixtures in Mailchimp and CiviCRM.
   */
  public static function setUpBeforeClass() {
    $api = CRM_Mailchimp_Utils::getMailchimpApi(TRUE);
    //$api->setLogFacility(function($m){print $m;});
    $api->setLogFacility(function($m){CRM_Core_Error::debug_log_message($m, FALSE, 'mailchimp');});
    static::createMailchimpFixtures();
  }
  /**
   * Runs before every test.
   */
  public function setUp() {
    // Ensure CiviCRM fixtures present.
    static::createCiviCrmFixtures();
  }
  /**
   * Remove the test list, if one was successfully set up.
   */
  public static function tearDownAfterClass() {
    static::tearDownCiviCrmFixtures();
    static::tearDownMailchimpFixtures();
    CRM_Mailchimp_Utils::resetAllCaches();
  }
  /**
   * This is run before every test method.
   */
  public function assertPreConditions() {
    $this->assertEquals(200, static::$api_contactable->http_code);
    $this->assertTrue(!empty(static::$api_contactable->data->account_name), "Expected account_name to be returned.");
    $this->assertTrue(!empty(static::$api_contactable->data->email), "Expected email belonging to the account to be returned.");

    $this->assertNotEmpty(static::$test_list_id);
    $this->assertInternalType('string', static::$test_list_id);
    $this->assertGreaterThan(0, static::$civicrm_contact_1['contact_id']);
    $this->assertGreaterThan(0, static::$civicrm_contact_2['contact_id']);

    foreach ([static::$civicrm_contact_1, static::$civicrm_contact_2] as $contact) {
      $this->assertGreaterThan(0, $contact['contact_id']);
      $this->assertNotEmpty($contact['email']);
      $this->assertNotEmpty($contact['subscriber_hash']);
      // Ensure one and only one contact exists with each of our test emails.
      civicrm_api3('Contact', 'getsingle', ['email' => $contact['email']]);
    }
  }

  /**
   * Reset the fixture to the new state.
   *
   * This means neither CiviCRM contact has any group records;
   * Mailchimp test list is empty.
   */
  public function tearDown() {

    // Delete all GroupContact records on our test contacts to test groups.
    $api = CRM_Mailchimp_Utils::getMailchimpApi();
    $contacts = array_filter([static::$civicrm_contact_1, static::$civicrm_contact_2],
      function($_) { return $_['contact_id']>0; });

    // Ensure list is empty.
    $list_id = static::$test_list_id;
    $url_prefix = "/lists/$list_id/members/";
    foreach ($contacts as $contact) {
      if ($contact['subscriber_hash']) {
        try {
          $api->delete($url_prefix . $contact['subscriber_hash']);
        }
        catch (CRM_Mailchimp_RequestErrorException $e) {
          if (!$e->response || $e->response->http_code != 404) {
            throw $e;
          }
          // Contact not subscribed; fine.
        }
      }
    }
    // Check it really is empty.
    $this->assertEquals(0, $api->get("/lists/$list_id", ['fields' => 'stats.member_count'])->data->stats->member_count);

    // Delete and reset our contacts.
    $this->tearDownCiviCrmFixtures();
    return;
    foreach ($contacts as $contact) {
      foreach ([static::$civicrm_group_id_membership, static::$civicrm_group_id_interest_1, static::$civicrm_group_id_interest_2] as $group_id) {
        $this->deleteGroup($contact, $group_id, TRUE);
        // Ensure name is as it should be as some tests change this.
        civicrm_api3('Contact', 'create', [
          'contact_id' => $contact['contact_id'],
          'first_name' => $contact['first_name'],
          'last_name' =>  $contact['last_name'],
          ]);
      }
    }
  }

  /**
   * Basic test of using the batchAndWait.
   *
   * Just should not throw anything. Tests that the round-trip of submitting a
   * batch request to MC, receiving a job id and polling it until finished is
   * working. For sanity's sake, really!
   *
   * The MC calls do not depend on any fixtures and should work with any
   * Mailchimp account.
   *
   * @group basics
   */
  public function testBatch() {

    $api = CRM_Mailchimp_Utils::getMailchimpApi();

    try {
      $result = $api->batchAndWait([
        ['get', "/lists"],
        ['get', "/campaigns/", ['count'=>10]],
      ]);
    }
    catch (CRM_Mailchimp_Exception $e) {
      // Spit out request and response for debugging.
      print "Request:\n";
      print_r($e->request);
      print "Response:\n";
      print_r($e->response);
      // re-throw exception.
      throw $e;
    }
  }

  /**
   * Test that we can connect to the API and retrieve lists.
   *
   * @group basics
   */
  public function testLists() {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();

    // Check we can access lists, that there is at least one list.
    $result = $api->get('/lists');
    $this->assertEquals(200, $result->http_code);
    $this->assertTrue(isset($result->data->lists));
    $this->assertInternalType('array', $result->data->lists);
  }
  /**
   * Check that requesting something that's no there throws the right exception
   *
   * @expectedException CRM_Mailchimp_RequestErrorException
   * @group basics
   */
  public function test404() {
    CRM_Mailchimp_Utils::getMailchimpApi()->get('/lists/thisisnotavalidlisthash');
  }


  /**
   * Starting with an empty MC list and one person on the CiviCRM mailchimp
   * group, a push should subscribe the person.
   *
   * @group push
   */
  public function testPushAddsNewPerson() {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();

    try {

      // Add contact to membership group without telling MC.
      $this->joinMembershipGroup(static::$civicrm_contact_1, TRUE);
      // Check they are definitely in the group.
      $this->assertContactIsInGroup(static::$civicrm_contact_1['contact_id'], static::$civicrm_group_id_membership);

      // Double-check this member is not known at Mailchimp.
      $this->assertContactNotListMember(static::$civicrm_contact_1);
      $sync = new CRM_Mailchimp_Sync(static::$test_list_id);

      // Now trigger a push for this test list.

      // Collect data from CiviCRM.
      // There should be one member.
      $sync->collectCiviCrm('push');
      $this->assertEquals(1, $sync->countCiviCrmMembers());

      // Collect data from Mailchimp.
      // There shouldn't be any members in this list yet.
      $sync->collectMailchimp('push');
      $this->assertEquals(0, $sync->countMailchimpMembers());

      $matches = $sync->matchMailchimpMembersToContacts();
      $this->assertEquals([
        'bySubscribers' => 0,
        'byUniqueEmail' => 0,
        'byNameEmail'   => 0,
        'bySingle'      => 0,
        'totalMatched'  => 0,
        'newContacts'   => 0,
        'failures'      => 0,
        ], $matches);

      // There should not be any in sync records.
      $in_sync = $sync->removeInSync('push');
      $this->assertEquals(0, $in_sync);

      // Check that removals (i.e. someone in Mailchimp but not/no longer in
      // Civi's group) are zero.
      $to_delete = $sync->getEmailsNotInCiviButInMailchimp();
      $this->assertEquals(0, count($to_delete));

      // Run bulk subscribe...
      $stats = $sync->updateMailchimpFromCivi();
      $this->assertEquals(0, $stats['updates']);
      $this->assertEquals(0, $stats['unsubscribes']);
      $this->assertEquals(1, $stats['additions']);

      // Now check they are subscribed.
      $not_found = TRUE;
      $i =0;
      $start = time();
      //print date('Y-m-d H:i:s') . " Mailchimp batch returned 'finished'\n";
      while ($not_found && $i++ < 2*10) {
        try {
          $result = $api->get("/lists/" . static::$test_list_id . "/members/" . static::$civicrm_contact_1['subscriber_hash'], ['fields' => 'status']);
          // print date('Y-m-d H:i:s') . " found now " . round(time() - $start, 2) . "s after Mailchimp reported the batch had finished.\n";
          $not_found = FALSE;
        }
        catch (CRM_Mailchimp_RequestErrorException $e) {
          if ($e->response->http_code == 404) {
            // print date('Y-m-d H:i:s') . " not found yet\n";
            sleep(10);
          }
          else {
            throw $e;
          }
        }
      }
    }
    catch (CRM_Mailchimp_Exception $e) {
      // Spit out request and response for debugging.
      print "Request:\n";
      print_r($e->request);
      print "Response:\n";
      print_r($e->response);
      // re-throw exception.
      throw $e;
    }
  }

  /**
   * Test push updates a record that changed in CiviCRM.
   *
   * 1. Test that a changed name is recognised as needing an update:
   *
   * 2. Test that a changed interest also triggers an update being needed.
   *
   * 3. Test that these changes and adding a new contact are all achieved by a
   *    push operation.
   *
   * Note: there are loads of possible cases for updates because of the
   * number of variables (new/existing contact), (changes/no changes/no changes
   * because it would delete data), (change on firstname/lastname/interests...)
   *
   * But the logic for these - what data results in what updates - is done in a
   * uinttest for the CRM_Mailchimp_Sync class, so here we focus on checking the
   * code that compares data in the collection tables works.
   *
   * @group push
   */
  public function testPushChangedName() {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();
    $this->assertNotEmpty(static::$civicrm_contact_1['contact_id']);

    try {
      // Add contact1, to the membership group, allowing the posthook to also
      // subscribe them.
      // This will be the changes test.
      $this->joinMembershipGroup(static::$civicrm_contact_1);

      // Now make some local changes, without telling Mailchimp...
      // Add contact2 to the membership group, locally only.
      // This will be the addition test.
      $this->joinMembershipGroup(static::$civicrm_contact_2, TRUE);
      // Change the first name of our test record locally only.
      civicrm_api3('Contact', 'create', [
        'contact_id' => static::$civicrm_contact_1['contact_id'],
        'first_name' => 'Betty',
        ]);

      $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
      // Are the changes noted?
      $sync->collectCiviCrm('push');
      $this->assertEquals(2, $sync->countCiviCrmMembers());
      // Collect from Mailchimp.
      $sync->collectMailchimp('push');
      $this->assertEquals(1, $sync->countMailchimpMembers());

      $matches = $sync->matchMailchimpMembersToContacts();
      $this->assertEquals([
        'bySubscribers' => 1,
        'byUniqueEmail' => 0,
        'byNameEmail'   => 0,
        'bySingle'      => 0,
        'totalMatched'  => 1,
        'newContacts'   => 0,
        'failures'      => 0,
        ], $matches);

      // As the records are not in sync, none should get deleted.
      $in_sync = $sync->removeInSync('push');
      $this->assertEquals(0, $in_sync);

      // We don't need to do the actual updateMailchimpFromCivi() call
      // yet because we want to test some other stuff first...

      // Now change name back so we can test only an interest change.
      civicrm_api3('Contact', 'create', [
        'contact_id' => static::$civicrm_contact_1['contact_id'],
        'first_name' => static::$civicrm_contact_1['first_name'],
        ]);
      // Add the interest group locally only.
      $this->joinGroup(static::$civicrm_contact_1, static::$civicrm_group_id_interest_1, TRUE);

      // Is a changed interest group spotted?
      // re-collect the CiviCRM data and check it's still 2 records.
      $sync->collectCiviCrm('push');
      $this->assertEquals(2, $sync->countCiviCrmMembers());
      // re-collect from Mailchimp (although nothing has changed here we must do
      // this so that the matchMailchimpMembersToContacts can work.
      $sync->collectMailchimp('push');

      $matches = $sync->matchMailchimpMembersToContacts();
      $this->assertEquals([
        'bySubscribers' => 1, // xxx
        'byUniqueEmail' => 0,
        'byNameEmail'   => 0,
        'bySingle'      => 0,
        'totalMatched'  => 1,
        'newContacts'   => 0,
        'failures'      => 0,
        ], $matches);

      // As the records are not in sync, none should get deleted.
      $in_sync = $sync->removeInSync('push');
      $this->assertEquals(0, $in_sync);

      // Again, we don't yet call updateMailchimpFromCivi() as we do the final
      // test.

      //
      // Test 3: Change name back to Betty again, add new contact to membership
      // group and check updates work.
      //

      // Change the name again as this is another thing we can test gets updated
      // correctly.
      civicrm_api3('Contact', 'create', [
        'contact_id' => static::$civicrm_contact_1['contact_id'],
        'first_name' => 'Betty',
        ]);

      // Now collect Civi again.
      $sync->collectCiviCrm('push');
      $this->assertEquals(2, $sync->countCiviCrmMembers());
      // re-collect from Mailchimp (although nothing has changed here we must do
      // this so that the matchMailchimpMembersToContacts can work.
      $sync->collectMailchimp('push');

      $matches = $sync->matchMailchimpMembersToContacts();
      $this->assertEquals([
        'bySubscribers' => 1,
        'byUniqueEmail' => 0,
        'byNameEmail'   => 0,
        'bySingle'      => 0,
        'totalMatched'  => 1,
        'newContacts'   => 0,
        'failures'      => 0,
        ], $matches);

      // No records in sync, check this.
      $in_sync = $sync->removeInSync('push');
      $this->assertEquals(0, $in_sync);

      // Send updates to Mailchimp.
      $stats = $sync->updateMailchimpFromCivi();
      $this->assertEquals(0, $stats['unsubscribes']);
      $this->assertEquals(1, $stats['updates']);
      $this->assertEquals(1, $stats['additions']);

      // Now re-collect from Mailchimp and check all are in sync.
      $sync->collectMailchimp('push');
      $this->assertEquals(2, $sync->countMailchimpMembers());

      $matches = $sync->matchMailchimpMembersToContacts();
      $this->assertEquals([
        'bySubscribers' => 2,
        'byUniqueEmail' => 0,
        'byNameEmail'   => 0,
        'bySingle'      => 0,
        'totalMatched'  => 2,
        'newContacts'   => 0,
        'failures'      => 0,
        ], $matches);

      // Verify that they are in deed all in sync:
      $in_sync = $sync->removeInSync('push');
      $this->assertEquals(2, $in_sync);

    }
    catch (CRM_Mailchimp_Exception $e) {
      // Spit out request and response for debugging.
      print "Request:\n";
      print_r($e->request);
      print "Response:\n";
      print_r($e->response);
      // re-throw exception.
      throw $e;
    }
  }

  /**
   * Test push unsubscribes contacts and does not update contacts that are not
   * subscribed at CiviCRM.
   *
   * If a contact is not subscribed at CiviCRM their data should not be
   * collected by collectCiviCrm().
   *
   * If this contact is subscribed at Mailchimp, this data will be collected and
   * we should send an unsubscribe request, but we should not bother with any
   * other updates, such as name or interest changes.
   *
   * @group push
   */
  public function testPushUnsubscribes() {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();

    try {
      // Add contact1, to the membership group, allowing the posthook to also
      // subscribe them.
      $this->joinMembershipGroup(static::$civicrm_contact_1);

      // Now make some local changes, without telling Mailchimp...
      // Change the first name of our test record locally only.
      civicrm_api3('Contact', 'create', [
        'contact_id' => static::$civicrm_contact_1['contact_id'],
        'first_name' => 'Betty',
        ]);
      // Add them to an interest group.
      $this->joinGroup(static::$civicrm_contact_1, static::$civicrm_group_id_interest_1, TRUE);
      // Unusbscribe them.
      $this->removeGroup(static::$civicrm_contact_1, static::$civicrm_group_id_membership, TRUE);

      $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
      // Collect data from CiviCRM.
      $sync->collectCiviCrm('push');
      $this->assertEquals(0, $sync->countCiviCrmMembers());
      // Collect from Mailchimp.
      $sync->collectMailchimp('push');
      $this->assertEquals(1, $sync->countMailchimpMembers());

      $matches = $sync->matchMailchimpMembersToContacts();
      $this->assertEquals([
        'bySubscribers' => 0,
        'byUniqueEmail' => 1,
        'byNameEmail'   => 0,
        'bySingle'      => 0,
        'totalMatched'  => 1,
        'newContacts'   => 0,
        'failures'      => 0,
        ], $matches);

      // Are the changes noted? As the records are not in sync, none should get
      // deleted.
      $in_sync = $sync->removeInSync('push');
      $this->assertEquals(0, $in_sync);

      // Send updates to Mailchimp.
      $stats = $sync->updateMailchimpFromCivi();
      $this->assertEquals(0, $stats['updates']);
      $this->assertEquals(1, $stats['unsubscribes']);

      // Check all unsubscribed at Mailchimp.
      $sync->collectMailchimp('push');
      $difficult_matches = $sync->matchMailchimpMembersToContacts();
      $this->assertEquals(0, $sync->countMailchimpMembers());

      // Now fetch member details from Mailchimp.
      $result = $api->get("/lists/" . static::$test_list_id . "/members/" . static::$civicrm_contact_1['subscriber_hash'],
        ['fields' => 'status,merge_fields.FNAME,interests'])->data;

      // They should be unsubscribed.
      $this->assertEquals('unsubscribed', $result->status);
      // They should have the original first name since our change should not
      // have been pushed.
      $this->assertEquals(static::$civicrm_contact_1['first_name'], $result->merge_fields->FNAME);
      // They should not have any interests, since our intersest group addition
      // should not have been pushed.
      foreach ((array) $result->interests as $interested) {
        $this->assertEquals(0, $interested);
      }
    }
    catch (CRM_Mailchimp_Exception $e) {
      // Spit out request and response for debugging.
      print "Request:\n";
      print_r($e->request);
      print "Response:\n";
      print_r($e->response);
      // re-throw exception.
      throw $e;
    }
  }

  /**
   * @throws \CRM_Mailchimp_Exception
   * @throws \Exception
   */
  public function testPushDoesNotUnsubscribeDuplicates() {
    try {
      // Put a contact on MC list, not in CiviCRM, and make dupes in CiviCRM
      // so we can't sync.
      $this->createTitanic();
      // Now sync.
      $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
      // Collect data from CiviCRM - no-one in membership group.
      $sync->collectCiviCrm('push');
      $this->assertEquals(0, $sync->countCiviCrmMembers());
      // Collect from Mailchimp.
      $sync->collectMailchimp('push');
      $this->assertEquals(1, $sync->countMailchimpMembers());

      $matches = $sync->matchMailchimpMembersToContacts();
      $this->assertEquals([
        'bySubscribers' => 0,
        'byUniqueEmail' => 0,
        'byNameEmail'   => 0,
        'bySingle'      => 0,
        'totalMatched'  => 0,
        'newContacts'   => 0,
        'failures'      => 1,
        ], $matches);

      // Nothing is insync.
      $in_sync = $sync->removeInSync('push');
      $this->assertEquals(0, $in_sync);

      // Send updates to Mailchimp - nothing should be updated.
      $stats = $sync->updateMailchimpFromCivi();
      $this->assertEquals(0, $stats['updates']);
      $this->assertEquals(0, $stats['unsubscribes']);
      $this->assertEquals(0, $stats['additions']);
    }
    catch (CRM_Mailchimp_Exception $e) {
      // Spit out request and response for debugging.
      print "Request:\n";
      print_r($e->request);
      print "Response:\n";
      print_r($e->response);
      // re-throw exception.
      throw $e;
    }
  }


  /**
   * Test pull updates a records that changed name in Mailchimp.
   *
   * Test that changing name at Mailchimp changes name in CiviCRM.
   * But does not overwrite a CiviCRM name with a blank from Mailchimp.
   *
   * @group pull
   */
  public function testPullChangesName() {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();
    $this->assertNotEmpty(static::$civicrm_contact_1['contact_id']);

    try {
      $this->joinMembershipGroup(static::$civicrm_contact_1);
      $this->joinMembershipGroup(static::$civicrm_contact_2);
      // Change name at Mailchimp to Betty (is Wilma)
      $this->assertNotEmpty(static::$civicrm_contact_1['subscriber_hash']);
      $result = $api->patch('/lists/' . static::$test_list_id . '/members/' . static::$civicrm_contact_1['subscriber_hash'],
        ['merge_fields' => ['FNAME' => 'Betty']]);
      $this->assertEquals(200, $result->http_code);

      // Change last name of contact 2 at Mailchimp to blank.
      $result = $api->patch('/lists/' . static::$test_list_id . '/members/' . static::$civicrm_contact_2['subscriber_hash'],
        ['merge_fields' => ['LNAME' => '']]);
      $this->assertEquals(200, $result->http_code);

      // Collect data from Mailchimp and CiviCRM.
      $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
      $sync->collectCiviCrm('pull');
      $sync->collectMailchimp('pull');

      $matches = $sync->matchMailchimpMembersToContacts();
      $this->assertEquals([
        'bySubscribers' => 2,
        'byUniqueEmail' => 0,
        'byNameEmail'   => 0,
        'bySingle'      => 0,
        'totalMatched'  => 2,
        'newContacts'   => 0,
        'failures'      => 0,
        ], $matches);

      // Remove in-sync things (both have changed, should be zero)
      $in_sync = $sync->removeInSync('pull');
      $this->assertEquals(0, $in_sync);

      // Make changes in Civi.
      $stats = $sync->updateCiviFromMailchimp();
      $this->assertEquals([
        'created' => 0,
        'joined'  => 0,
        'in_sync' => 2, // both are in the membership group.
        'removed' => 0,
        'updated' => 1, // only one contact should be changed.
        ], $stats);

      // Ensure the updated name for contact 1 is pulled from Mailchimp to Civi.
      civicrm_api3('Contact', 'getsingle', [
        'contact_id' => static::$civicrm_contact_1['contact_id'],
        'first_name' => 'Betty',
        ]);

      // Ensure change was NOT made; contact 2 should still have same surname.
      civicrm_api3('Contact', 'getsingle', [
        'contact_id' => static::$civicrm_contact_2['contact_id'],
        'last_name' => static::$civicrm_contact_2['last_name'],
        ]);

      CRM_Mailchimp_Sync::dropTemporaryTables();
    }
    catch (CRM_Mailchimp_Exception $e) {
      // Spit out request and response for debugging.
      print "Request:\n";
      print_r($e->request);
      print "Response:\n";
      print_r($e->response);
      // re-throw exception.
      throw $e;
    }
  }

  /**
   * Test pull updates groups from interests in CiviCRM.
   *
   * @group pull
   */
  public function testPullChangesInterests() {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();

    try {
      // Add contact 1 to interest1, then subscribe contact 1.
      $this->joinGroup(static::$civicrm_contact_1, static::$civicrm_group_id_interest_1, TRUE);
      $this->joinMembershipGroup(static::$civicrm_contact_1);

      // Change interests at Mailchimp: de-select interest1 and add interest2.
      $result = $api->patch('/lists/' . static::$test_list_id . '/members/' . static::$civicrm_contact_1['subscriber_hash'],
        ['interests' => [
          static::$test_interest_id_1 => FALSE,
          static::$test_interest_id_2 => TRUE,
        ]]);
      $this->assertEquals(200, $result->http_code);

      // Collect data from Mailchimp and CiviCRM.
      $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
      $sync->collectCiviCrm('pull');
      $sync->collectMailchimp('pull');

      $matches = $sync->matchMailchimpMembersToContacts();
      $this->assertEquals([
        'bySubscribers' => 1,
        'byUniqueEmail' => 0,
        'byNameEmail'   => 0,
        'bySingle'      => 0,
        'totalMatched'  => 1,
        'newContacts'   => 0,
        'failures'      => 0,
        ], $matches);

      // Remove in-sync things (both have changed, should be zero)
      $in_sync = $sync->removeInSync('pull');
      $this->assertEquals(0, $in_sync);

      // Make changes in Civi.
      $stats = $sync->updateCiviFromMailchimp();
      $this->assertEquals([
        'created' => 0,
        'joined'  => 0,
        'in_sync' => 1,
        'removed' => 0,
        'updated' => 1,
        ], $stats);

      $this->assertContactIsNotInGroup(static::$civicrm_contact_1['contact_id'], static::$civicrm_group_id_interest_1);
      $this->assertContactIsInGroup(static::$civicrm_contact_1['contact_id'], static::$civicrm_group_id_interest_2);

      CRM_Mailchimp_Sync::dropTemporaryTables();
    }
    catch (CRM_Mailchimp_Exception $e) {
      // Spit out request and response for debugging.
      print "Request:\n";
      print_r($e->request);
      print "Response:\n";
      print_r($e->response);
      // re-throw exception.
      throw $e;
    }
  }

  /**
   * Test pull does not update groups from interests not configured to allow
   * this.
   *
   * @group pull
   */
  public function testPullChangesNonPullInterests() {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();

    try {
      // Alter the group to remove the permission for Mailchimp to update
      // CiviCRM.
      $result = civicrm_api3('Group', 'create', [
        'id' => static::$civicrm_group_id_interest_1,
        static::$custom_is_mc_update_grouping => 0
      ]);

      // Add contact 1 to interest1, then subscribe contact 1.
      $this->joinGroup(static::$civicrm_contact_1, static::$civicrm_group_id_interest_1, TRUE);
      $this->joinMembershipGroup(static::$civicrm_contact_1);

      // Change interests at Mailchimp: de-select interest1
      $result = $api->patch('/lists/' . static::$test_list_id . '/members/' . static::$civicrm_contact_1['subscriber_hash'],
        ['interests' => [static::$test_interest_id_1 => FALSE]]);
      $this->assertEquals(200, $result->http_code);

      // Collect data from Mailchimp and CiviCRM.
      $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
      $sync->collectCiviCrm('pull');
      $sync->collectMailchimp('pull');

      $matches = $sync->matchMailchimpMembersToContacts();
      $this->assertEquals([
        'bySubscribers' => 1,
        'byUniqueEmail' => 0,
        'byNameEmail'   => 0,
        'bySingle'      => 0,
        'totalMatched'  => 1,
        'newContacts'   => 0,
        'failures'      => 0,
        ], $matches);

      // Remove in-sync things - should be 1 because except for this change
      // we're not allowed to change, nothing has changed.
      $in_sync = $sync->removeInSync('pull');
      $this->assertEquals(1, $in_sync);

      CRM_Mailchimp_Sync::dropTemporaryTables();
    }
    catch (CRM_Mailchimp_Exception $e) {
      // Spit out request and response for debugging.
      print "Request:\n";
      print_r($e->request);
      print "Response:\n";
      print_r($e->response);
      // re-throw exception.
      throw $e;
    }
  }

  /**
   * Test new mailchimp contacts added to CiviCRM.
   *
   * Add contact1 and subscribe, then delete contact 1 from CiviCRM, then do a
   * pull. This should result in contact 1 being re-created with all their
   * details.
   *
   * WARNING if this test fails at a particular place it messes up the fixture,
   * but that's unlikely.
   *
   * @group pull
   *
   */
  public function testPullAddsContact() {

    // Give contact 1 an interest.
    $this->joinGroup(static::$civicrm_contact_1, static::$civicrm_group_id_interest_1, TRUE);
    // Add contact 1 to membership group thus subscribing them at Mailchimp.
    $this->joinMembershipGroup(static::$civicrm_contact_1);

    // Delete contact1 from CiviCRM
    // We have to ensure no post hooks are fired, so we disable the API.
    CRM_Mailchimp_Utils::$post_hook_enabled = FALSE;
    $result = civicrm_api3('Contact', 'delete', ['id' => static::$civicrm_contact_1['contact_id'], 'skip_undelete' => 1]);
    static::$civicrm_contact_1['contact_id'] = 0;
    CRM_Mailchimp_Utils::$post_hook_enabled = TRUE;

    try {
      // Collect data from Mailchimp and CiviCRM.
      $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
      $sync->collectCiviCrm('pull');
      $sync->collectMailchimp('pull');

      $matches = $sync->matchMailchimpMembersToContacts();
      $this->assertEquals([
        'bySubscribers' => 0,
        'byUniqueEmail' => 0,
        'byNameEmail'   => 0,
        'bySingle'      => 0,
        'totalMatched'  => 0,
        'newContacts'   => 1,
        'failures'      => 0,
        ], $matches);

      // Remove in-sync things (nothing should be in sync)
      $in_sync = $sync->removeInSync('pull');
      $this->assertEquals(0, $in_sync);

      // Make changes in Civi.
      $stats = $sync->updateCiviFromMailchimp();
      $this->assertEquals([
        'created' => 1,
        'joined'  => 0,
        'in_sync' => 0,
        'removed' => 0,
        'updated' => 0,
        ], $stats);

      // Ensure expected change was made.
      $result = civicrm_api3('Contact', 'getsingle', [
        'email' => static::$civicrm_contact_1['email'],
        'first_name' => static::$civicrm_contact_1['first_name'],
        'last_name' => static::$civicrm_contact_1['last_name'],
        'return' => 'group',
        ]);
      // If that didn't throw an exception, the contact was created.
      // Store the new contact id in the fixture to enable clearup.
      static::$civicrm_contact_1['contact_id'] = (int) $result['contact_id'];
      // Check they're in the membership group.
      $in_groups = CRM_Mailchimp_Utils::getGroupIds($result['groups'], $sync->group_details);
      $this->assertContains(static::$civicrm_group_id_membership, $in_groups, "New contact was not in membership group, but should be.");
      $this->assertContains(static::$civicrm_group_id_interest_1, $in_groups, "New contact was not in interest group 1, but should be.");
    }
    catch (CRM_Mailchimp_Exception $e) {
      // Spit out request and response for debugging.
      print "Request:\n";
      print_r($e->request);
      print "Response:\n";
      print_r($e->response);
      // re-throw exception.
      throw $e;
    }
  }

  /**
   * Test unsubscribed/missing mailchimp contacts are removed from CiviCRM
   * membership group.
   *
   * Update contact 1 at mailchimp to unsubscribed.
   * Delete contact 2 at mailchimp.
   * Run pull.
   * Both contacts should be 'removed' from CiviCRM group.
   *
   * @group pull
   */
  public function testPullRemovesContacts() {

    try {
      $this->joinMembershipGroup(static::$civicrm_contact_1);
      $this->joinMembershipGroup(static::$civicrm_contact_2);

      // Update contact 1 at Mailchimp to unsubscribed.
      $api = CRM_Mailchimp_Utils::getMailchimpApi();
      $result = $api->patch('/lists/' . static::$test_list_id . '/members/' . static::$civicrm_contact_1['subscriber_hash'],
          ['status' => 'unsubscribed']);
      $this->assertEquals(200, $result->http_code);

      // Delete contact 2 from Mailchimp completely.
      $result = $api->delete('/lists/' . static::$test_list_id . '/members/' . static::$civicrm_contact_2['subscriber_hash']);
      $this->assertEquals(204, $result->http_code);

      // Collect data from Mailchimp and CiviCRM.
      $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
      // Both contacts should still be subscribed according to CiviCRM.
      $sync->collectCiviCrm('pull');
      $this->assertEquals(2, $sync->countCiviCrmMembers());
      // Nothing should be subscribed at Mailchimp.
      $sync->collectMailchimp('pull');
      $this->assertEquals(0, $sync->countMailchimpMembers());

      $matches = $sync->matchMailchimpMembersToContacts();
      $this->assertEquals([
        'bySubscribers' => 0,
        'byUniqueEmail' => 0,
        'byNameEmail'   => 0,
        'bySingle'      => 0,
        'totalMatched'  => 0,
        'newContacts'   => 0,
        'failures'      => 0,
        ], $matches);

      // Remove in-sync things (nothing is in sync)
      $in_sync = $sync->removeInSync('pull');
      $this->assertEquals(0, $in_sync);

      // Make changes in Civi.
      $stats = $sync->updateCiviFromMailchimp();
      $this->assertEquals([
        'created' => 0,
        'joined'  => 0,
        'in_sync' => 0,
        'removed' => 2,
        'updated' => 0,
        ], $stats);

      // Each contact should now be removed from the group.
      $this->assertContactIsNotInGroup(static::$civicrm_contact_1['contact_id'], static::$civicrm_group_id_membership);
      $this->assertContactIsNotInGroup(static::$civicrm_contact_2['contact_id'], static::$civicrm_group_id_membership);

    }
    catch (CRM_Mailchimp_Exception $e) {
      // Spit out request and response for debugging.
      print "Request:\n";
      print_r($e->request);
      print "Response:\n";
      print_r($e->response);
      // re-throw exception.
      throw $e;
    }
  }

  /**
   * Contact at mailchimp subscribed with alternative email, known to us.
   *
   * Put contact 1 in group and subscribe.
   * Add a different bulk email to contact 1
   * Do a pull.
   *
   * Expect no changes.
   *
   * @group pull
   */
  public function testPullContactWithOtherEmailInSync() {

    try {
      $this->joinMembershipGroup(static::$civicrm_contact_1);
      // Give contact 1 a new, additional bulk email.
      civicrm_api3('Email', 'create', [
        'contact_id' => static::$civicrm_contact_1['contact_id'],
        'email' => 'new-' . static::$civicrm_contact_1['email'],
        'is_bulkmail' => 1,
        ]);

      // Collect data from Mailchimp and CiviCRM.
      $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
      $sync->collectCiviCrm('pull');
      $this->assertEquals(1, $sync->countCiviCrmMembers());
      $sync->collectMailchimp('pull');
      $this->assertEquals(1, $sync->countMailchimpMembers());
      $matches = $sync->matchMailchimpMembersToContacts();
      $this->assertEquals([
        'bySubscribers' => 0, // Should not match; emails different.
        'byUniqueEmail' => 1, // email at MC only belongs to c1
        'byNameEmail'   => 0,
        'bySingle'      => 0,
        'totalMatched'  => 1,
        'newContacts'   => 0,
        'failures'      => 0,
        ], $matches);

      // Remove in-sync things these two should be in-sync.
      $in_sync = $sync->removeInSync('pull');
      $this->assertEquals(1, $in_sync);
    }
    catch (CRM_Mailchimp_Exception $e) {
      // Spit out request and response for debugging.
      print "Request:\n";
      print_r($e->request);
      print "Response:\n";
      print_r($e->response);
      // re-throw exception.
      throw $e;
    }
  }

  /**
   * Contact at mailchimp subscribed with alternative email, known to us and has
   * name differences.
   *
   * Put contact 1 in group and subscribe.
   * Add a different bulk email to contact 1
   * Do a pull.
   *
   * Expect no changes.
   *
   * @group pull
   */
  public function testPullContactWithOtherEmailDiff() {

    try {
      $this->joinMembershipGroup(static::$civicrm_contact_1);
      // Give contact 1 a new, additional bulk email.
      civicrm_api3('Email', 'create', [
        'contact_id' => static::$civicrm_contact_1['contact_id'],
        'email' => 'new-' . static::$civicrm_contact_1['email'],
        'is_bulkmail' => 1,
        ]);
      // Update our name.
      civicrm_api3('Contact', 'create',[
        'contact_id' => static::$civicrm_contact_1['contact_id'],
        'first_name' => 'Betty',
        ]);

      // Collect data from Mailchimp and CiviCRM.
      $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
      $sync->collectCiviCrm('pull');
      $this->assertEquals(1, $sync->countCiviCrmMembers());
      $sync->collectMailchimp('pull');
      $this->assertEquals(1, $sync->countMailchimpMembers());

      $matches = $sync->matchMailchimpMembersToContacts();
      $this->assertEquals([
        'bySubscribers' => 0, // Should not match; emails different.
        'byUniqueEmail' => 1, // email at MC only belongs to c1
        'byNameEmail'   => 0,
        'bySingle'      => 0,
        'totalMatched'  => 1,
        'newContacts'   => 0,
        'failures'      => 0,
        ], $matches);

      // Remove in-sync things - they are not in sync.
      $in_sync = $sync->removeInSync('pull');
      $this->assertEquals(0, $in_sync);

      // Make changes in Civi.
      $stats = $sync->updateCiviFromMailchimp();
      $this->assertEquals([
        'created' => 0,
        'joined'  => 0,
        'in_sync' => 1, // Contact should be recognised as in group.
        'removed' => 0,
        'updated' => 1, // Name should be updated.
        ], $stats);

      // Check first name was changed back to the original, last name unchanged.
      $this->assertContactName(static::$civicrm_contact_1,
        static::$civicrm_contact_1['first_name'],
        static::$civicrm_contact_1['last_name']);
      // Check contact is (still) in membership group.
      $this->assertContactIsInGroup(static::$civicrm_contact_1['contact_id'], static::$civicrm_group_id_membership);
    }
    catch (CRM_Mailchimp_Exception $e) {
      // Spit out request and response for debugging.
      print "Request:\n";
      print_r($e->request);
      print "Response:\n";
      print_r($e->response);
      // re-throw exception.
      throw $e;
    }
  }

  /**
   *
   */
  public function testPullIgnoresDuplicates() {
    try {
      $this->createTitanic();

      // Now pull sync.
      $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
      // Collect data from CiviCRM.
      $sync->collectCiviCrm('pull');
      $this->assertEquals(0, $sync->countCiviCrmMembers());
      // Collect from Mailchimp.
      $sync->collectMailchimp('pull');
      $this->assertEquals(1, $sync->countMailchimpMembers());
      // Nothing should be matchable.
      $matches = $sync->matchMailchimpMembersToContacts();
      $this->assertEquals([
        'bySubscribers' => 0,
        'byUniqueEmail' => 0,
        'byNameEmail' => 0,
        'bySingle' => 0,
        'totalMatched' => 0,
        'newContacts'   => 0,
        'failures' => 1,
        ], $matches);

      // Nothing is insync.
      $in_sync = $sync->removeInSync('pull');
      $this->assertEquals(0, $in_sync);

      // Update CiviCRM - nothing should be changed.
      $stats = $sync->updateCiviFromMailchimp();
      $this->assertEquals([
        'created' => 0,
        'joined'  => 0,
        'in_sync' => 0, // Contact should be recognised as in group.
        'removed' => 0,
        'updated' => 0, // Name should be updated.
        ], $stats);

    }
    catch (CRM_Mailchimp_Exception $e) {
      // Spit out request and response for debugging.
      print "Request:\n";
      print_r($e->request);
      print "Response:\n";
      print_r($e->response);
      // re-throw exception.
      throw $e;
    }
  }


  /**
   * Check interests are properly mapped as groups are changed and that
   * collectMailchimp and collectCiviCrm work as expected.
   *
   *
   * This uses the posthook, which in turn uses
   * updateMailchimpFromCiviSingleContact.
   *
   * If all is working then at that point both collections should match.
   *
   */
  public function testSyncInterestGroupings() {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();

    try {
      // Add them to the interest group (this should not trigger a Mailchimp
      // update as they are not in thet membership list yet).
      $this->joinGroup(static::$civicrm_contact_1, static::$civicrm_group_id_interest_1);
      // The post hook should subscribe this person and set their interests.
      $this->joinMembershipGroup(static::$civicrm_contact_1);
      // Check their interest group was set.
      $result = $api->get("/lists/" . static::$test_list_id . "/members/" . static::$civicrm_contact_1['subscriber_hash'], ['fields' => 'status,interests'])->data;
      $this->assertEquals((object) [static::$test_interest_id_1 => TRUE, static::$test_interest_id_2 => FALSE], $result->interests);

      // Remove them to the interest group.
      $this->removeGroup(static::$civicrm_contact_1, static::$civicrm_group_id_interest_1);
      // Check their interest group was unset.
      $result = $api->get("/lists/" . static::$test_list_id . "/members/" . static::$civicrm_contact_1['subscriber_hash'], ['fields' => 'status,interests'])->data;
      $this->assertEquals((object) [static::$test_interest_id_1 => FALSE, static::$test_interest_id_2 => FALSE], $result->interests);

      // Add them to the 2nd interest group.
      // While this is a dull test, we assume it works if the other interest
      // group one did, it leaves the fixture with one on and one off which is a
      // good mix for the next test.
      $this->joinGroup(static::$civicrm_contact_1, static::$civicrm_group_id_interest_2);
      // Check their interest group was set.
      $result = $api->get("/lists/" . static::$test_list_id . "/members/" . static::$civicrm_contact_1['subscriber_hash'], ['fields' => 'status,interests'])->data;
      $this->assertEquals((object) [static::$test_interest_id_1 => FALSE, static::$test_interest_id_2 => TRUE], $result->interests);

      // Now check collections work.
      $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
      $sync->collectCiviCrm('push');
      $this->assertEquals(1, $sync->countCiviCrmMembers());
      $sync->collectMailchimp('push');
      $this->assertEquals(1, $sync->countMailchimpMembers());
      $matches = $sync->matchMailchimpMembersToContacts();
      $this->assertEquals([
        'bySubscribers' => 1,
        'byUniqueEmail' => 0,
        'byNameEmail'   => 0,
        'bySingle'      => 0,
        'totalMatched'  => 1,
        'newContacts'   => 0,
        'failures'      => 0,
        ], $matches);

      // This should return 1
      $dao = CRM_Core_DAO::executeQuery("SELECT * FROM tmp_mailchimp_push_m");
      $dao->fetch();
      $mc = [
        'email' => $dao->email,
        'first_name' => $dao->first_name,
        'last_name' => $dao->last_name,
        'interests' => $dao->interests,
        'hash' => $dao->hash,
        'cid_guess' => $dao->cid_guess,
      ];
      $dao = CRM_Core_DAO::executeQuery("SELECT * FROM tmp_mailchimp_push_c");
      $dao->fetch();
      $civi = [
        'email' => $dao->email,
        'email_id' => $dao->email_id,
        'contact_id' => $dao->contact_id,
        'first_name' => $dao->first_name,
        'last_name' => $dao->last_name,
        'interests' => $dao->interests,
        'hash' => $dao->hash,
      ];
      $this->assertEquals($civi['first_name'], $mc['first_name']);
      $this->assertEquals($civi['last_name'], $mc['last_name']);
      $this->assertEquals($civi['email'], $mc['email']);
      $this->assertEquals($civi['interests'], $mc['interests']);
      $this->assertEquals($civi['hash'], $mc['hash']);

      // As the records are in sync, they should be and deleted.
      $in_sync = $sync->removeInSync('push');
      $this->assertEquals(1, $in_sync);

      // Now check the tables are both empty.
      $this->assertEquals(0, $sync->countMailchimpMembers());
      $this->assertEquals(0, $sync->countCiviCrmMembers());
    }
    catch (CRM_Mailchimp_Exception $e) {
      // Spit out request and response for debugging.
      print "Request:\n";
      print_r($e->request);
      print "Response:\n";
      print_r($e->response);
      // re-throw exception.
      throw $e;
    }
  }


  /**
   * Test CiviCRM API function to get mailchimp lists.
   */
  public function xtestCiviCrmApiGetLists() {
    $params = [];
    $lists = civicrm_api3('Mailchimp', 'getlists', $params);
    $a=1;
  }

  /**
   * Check that the contact's email is a member in given state on Mailchimp.
   *
   * @param array $contact e.g. static::$civicrm_contact_1
   * @param string $state Mailchimp member state: 'subscribed', 'unsubscribed', ...
   */
  public function assertContactExistsWithState($contact, $state) {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();
    try {
      $result = $api->get("/lists/" . static::$test_list_id . "/members/$contact[subscriber_hash]", ['fields' => 'status']);
    }
    catch (CRM_Mailchimp_RequestErrorException $e) {
      if ($e->response->http_code == 404) {
        // Not subscribed give more helpful error.
        $this->fail("Expected contact $contact[email] to be in the list at Mailchimp, but MC said resource not found; i.e. not subscribed.");
      }
      throw $e;
    }
    $this->assertEquals($state, $result->data->status);
  }
  /**
   * Check that the contact's email is not a member of the test list at
   * Mailchimp.
   *
   * @param array $contact e.g. static::$civicrm_contact_1
   */
  public function assertContactNotListMember($contact) {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();
    try {
      $subscriber_hash = static::$civicrm_contact_1['subscriber_hash'];
      $result = $api->get("/lists/" . static::$test_list_id . "/members/$contact[subscriber_hash]", ['fields' => 'status']);
    }
    catch (CRM_Mailchimp_RequestErrorException $e) {
      $this->assertEquals(404, $e->response->http_code);
    }
  }
  /**
   * Check the contact's name field.
   *
   * @param mixed $first_name NULL means do not compare, otherwise a comparison
   *                          is made.
   * @param mixed $last_name  works same
   */
  public function assertContactName($contact, $first_name=NULL, $last_name=NULL) {
    $this->assertGreaterThan(0, $contact['contact_id']);
    $result = civicrm_api3('Contact', 'getsingle', [
      'contact_id' => $contact['contact_id'],
      'return' => 'first_name,last_name',
      ]);
    if ($first_name !== NULL) {
      $this->assertEquals($first_name, $result['first_name'],
        "First name was not as expected for contact $contact[contact_id]");
      $this->assertEquals($last_name, $result['last_name'],
        "Last name was not as expected for contact $contact[contact_id]");
    }
  }
  /**
   * Creates the 'titanic' situation where we have several contact in CiviCRM
   * that could potentially match data from Mailchimp.
   *
   * This code is shared between `testPullIgnoresDuplicates` and
   * `testPushDoesNotUnsubscribeDuplicates`.
   */
  public function createTitanic() {
    $c1 = static::$civicrm_contact_1;
    $c2 = static::$civicrm_contact_2;
    // Add contact1, to the membership group, allowing the posthook to also
    // subscribe them.
    $this->joinMembershipGroup($c1);

    // Now remove them without telling Mailchimp
    $this->removeGroup($c1, static::$civicrm_group_id_membership, TRUE);

    // Now create a duplicate contact by adding the email to the 2nd contact
    // and changing the last names to be the same and change the first names
    // so that neither match what Mailchimp has.
    civicrm_api3('Contact', 'create', [
      'contact_id' => $c2['contact_id'],
      'last_name' => $c1['last_name'],
    ]);
    civicrm_api3('Contact', 'create', [
      'contact_id' => $c1['contact_id'],
      'first_name' => 'New ' . $c1['first_name'],
    ]);
    civicrm_api3('Email', 'create', [
      'contact_id' => $c2['contact_id'],
      'email' => $c1['email'],
      'is_bulkmail' => 1,
    ]);
  }
}

//
// test that collect Civi collects right interests data.
// test that collect Mailchimp collects right interests data.
//
// test that push does interests correctly.
// test when mc has unmapped interests that they are not affected by our code.
