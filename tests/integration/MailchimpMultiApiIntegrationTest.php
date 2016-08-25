<?php
/**
 * @file
 * Tests that the systems work together as expected this work only two accounts or more.
 *
 */

require 'integration-test-bootstrap.php';

class MailchimpMultiApiIntegrationTest extends MailchimpApiIntegrationBase {
  /**
   * Connect to API and create test fixtures in Mailchimp and CiviCRM.
   */
  public static function setUpBeforeClass() {
    CRM_Mailchimp_Utils::insertApiDetailsToDb(__DIR__ . DIRECTORY_SEPARATOR.self::API_FILENAME);
    $noOfAccounts = CRM_Mailchimp_Utils::getCountMailchimpAccounts();
    //throw error if it is one account
    if ($noOfAccounts == 1) {
      throw new Exception('Multi Api Integration do not support, needed minimum 2');
    }
    // if more than two accounts, keep it first two accounts for test cases
    if ($noOfAccounts > 2) {// Keep first two and delete rest
      CRM_Mailchimp_Utils::keepFirstTwoDeleteRest();
    }

    static::$account_id = (int) CRM_Mailchimp_Utils::getMailchimpSingleAccountId();//account1
    static::$account_id_account_2 = (int) CRM_Mailchimp_Utils::getMailchimpSecondAccountId();//account2
    $api = CRM_Mailchimp_Utils::getMailchimpApi(static::$account_id);
    $api->setLogFacility(function($m){print $m;});
    $api->setLogFacility(function($m){CRM_Core_Error::debug_log_message($m, FALSE, 'mailchimp');});
    static::createMailchimpFixtures(static::$account_id, static::$account_id_account_2);
  }
  /**
   * Runs before every test.
   */
  public function setUp() {
    // Ensure CiviCRM fixtures present.
    static::createCiviCrmFixtures(static::$account_id, static::$account_id_account_2);
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
    
    $this->assertEquals(200, static::$api_contactable_account_2->http_code);
    $this->assertTrue(!empty(static::$api_contactable_account_2->data->account_name), "Expected account_name to be returned.");
    $this->assertTrue(!empty(static::$api_contactable_account_2->data->email), "Expected email belonging to the account to be returned.");


    $this->assertNotEmpty(static::$test_list_id);
    $this->assertInternalType('string', static::$test_list_id);
    $this->assertGreaterThan(0, static::$civicrm_contact_1['contact_id']);
    $this->assertGreaterThan(0, static::$civicrm_contact_2['contact_id']);
    
    $this->assertNotEmpty(static::$test_list_id_account_2);
    $this->assertInternalType('string', static::$test_list_id_account_2);

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
    
    $api = CRM_Mailchimp_Utils::getMailchimpApi(static::$account_id);
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

    
    $api = CRM_Mailchimp_Utils::getMailchimpApi(static::$account_id_account_2);
    $contacts = array_filter([static::$civicrm_contact_1, static::$civicrm_contact_2],
      function($_) { return $_['contact_id']>0; });

    // Ensure list is empty.
    $list_id = static::$test_list_id_account_2;
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
    $this->tearDownCiviCrmFixtures(TRUE);
    return;
    foreach ($contacts as $contact) {
      foreach ([
        static::$civicrm_group_id_membership,
        static::$civicrm_group_id_interest_1,
        static::$civicrm_group_id_interest_2,
        static::$civicrm_group_id_membership_account_2,
        static::$civicrm_group_id_interest_1_account_2,
        static::$civicrm_group_id_interest_2_account_2] as $group_id) {
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
   * Starting with an empty MC list and one person on the two CiviCRM mailchimp
   * groups(different account), a push should subscribe the person in both MC list in different accounts
   *
   * @group push
   */
  
  public function testPushAddsNewSamePersonToDifferentMembershipGroup() {
    try {
      // Add contact to membership group without telling MC.
      $this->joinMembershipGroup(static::$civicrm_contact_1, static::$civicrm_group_id_membership, TRUE);
      $this->joinMembershipGroup(static::$civicrm_contact_1, static::$civicrm_group_id_membership_account_2, TRUE);
      // Check they are definitely in the group.
      $this->assertContactIsInGroup(static::$civicrm_contact_1['contact_id'], static::$civicrm_group_id_membership);
      $this->assertContactIsInGroup(static::$civicrm_contact_1['contact_id'], static::$civicrm_group_id_membership_account_2);

      // Double-check this member is not known at Mailchimp.
      $this->assertContactNotListMemberMultiple(static::$account_id, static::$account_id_account_2, static::$civicrm_contact_1);
      foreach (array(static::$account_id, static::$account_id_account_2) as $accountId) {
        $api = CRM_Mailchimp_Utils::getMailchimpApi($accountId);
        $sync = new CRM_Mailchimp_Sync($accountId, static::$accountId_listId_relationship[$accountId]);

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
            $listId = static::$accountId_listId_relationship[$accountId];
            $result = $api->get("/lists/" . $listId . "/members/" . static::$civicrm_contact_1['subscriber_hash'], ['fields' => 'status']);
            CRM_Core_Error::debug_var('$listId', $result);
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
   * Starting with an empty MC list and one person on the CiviCRM mailchimp interst
   * group for one account, a push should subscribe the person to the exact mailchimp account
   *
   * @group push
   */
  
  public function testPushAddsNewPersonToInterest() {
     try {
      // Add contact to membership group without telling MC.
      $this->joinMembershipGroup(static::$civicrm_contact_1, static::$civicrm_group_id_membership_account_2, TRUE);
      // Check they are definitely in the group.
      $this->assertContactIsInGroup(static::$civicrm_contact_1['contact_id'], static::$civicrm_group_id_membership_account_2);
      // Add contact to inteest group without telling MC.
      $this->joinGroup(static::$civicrm_contact_1, static::$civicrm_group_id_interest_1_account_2, TRUE);
      $this->assertContactIsInGroup(static::$civicrm_contact_1['contact_id'], static::$civicrm_group_id_interest_1);
      // Double-check this member is not known at Mailchimp.
      $this->assertContactNotListMember(static::$account_id_account_2, static::$civicrm_contact_1);
      $api = CRM_Mailchimp_Utils::getMailchimpApi(static::$account_id_account_2);
      $sync = new CRM_Mailchimp_Sync(static::$account_id_account_2, static::$test_list_id_account_2);

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
          $listId = static::$test_list_id_account_2;
          $result = $api->get("/lists/" . $listId . "/members/" . static::$civicrm_contact_1['subscriber_hash'], ['fields' => 'status']);
          CRM_Core_Error::debug_var('$listId', $result);
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
  
  /*
   * Test post hook works when a contact is added to civicrm membership group, should subscribe to relavant account's list
   */
  
  public function testHookPushAddsNewPerson() {
     try {
       // Add contact1, to the membership group, allowing the posthook to also
      // subscribe them.
      $this->joinMembershipGroup(static::$civicrm_contact_1, static::$civicrm_group_id_membership_account_2);
      // Check they are definitely in the group.
      $this->assertContactIsInGroup(static::$civicrm_contact_1['contact_id'], static::$civicrm_group_id_membership_account_2);
      $api = CRM_Mailchimp_Utils::getMailchimpApi(static::$account_id_account_2);
      $sync = new CRM_Mailchimp_Sync(static::$account_id_account_2, static::$test_list_id_account_2);

      // Now trigger a push for this test list.

      // Collect data from CiviCRM.
      // There should be one member.
      $sync->collectCiviCrm('push');
      $this->assertEquals(1, $sync->countCiviCrmMembers());

      // Collect data from Mailchimp.
      // There shouldn't be any members in this list yet.
      $sync->collectMailchimp('push');
      $this->assertEquals(1, $sync->countMailchimpMembers());

      $matches = $sync->matchMailchimpMembersToContacts();
      CRM_Core_Error::debug_var('testHookPushAddsNewPerson $matches ', $matches);
      
      $this->assertEquals([
        'bySubscribers' => 1,
        'byUniqueEmail' => 0,
        'byNameEmail'   => 0,
        'bySingle'      => 0,
        'totalMatched'  => 1,
        'newContacts'   => 0,
        'failures'      => 0,
        ], $matches);
     

      // There should not be any in sync records.
      $in_sync = $sync->removeInSync('push');
      CRM_Core_Error::debug_var('testHookPushAddsNewPerson $in_sync ', $in_sync);
      
      $this->assertEquals(1, $in_sync);

      // Now check they are subscribed.
      $not_found = TRUE;
      $i =0;
      $start = time();
      //print date('Y-m-d H:i:s') . " Mailchimp batch returned 'finished'\n";
      while ($not_found && $i++ < 2*10) {
        try {
          $listId = static::$test_list_id_account_2;
          $result = $api->get("/lists/" . $listId . "/members/" . static::$civicrm_contact_1['subscriber_hash'], ['fields' => 'status']);
          CRM_Core_Error::debug_var('$listId', $result);
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
   * Check that the contact's email is not a member of the test list at
   * Mailchimp.
   * @param int account1, int account2, 
   * @param array $contact e.g. static::$civicrm_contact_1
   */
  public function assertContactNotListMemberMultiple($accountId, $accountId2, $contact) {
    try {
      $api = CRM_Mailchimp_Utils::getMailchimpApi($accountId);
      $subscriber_hash = static::$civicrm_contact_1['subscriber_hash'];
      $result = $api->get("/lists/" . static::$test_list_id . "/members/$contact[subscriber_hash]", ['fields' => 'status']);
      $api = CRM_Mailchimp_Utils::getMailchimpApi($accountId2);
      $result = $api->get("/lists/" . static::$test_list_id_account_2 . "/members/$contact[subscriber_hash]", ['fields' => 'status']);
    }
    catch (CRM_Mailchimp_RequestErrorException $e) {
      $this->assertEquals(404, $e->response->http_code);
    }
  }
  public function assertContactNotListMember($accountId, $contact) {
    try {
      $api = CRM_Mailchimp_Utils::getMailchimpApi($accountId);
      $subscriber_hash = static::$civicrm_contact_1['subscriber_hash'];
      $result = $api->get("/lists/" . static::$test_list_id . "/members/$contact[subscriber_hash]", ['fields' => 'status']);
    }
    catch (CRM_Mailchimp_RequestErrorException $e) {
      $this->assertEquals(404, $e->response->http_code);
    }
  }
}


