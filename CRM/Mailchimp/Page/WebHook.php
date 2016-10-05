<?php
class CRM_Mailchimp_Page_WebHook extends CRM_Core_Page {

  const MC_SETTING_GROUP = 'MailChimp Preferences';

  /**
   * Holds a CRM_Mailchimp_Sync object for the list.
   *
   * This is a common requirement of several methods.
   */
  public $sync;

  /**
   * Holds the contents of the request data's 'data' key.
   *
   * Full request post data is { 'type': TYPE, 'data': {} }, but beyond routing
   * we're only interested in the bit inside 'data'.
   */
  public $request_data;

  /**
   * CiviCRM contact id.
   */
  public $contact_id;
  /**
   * Process a webhook request from Mailchimp.
   *
   * The only documentation for this *sigh* is (May 2016) at
   * https://apidocs.mailchimp.com/webhooks/
   */
  public function run() {

    CRM_Mailchimp_Utils::checkDebug("Webhook POST: " . serialize($_POST));
    // Empty response object, default response code.
    try {
      $expected_key = CRM_Core_BAO_Setting::getItem(self::MC_SETTING_GROUP, 'security_key', NULL, FALSE);
      $given_key = isset($_GET['key']) ? $_GET['key'] : null;
      list($response_code, $response_object) = $this->processRequest($expected_key, $given_key, $_POST);
      CRM_Mailchimp_Utils::checkDebug("Webhook response code $response_code (200 = ok)");
    }
    catch (RuntimeException $e) {
      $response_code = $e->getCode();
      $response_object = NULL;
      CRM_Mailchimp_Utils::checkDebug("Webhook RuntimeException code $response_code (200 means OK): " . $e->getMessage());
    }
    catch (Exception $e) {
      // Broad catch.
      $response_code = 500;
      $response_object = NULL;
      CRM_Mailchimp_Utils::checkDebug("Webhook " . get_class($e) . ": " . $e->getMessage());
    }

    // Serve HTTP response.
    if ($response_code != 200) {
      // Some fault.
      header("HTTP/1.1 $response_code");
    }
    else {
      // Return the JSON output
      header('Content-type: application/json');
      print json_encode($response_object);
    }
    CRM_Utils_System::civiExit();
  }

  /**
   * Validate and process the request.
   *
   * This is separated from the run() method for testing purposes.
   *
   * This method serves as a router to other methods named after the type of
   * webhook we're called with.
   *
   * Methods may return data for mailchimp, or may throw RuntimeException
   * objects, the error code of which will be used for the response.
   * So you can throw a `RuntimeException("Invalid webhook configuration", 500);`
   * to tell mailchimp the webhook failed, but you can equally throw a 
   * `RuntimeException("soft fail", 200)` which will not tell Mailchimp there
   * was any problem. Mailchimp retries if there was a problem.
   *
   * If an exception is thrown, it is logged. @todo where?
   *
   * @return array with two values: $response_code, $response_object.
   */
  public function processRequest($expected_key, $key, $request_data) {

    // Check CMS's permission for (presumably) anonymous users.
    if (CRM_Core_Config::singleton()->userPermissionClass->isModulePermissionSupported() && !CRM_Mailchimp_Permission::check('allow webhook posts')) {
      throw new RuntimeException("Missing allow webhook posts permission.", 500);
    }

    // Check the 2 keys exist and match.
    if (!$key || !$expected_key || $key != $expected_key ) {
      throw new RuntimeException("Invalid security key.", 500);
    }

    if (empty($request_data['data']['list_id']) || empty($request_data['type'])
      || !in_array($request_data['type'], ['subscribe', 'unsubscribe', 'profile', 'upemail', 'cleaned'])
    ) {
      // We are not programmed to respond to this type of request.
      // But maybe Mailchimp introduced something new, so we'll just say OK.
      throw new RuntimeException("Missing or invalid data in request: " . json_encode($request_data), 200);
    }

    $method = $request_data['type'];

    // Check list config at Mailchimp.
    $list_id = $request_data['data']['list_id'];
    $api = CRM_Mailchimp_Utils::getMailchimpApi();
    $result = $api->get("/lists/$list_id/webhooks")->data->webhooks;
    $url = CRM_Mailchimp_Utils::getWebhookUrl();
    // Find our webhook and check for a particularly silly configuration.
    foreach ($result as $webhook) {
      if ($webhook->url == $url) {
        if ($webhook->sources->api) {
          // To continue could cause a nasty loop.
          throw new RuntimeException("The list '$list_id' is not configured correctly at Mailchimp. It has the 'API' source set so processing this using the API could cause a loop.", 500);
        }
      }
    }

    // Disable post hooks. We're updating *from* Mailchimp so we don't want
    // to fire anything *at* Mailchimp.
    CRM_Mailchimp_Utils::$post_hook_enabled = FALSE;

    // Pretty much all the request methods use these:
    $this->sync = new CRM_Mailchimp_Sync($request_data['data']['list_id']);
    $this->request_data = $request_data['data'];
    // Call the appropriate handler method.
    CRM_Mailchimp_Utils::checkDebug("Webhook: $method with request data: " . json_encode($request_data));
    $this->$method();

    // re-set the post hooks.
    CRM_Mailchimp_Utils::$post_hook_enabled = TRUE;
    // Return OK response.
    return [200, NULL];
  }

  /**
   * Handle subscribe requests.
   *
   * For subscribes we rely on the following in request_data:
   *
   * - "[list_id]": "a6b5da1054",
   * - "[email]": "api@mailchimp.com",
   * - "[merges][FNAME]": "MailChimp",
   * - "[merges][LNAME]": "API",
   * - "[merges][INTERESTS]": "Group1,Group2",
   *
   */
  public function subscribe() {
    // This work is shared with 'profile', so kept in a separate method for
    // clarity.
    $this->findOrCreateSubscribeAndUpdate();
  }
  /**
   * Handle unsubscribe requests.
   *
   * For unsubscribes we rely on the following in request_data:
   *
   * - "data[list_id]": "a6b5da1054",
   * - "data[email]": "api@mailchimp.com",
   * - "data[merges][FNAME]": "MailChimp",
   * - "data[merges][LNAME]": "API",
   *
   */
  public function unsubscribe() {

    try {
      $this->contact_id = $this->sync->guessContactIdSingle(
        $this->request_data['email'],
        $this->request_data['merges']['FNAME'],
        $this->request_data['merges']['LNAME'],
        $must_be_in_group=TRUE
      );
      if (!$this->contact_id) {
        // Hmm. We don't think they *are* subscribed.
        // Nothing for us to do.
        return;
      }
    }
    catch (CRM_Mailchimp_DuplicateContactsException $e) {
      // We cannot process this webhook.
      throw new RuntimeException("Duplicate contact: " . $e->getMessage(), 500);
    }

    // Contact has just unsubscribed, we'll need to remove them from the group.
    civicrm_api3('GroupContact', 'create', [
      'contact_id' => $this->contact_id,
      'group_id'   => $this->sync->membership_group_id,
      'status'     => 'Removed',
      ]);
  }
  /**
   * Handle profile update requests.
   *
   * Works as subscribe does.
   */
  public function profile() {

    // Profile changes trigger two webhooks simultaneously. This is
    // upsetting as we can end up creating a new contact twice. So we delay
    // the profile one a bit so that if a contact needs creating, this will
    // be done before the profile update one.
    // Mailchimp expects a response to webhooks within 15s, so we have to
    // keep the delay short enough.
    sleep(10);

    // Do same work as subscribe. While with subscribe it's more typical to find
    // a contact that is not in CiviCRM, it's still a possible situation for a
    // profile update, e.g. if the subscribe webhook failed or was not fired.
    $this->findOrCreateSubscribeAndUpdate();
  }

  /**
   * Subscriber updated their email.
   *
   * Relies on the following keys in $this->request_data:
   *
   * - list_id
   * - new_email
   * - old_email
   *
   */
  public function upemail() {
    if (empty($this->request_data['new_email'])
      || empty($this->request_data['old_email'])) {
      // Weird.
      throw new RuntimeException("Attempt to change an email address without specifying both email addresses.", 400);
    }

    // Identify contact.
    try {
      $contact_id = $this->sync->guessContactIdSingle(
        $this->request_data['old_email'], NULL, NULL, $must_be_in_group=TRUE);
    }
    catch (CRM_Mailchimp_DuplicateContactsException $e) {
      throw new RuntimeException("Duplicate contact: " . $e->getMessage(), 500);
    }
    if (!$contact_id) {
      // We don't know this person. Log an error for us, but no need for
      // Mailchimp to retry the webhook call.
      throw new RuntimeException("Contact unknown", 200);
    }

    // Now find the old email.

    // Find bulk email address for this contact.
    $result = civicrm_api3('Email', 'get', [
      'sequential' => 1,
      'contact_id' => $contact_id,
      'is_bulkmail' => 1,
    ]);
    if ($result['count'] == 1) {
      // They do have a dedicated bulk email, change it.
      $result = civicrm_api3('Email', 'create', [
        'id' => $result['values'][0]['id'],
        'email' => $this->request_data['new_email'],
      ]);
      return;
    }

    // They don't yet have a bulk email, give them one set to this new email.
    $result = civicrm_api3('Email', 'create', [
      'sequential' => 1,
      'contact_id' => $contact_id,
      'email' => $this->request_data['new_email'],
      'is_bulkmail' => 1,
    ]);

  }
  /**
   * Email removed by Mailchimp.
   *
   * The request data we rely on is:
   *
   * - data[list_id]
   * - data[campaign_id]
   * - data[reason]       This will be hard|abuse
   * - data[email]
   *
   * Put the email on hold.
   */
  public function cleaned() {
    if (empty($this->request_data['email'])) {
      // Weird.
      throw new RuntimeException("Attempt to clean an email address without an email address.", 400);
    }

    // Find the email address and whether the contact is in this list's
    // membership group.
    $result = civicrm_api3('Email', 'get', [
      'email' => $this->request_data['email'],
      'api.Contact.get' => [
        'group' => $this->sync->membership_group_id,
        'return' => "contact_id",
      ],
    ]);

    if ($result['count'] == 0) {
      throw new RuntimeException("Email unknown", 200);
    }

    // Loop found emails.
    $found = 0;
    foreach ($result['values'] as $email) {
      // hard: always set on hold.
      // abuse: set on hold only if contact is in the list.
      if ($this->request_data['reason'] == 'hard'
        || (
          $this->request_data['reason'] == 'abuse'
          && $email['api.Contact.get']['count'] == 1)
      ) {
        // Set it on hold.
        civicrm_api3('Email', 'create', ['on_hold' => 1] + $email);
        $found++;
      }
    }

    if ($this->request_data['reason'] == 'abuse' && $found == 0) {
      // We got an abuse request but we could not find a contact that was
      // subscribed; we have not put any emails on hold.
      throw new RuntimeException("Email unknown", 200);
    }
  }

  // Helper functions.
  /**
   * Find/create, and update.
   *
   * - "[list_id]": "a6b5da1054",
   * - "[email]": "api@mailchimp.com",
   * - "[merges][FNAME]": "MailChimp",
   * - "[merges][LNAME]": "API",
   * - "[merges][INTERESTS]": "Group1,Group2",
   *
   */
  public function findOrCreateSubscribeAndUpdate() {

    $this->findOrCreateContact();

    // Check whether names have changed.
    $contact = civicrm_api3('Contact', 'getsingle', ['contact_id' => $this->contact_id]);
    $edits   = CRM_Mailchimp_Sync::updateCiviFromMailchimpContactLogic(
      [
        'first_name' => empty($this->request_data['merges']['FNAME']) ? '' : $this->request_data['merges']['FNAME'],
        'last_name'  => empty($this->request_data['merges']['LNAME']) ? '' : $this->request_data['merges']['LNAME'],
      ],
      $contact);
    if ($edits) {
      // We do need to make some changes.
      civicrm_api3('Contact', 'create', ['contact_id' => $this->contact_id] + $edits);
    }

    // Contact has just subscribed, we'll need to add them to the list.
    civicrm_api3('GroupContact', 'create', [
      'contact_id' => $this->contact_id,
      'group_id'   => $this->sync->membership_group_id,
      'status'     => 'Added',
      ]);

    $this->updateInterestsFromMerges();
  }
  /**
   * Finds or creates the contact from email, first and last name.
   *
   * Sets $this->contact_id if successful.
   *
   * @throw RuntimeException if a duplicate contact in CiviCRM means we cannot
   * identify a contact.
   */
  public function findOrCreateContact() {
    // Find contact.
    try {
      // Check for missing merges fields.
      $this->request_data['merges'] += ['FNAME' => '', 'LNAME' => ''];
      if (  empty($this->request_data['merges']['FNAME'])
        &&  empty($this->request_data['merges']['LNAME'])
        && !empty($this->request_data['merges']['NAME'])) {
        // No first or last names received, but we have a NAME merge field so
        // try splitting that.
        $names = explode(' ', $this->request_data['merges']['NAME']);
        $this->request_data['merges']['FNAME'] = trim(array_shift($names));
        if ($names) {
          // Rest of names go as last name.
          $this->request_data['merges']['LNAME'] = implode(' ', $names);
        }
      }

      // Nb. the following will throw an exception if duplication prevents us
      // adding a contact, so execution will only continue if we were able
      // either to identify an existing contact, or to identify that the
      // incomming contact is a new one that we're OK to create.
      $this->contact_id = $this->sync->guessContactIdSingle(
        $this->request_data['email'],
        $this->request_data['merges']['FNAME'],
        $this->request_data['merges']['LNAME']
      );
      if (!$this->contact_id) {
        // New contact, create now.
        $result = civicrm_api3('Contact', 'create', [
          'contact_type' => 'Individual',
          'first_name' => $this->request_data['merges']['FNAME'],
          'last_name'  => $this->request_data['merges']['LNAME'],
        ]);
        if (!$result['id']) {
          throw new RuntimeException("Failed to create contact", 500);
        }
        $this->contact_id = $result['id'];
        // Create bulk email.
        $result = civicrm_api3('Email', 'create', [
          'contact_id' => $this->contact_id,
          'email' => $this->request_data['email'],
          'is_bulkmail' => 1,
          ]);
        if (!$result['id']) {
          throw new RuntimeException("Failed to create contact's email", 500);
        }
      }
    }
    catch (CRM_Mailchimp_DuplicateContactsException $e) {
      // We cannot process this webhook.
      throw new RuntimeException("Duplicate contact: " . $e->getMessage(), 500);
    }
  }
  /**
   * Mailchimp still sends interests to webhooks in an old school way.
   *
   * So it's left to us to identify the interests and groups that they refer to.
   */
  public function updateInterestsFromMerges() {

    // Get a list of CiviCRM group Ids that this contact should be in.
    $should_be_in = $this->sync->splitMailchimpWebhookGroupsToCiviGroupIds($this->request_data['merges']['INTERESTS']);

    // Now get a list of all the groups they *are* in.
    $result = civicrm_api3('Contact', 'getsingle', ['return' => 'group', 'contact_id' => $this->contact_id]);
    $is_in = CRM_Mailchimp_Utils::getGroupIds($result['groups'], $this->sync->interest_group_details);

    // Finally loop all the mapped interest groups and process any differences.
    foreach ($this->sync->interest_group_details as $group_id => $details) {
      if ($details['is_mc_update_grouping'] == 1) {
        // We're allowed to update Civi from Mailchimp for this one.
        if (in_array($group_id, $should_be_in) && !in_array($group_id, $is_in)) {
          // Not in this group, but should be.
          civicrm_api3('GroupContact', 'create', [
            'contact_id' => $this->contact_id,
            'group_id' => $group_id,
            'status' => 'Added',
          ]);
        }
        elseif (!in_array($group_id, $should_be_in) && in_array($group_id, $is_in)) {
          // Is in this group, but should not be.
          civicrm_api3('GroupContact', 'create', [
            'contact_id' => $this->contact_id,
            'group_id' => $group_id,
            'status' => 'Removed',
          ]);
        }
      }
    }
  }

}
