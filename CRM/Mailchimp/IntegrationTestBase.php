<?php
/**
 * @file
 * Contains code for generating fixtures shared between tests.
 */
class CRM_Mailchimp_IntegrationTestBase extends \PHPUnit_Framework_TestCase {
  const
    MC_TEST_LIST_NAME = 'Mailchimp-CiviCRM Integration Test List',
    MC_INTEREST_CATEGORY_TITLE = 'Test Interest Category',
    MC_INTEREST_NAME_1 = 'Orang-utans',
    MC_INTEREST_NAME_2 = 'Climate Change',
    C_TEST_MEMBERSHIP_GROUP_NAME = 'mailchimp_integration_test_m',
    C_TEST_INTEREST_GROUP_NAME_1 = 'mailchimp_integration_test_i1',
    C_TEST_INTEREST_GROUP_NAME_2 = 'mailchimp_integration_test_i2',
    C_CONTACT_1_FIRST_NAME = 'Wilma',
    C_CONTACT_1_LAST_NAME = 'Flintstone-Test-Record',
    C_CONTACT_2_FIRST_NAME = 'Barney',
    C_CONTACT_2_LAST_NAME = 'Rubble-Test-Record'
    ;
  protected static $api_contactable;
  /** string holds the Mailchimp Id for our test list. */
  protected static $test_list_id;
  /** string holds the Mailchimp Id for test interest category. */
  protected static $test_interest_category_id;
  /** string holds the Mailchimp Id for test interest. */
  protected static $test_interest_id_1;
  /** string holds the Mailchimp Id for test interest. */
  protected static $test_interest_id_2;

  /** holds CiviCRM contact Id for test contact 1*/
  protected static $test_cid1;
  /** holds CiviCRM contact Id for test contact 2*/
  protected static $test_cid2;
  /** holds CiviCRM Group Id for membership group*/
  protected static $civicrm_group_id_membership;
  /** holds CiviCRM Group Id for interest group id*/
  protected static $civicrm_group_id_interest_1;
  /** holds CiviCRM Group Id for interest group id*/
  protected static $civicrm_group_id_interest_2;

  /**
   * array Test contact 1
   */
  protected static $civicrm_contact_1 = [
    'contact_id' => NULL,
    'first_name' => self::C_CONTACT_1_FIRST_NAME,
    'last_name' => self::C_CONTACT_1_LAST_NAME,
    ];
  
  /**
   * array Test contact 2
   */
  protected static $civicrm_contact_2 = [
    'contact_id' => NULL,
    'first_name' => self::C_CONTACT_2_FIRST_NAME,
    'last_name' => self::C_CONTACT_2_LAST_NAME,
    ];

  /** custom_N name for this field */
  protected static $custom_mailchimp_group;
  /** custom_N name for this field */
  protected static $custom_mailchimp_grouping;
  /** custom_N name for this field */
  protected static $custom_mailchimp_list;
  /** custom_N name for this field */
  protected static $custom_is_mc_update_grouping;

  // Shared helper functions.
  /**
   * Connect to API and create test fixture list.
   *
   * Creates one list with one interest category and two interests.
   */
  public static function createMailchimpFixtures() {
    try {
      $api = CRM_Mailchimp_Utils::getMailchimpApi();
      $result = $api->get('/');
      static::$api_contactable = $result;

      // Ensure we have a test list.
      $test_list_id = NULL;
      $lists = $api->get('/lists', ['count' => 10000, 'fields' => 'lists.name,lists.id'])->data->lists;
      foreach ($lists as $list) {
        if ($list->name == self::MC_TEST_LIST_NAME) {
          $test_list_id = $list->id;
          break;
        }
      }

      if (empty($test_list_id)) {
        // Test list does not exist, create it now.

        // Annoyingly Mailchimp uses addr1 in a GET / response and address1 for
        // a POST /lists request!
        $contact = (array) static::$api_contactable->data->contact;
        $contact['address1'] = $contact['addr1'];
        $contact['address2'] = $contact['addr2'];
        unset($contact['addr1'], $contact['addr2']);

        $test_list_id = $api->post('/lists', [
          'name' => self::MC_TEST_LIST_NAME,
          'contact' => $contact,
          'permission_reminder' => 'This is sent to test email accounts only.',
          'campaign_defaults' => [
            'from_name' => 'Automated Test Script',
            'from_email' => static::$api_contactable->data->email,
            'subject' => 'Automated Test',
            'language' => 'en',
            ],
          'email_type_option' => FALSE,
        ])->data->id;
      }

      // Store this for our fixture.
      static::$test_list_id = $test_list_id;

      // Ensure the list has the interest category we need.
      $categories = $api->get("/lists/$test_list_id/interest-categories",
            ['fields' => 'categories.id,categories.title','count'=>10000])
          ->data->categories;
      $category_id = NULL;
      foreach ($categories as $category) {
        if ($category->title == static::MC_INTEREST_CATEGORY_TITLE) {
          $category_id = $category->id;
        }
      }
      if ($category_id === NULL) {
        // Create it.
        $category_id = $api->post("/lists/$test_list_id/interest-categories", [
          'title' => static::MC_INTEREST_CATEGORY_TITLE,
          'type' => 'hidden',
        ])->data->id;
      }
      static::$test_interest_category_id = $category_id;

      // Store thet interest ids.
      static::$test_interest_id_1 = static::createInterest(static::MC_INTEREST_NAME_1);
      static::$test_interest_id_2 = static::createInterest(static::MC_INTEREST_NAME_2);
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
   * Create an interest within our interest category on the Mailchimp list.
   *
   * @return string interest_id created.
   */
  public static function createInterest($name) {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();
    // Ensure the interest category has the interests we need.
    $test_list_id = static::$test_list_id;
    $category_id = static::$test_interest_category_id;
    $interests = $api->get("/lists/$test_list_id/interest-categories/$category_id/interests",
      ['fields' => 'interests.id,interests.name','count'=>10000])
      ->data->interests;
    $interest_id = NULL;
    foreach ($interests as $interest) {
      if ($interest->name == $name) {
        $interest_id = $interest->id;
      }
    }
    if ($interest_id === NULL) {
      // Create it.
      // Note: as of 9 May 2016, Mailchimp do not advertise this method and
      // while it works, it throws an error. They confirmed this behaviour in
      // a live chat session and said their devs would look into it, so may
      // have been fixed.
      try {
        $interest_id = $api->post("/lists/$test_list_id/interest-categories/$category_id/interests", [
          'name' => $name,
        ])->data->id;
      }
      catch (CRM_Mailchimp_NetworkErrorException $e) {
        // As per comment above, this may still have worked. Repeat the
        // lookup.
        //
        $interests = $api->get("/lists/$test_list_id/interest-categories/$category_id/interests",
          ['fields' => 'interests.id,interests.name','count'=>10000])
          ->data->interests;
        foreach ($interests as $interest) {
          if ($interest->name == $name) {
            $interest_id = $interest->id;
          }
        }
        if (empty($interest_id)) {
          throw new CRM_Mailchimp_NetworkErrorException($api, "Creating the interest failed, and while this is a known bug, it actually did not create the interest, either. ");
        }
      }
    }
    return $interest_id;
  }
  
  /**
   * Creates CiviCRM fixtures.
   *
   * Creates three groups and two contacts. Groups:
   *
   * 1. Group tracks membership of mailchimp test list.
   * 2. Group tracks interest 1
   * 3. Group tracks interest 2
   *
   * Can be run multiple times without creating multiple fixtures.
   *
   */
  public static function createCiviCrmFixtures() {
    //
    // Now set up the CiviCRM fixtures.
    //

    // Ensure we have a security key configured.
    $key = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'security_key', NULL, FALSE);
    if (!$key) {
      // Create a random key.
      CRM_Core_BAO_Setting::setItem( md5(time() . 'Something unique'), CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'security_key');
    }

    // Need to know field Ids for mailchimp fields.
    $result = civicrm_api3('CustomField', 'get', ['label' => array('LIKE' => "%mailchimp%")]);
    $custom_ids = [];
    foreach ($result['values'] as $custom_field) {
      $custom_ids[$custom_field['name']] = "custom_" . $custom_field['id'];
    }
    // Ensure we have the fields we later rely on.
    foreach (['Mailchimp_Group', 'Mailchimp_Grouping', 'Mailchimp_List', 'is_mc_update_grouping'] as $_) {
      if (empty($custom_ids[$_])) {
        throw new Exception("Expected to find the Custom Field with name $_");
      }
      // Store as static vars.
      $var = 'custom_' . strtolower($_);
      static::${$var} = $custom_ids[$_];
    }

    // Next create mapping groups in CiviCRM for membership group
    $result = civicrm_api3('Group', 'get', ['name' => static::C_TEST_MEMBERSHIP_GROUP_NAME, 'sequential' => 1]);
    if ($result['count'] == 0) {
      // Didn't exist, create it now.
      $result = civicrm_api3('Group', 'create', [
        'sequential' => 1,
        'name' => static::C_TEST_MEMBERSHIP_GROUP_NAME,
        'title' => static::C_TEST_MEMBERSHIP_GROUP_NAME,
      ]);
    }
    static::$civicrm_group_id_membership = (int) $result['values'][0]['id'];

    // Ensure this group is set to be the membership group.
    $result = civicrm_api3('Group', 'create', array(
      'id' => static::$civicrm_group_id_membership,
      $custom_ids['Mailchimp_List'] => static::$test_list_id,
      $custom_ids['is_mc_update_grouping'] => 0,
      $custom_ids['Mailchimp_Grouping'] => NULL,
      $custom_ids['Mailchimp_Group'] => NULL,
    ));

    // Create group for the interests
    static::$civicrm_group_id_interest_1 = (int) static::createMappedInterestGroup($custom_ids, static::C_TEST_INTEREST_GROUP_NAME_1, static::$test_interest_id_1);
    static::$civicrm_group_id_interest_2 = (int) static::createMappedInterestGroup($custom_ids, static::C_TEST_INTEREST_GROUP_NAME_2, static::$test_interest_id_2);

    // Now create test contacts
    // Re-set their names.
    static::$civicrm_contact_1 = [
      'contact_id' => NULL,
      'first_name' => self::C_CONTACT_1_FIRST_NAME,
      'last_name' => self::C_CONTACT_1_LAST_NAME,
      ];
    static::createTestContact(static::$civicrm_contact_1);
    static::$civicrm_contact_2 = [
      'contact_id' => NULL,
      'first_name' => self::C_CONTACT_2_FIRST_NAME,
      'last_name' => self::C_CONTACT_2_LAST_NAME,
      ];
    static::createTestContact(static::$civicrm_contact_2);
  }
  
  /**
   * Create a contact in CiviCRM
   *
   * The input array is added to, adding email, contact_id and subscriber_hash
   *
   * @param array bare-bones contact details including just the keys: first_name, last_name.
   *
   */
  public static function createTestContact(&$contact) {
    $url_parts = parse_url(CIVICRM_UF_BASEURL);
    $contact['email'] = strtolower($contact['first_name'] . '.' . $contact['last_name']) . '.' . time() . '@' . $url_parts['host'];
    $contact['subscriber_hash'] = md5(strtolower($contact['email']));

    $result = civicrm_api3('Contact', 'get', ['sequential' => 1,
      'first_name' => $contact['first_name'],
      'last_name'  => $contact['last_name'],
      'email'      => $contact['email'],
    ]);

    if ($result['count'] == 0) {
      // Create the contact.
      $result = civicrm_api3('Contact', 'create', ['sequential' => 1,
        'contact_type' => 'Individual',
        'first_name' => $contact['first_name'],
        'last_name'  => $contact['last_name'],
        'api.Email.create' => [
          'email'      => $contact['email'],
          'is_bulkmail' => 1,
          'is_primary' => 1,
        ],
      ]);
    }
    $contact['contact_id'] = (int) $result['values'][0]['id'];
    return $contact;
  }
  
  /**
   * Create a group in CiviCRM that maps to the interest group name.
   *
   * @param string $name e.g. C_TEST_INTEREST_GROUP_NAME_1
   * @param string $interest_id Mailchimp interest id.
   */
  public static function createMappedInterestGroup($custom_ids, $name, $interest_id) {
    // Create group for the interest.
    $result = civicrm_api3('Group', 'get', ['name' => $name, 'sequential' => 1]);
    if ($result['count'] == 0) {
      // Didn't exist, create it now.
      $result = civicrm_api3('Group', 'create', [ 'sequential' => 1, 'name' => $name, 'title' => $name, ]);
    }
    $group_id = (int) $result['values'][0]['id'];

    // Ensure this group is set to be the interest group.
    $result = civicrm_api3('Group', 'create', [
      'id'                                 => $group_id,
      $custom_ids['Mailchimp_List']        => static::$test_list_id,
      $custom_ids['is_mc_update_grouping'] => 1,
      $custom_ids['Mailchimp_Grouping']    => static::$test_interest_category_id,
      $custom_ids['Mailchimp_Group']       => $interest_id,
    ]);

    return $group_id;
  }
  
  /**
   * Remove the test list, if one was successfully set up.
   */
  public static function tearDownMailchimpFixtures() {
    if (empty(static::$api_contactable->http_code)
      || static::$api_contactable->http_code != 200
      || empty(static::$test_list_id)
      || !is_string(static::$test_list_id)) {

      // Nothing to do.
      return;
    }

    try {

      // Delete is a bit of a one-way thing so we really test that it's the
      // right thing to do.

      // Check that the list exists, is named as we expect and only has max 2
      // contacts.
      $api = CRM_Mailchimp_Utils::getMailchimpApi();
      $test_list_id = static::$test_list_id;
      $result = $api->get("/lists/$test_list_id", ['fields' => '']);
      if ($result->http_code != 200) {
        throw new CRM_Mailchimp_RequestErrorException($api, "Trying to delete test list $test_list_id but getting list details failed. ");
      }
      if ($result->data->id != $test_list_id) {
        // OK this is paranoia.
        throw new CRM_Mailchimp_RequestErrorException($api, "Trying to delete test list $test_list_id but getting list returned different list?! ");
      }
      if ($result->data->name != static::MC_TEST_LIST_NAME) {
        // OK this is paranoia.
        throw new CRM_Mailchimp_RequestErrorException($api, "Trying to delete test list $test_list_id but the name was not as expected, so not deleted. ");
      }
      if ($result->data->stats->member_count > 2) {
        // OK this is paranoia.
        throw new CRM_Mailchimp_RequestErrorException($api, "Trying to delete test list $test_list_id but it has more than 2 members, so not deleted. ");
      }

      // OK, the test list exists, has the right name and only has two members:
      // delete it.
      $result = $api->delete("/lists/$test_list_id");
      if ($result->http_code != 204) {
        throw new CRM_Mailchimp_RequestErrorException($api, "Trying to delete test list $test_list_id but delete method did not return 204 as http response. ");
      }

    }
    catch (CRM_Mailchimp_Exception $e) {
      print "*** Exception!***\n" . $e->getMessage() . "\n";
      // Spit out request and response for debugging.
      print "Request:\n";
      print_r($e->request);
      print "Response:\n";
      print_r($e->response);
      // re-throw exception for usual stack trace etc.
      throw $e;
    }
  }
  
  /**
   * Strip out all test fixtures from CiviCRM.
   *
   * This is fairly course.
   *
   */
  public static function tearDownCiviCrmFixtures() {

    static::tearDownCiviCrmFixtureContacts();

    // Delete test group(s)
    if (static::$civicrm_group_id_membership) {
      //print "deleting test list ".static::$civicrm_group_id_membership ."\n";
      // Ensure this group is set to be the membership group.
      $result = civicrm_api3('Group', 'delete', ['id' => static::$civicrm_group_id_membership]);
    }
  }
  
  /**
   * Strip out CivCRM test contacts.
   */
  public static function tearDownCiviCrmFixtureContacts() {

    // Delete test contact(s)
    foreach ([static::$civicrm_contact_1, static::$civicrm_contact_2] as $contact) {
      if (!empty($contact['contact_id'])) {
        // print "Deleting test contact " . $contact['contact_id'] . "\n";
        $contact_id = (int) $contact['contact_id'];
        if ($contact_id>0) {
          try {
            // Test for existance of contact before trying a delete.
            civicrm_api3('Contact', 'getsingle', ['id' => $contact_id]);
            $result = civicrm_api3('Contact', 'delete', ['id' => $contact_id, 'skip_undelete' => 1]);
          }
          catch (CiviCRM_API3_Exception $e) {
            if ($e->getMessage() != 'Expected one Contact but found 0') {
              // That's OK, if it's already gone.
              throw $e;
            }
          }
        }
      }
    }
    // Reset the class variables for test contacts 1, 2
    static::$civicrm_contact_1 = [
      'contact_id' => NULL,
      'first_name' => self::C_CONTACT_1_FIRST_NAME,
      'last_name'  => self::C_CONTACT_1_LAST_NAME,
      ];
    static::$civicrm_contact_2 = [
      'contact_id' => NULL,
      'first_name' => self::C_CONTACT_2_FIRST_NAME,
      'last_name'  => self::C_CONTACT_2_LAST_NAME,
      ];

    // Delete any contacts with the last name of one of the test records.
    // this should be covered by the above, but a test goes very wrong it's
    // possible we end up with orphaned contacts that would screw up later
    // tests. The names have been chosen such that they're pretty much
    // definitely not going to be real ones ;-)
    $result = civicrm_api3('Contact', 'get', [
      'return' => 'contact_id',
      'last_name' => ['IN' => [self::C_CONTACT_1_LAST_NAME, self::C_CONTACT_2_LAST_NAME]]]);
    foreach (array_keys($result['values']) as $contact_id) {
      if ($contact_id>0) {
        try {
          $result = civicrm_api3('Contact', 'delete', ['id' => $contact_id, 'skip_undelete' => 1]);
        }
        catch (Exception $e) {
          throw $e;
        }
      }
    }
  }
  
  /**
   * Check that the contact's email is a member in given state.
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
   * Check that the contact's email is not a member of the test list.
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
   * Sugar function for adjusting fixture: uses CiviCRM API to add contact to
   * the membership group.
   *
   * Used a lot in the tests.
   *
   * @param array $contact Set to static::$civicrm_contact_{1,2}
   */
  public function joinMembershipGroup($contact, $disable_post_hooks=FALSE) {
    return $this->joinGroup($contact, static::$civicrm_group_id_membership, $disable_post_hooks);
  }
  
  /**
   * Sugar function for adjusting fixture: uses CiviCRM API to add contact to
   * the group specified.
   *
   * Used a lot in the tests.
   *
   * @param array $contact Set to static::$civicrm_contact_{1,2}
   * @param int   $group_id Set to
   *              static::$civicrm_group_id_interest_{1,2}
   */
  public function joinGroup($contact, $group_id, $disable_post_hooks=FALSE) {
    // Subscription at Mailchimp happens via the post hook. As that hook is also
    // run in user contexts it errors with CRM_Core_Session::setMessage(),
    // rather than an exception which may cause problems for the user. So we
    // have to look for and catch these errors via the session.

    // Ensure we have no session status message.
    $session = CRM_Core_Session::singleton();
    $session->getStatus(TRUE);

    if ($disable_post_hooks) {
      $original_state = CRM_Mailchimp_Utils::$post_hook_enabled;
      CRM_Mailchimp_Utils::$post_hook_enabled = FALSE;
    }
    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => $group_id,
      'contact_id' => $contact['contact_id'],
      'status' => "Added",
    ]);
    if ($disable_post_hooks) {
      CRM_Mailchimp_Utils::$post_hook_enabled = $original_state;
    }
    $errors = $session->getStatus(TRUE);
    if ($errors) {
      // Throw it to break the tests.
      throw new Exception($errors[0]['text']);
    }
    return $result;
  }
  
  /**
   * Sugar function for adjusting fixture: uses CiviCRM API to 'remove' contact
   * from the group specified.
   *
   * @param array $contact Set to static::$civicrm_contact_{1,2}
   * @param int   $group_id Set to
   *              static::$civicrm_group_id_interest_{1,2}
   */
  public function removeGroup($contact, $group_id, $disable_post_hooks=FALSE) {
    if ($disable_post_hooks) {
      $original_state = CRM_Mailchimp_Utils::$post_hook_enabled;
      CRM_Mailchimp_Utils::$post_hook_enabled = FALSE;
    }
    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => $group_id,
      'contact_id' => $contact['contact_id'],
      'status' => "Removed",
    ]);
    if ($disable_post_hooks) {
      CRM_Mailchimp_Utils::$post_hook_enabled = $original_state;
    }
    return $result;
  }
  
  /**
   * Sugar function for adjusting fixture: uses CiviCRM API to delete all
   * GroupContact records between the contact and the group specified.
   *
   * @param array $contact Set to static::$civicrm_contact_{1,2}
   * @param int   $group_id Set to
   *              static::$civicrm_group_id_interest_{1,2}
   */
  public function deleteGroup($contact, $group_id, $disable_post_hooks=FALSE) {
    if ($disable_post_hooks) {
      $original_state = CRM_Mailchimp_Utils::$post_hook_enabled;
      CRM_Mailchimp_Utils::$post_hook_enabled = FALSE;
    }
    $result = civicrm_api3('GroupContact', 'delete', [
      'group_id' => $group_id,
      'contact_id' => $contact['contact_id'],
    ]);
    if ($disable_post_hooks) {
      CRM_Mailchimp_Utils::$post_hook_enabled = $original_state;
    }
    return $result;
  }
  
  /**
   * Assert that a contact exists in the given CiviCRM group.
   */
  public function assertContactIsInGroup($contact_id, $group_id) {
    $result = civicrm_api3('Contact', 'getsingle', ['group' => $group_id, 'id' => $contact_id]);
    $this->assertEquals($contact_id, $result['contact_id']);
  }
  
  /**
   * Assert that a contact does not exist in the given CiviCRM group.
   */
  public function assertContactIsNotInGroup($contact_id, $group_id, $msg=NULL) {
    // Initial sanity checks.
    $this->assertGreaterThan(0, $contact_id);
    $this->assertGreaterThan(0, $group_id);
    // Fetching the contact should work.
    $result = civicrm_api3('Contact', 'getsingle', ['id' => $contact_id]);
    try {
      // ...But not if we filter for this group.
      $result = civicrm_api3('Contact', 'getsingle', ['group' => $group_id, 'id' => $contact_id]);
      if ($msg === NULL) {
        $msg = "Contact '$contact_id' should not be in group '$group_id', but is.";
      }
      $this->fail($msg);
    }
    catch (CiviCRM_API3_Exception $e) {
      $x=1;
    }
  }
}
