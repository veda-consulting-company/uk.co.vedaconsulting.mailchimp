<?php
/**
 * @file
 * This class holds all the sync logic for a particular list.
 */
class CRM_Mailchimp_Sync {
  /**
   * Holds the Mailchimp List ID.
   *
   * This is accessible read-only via the __get().
   */
  protected $list_id;
  /**
   * Cache of details from CRM_Mailchimp_Utils::getGroupsToSync.
   ▾ $this->group_details['61'] = (array [12])
     ⬦ $this->group_details['61']['list_id'] = (string [10]) `4882f4fdb8`
     ⬦ $this->group_details['61']['category_id'] = (null)
     ⬦ $this->group_details['61']['category_name'] = (null)
     ⬦ $this->group_details['61']['interest_id'] = (null)
     ⬦ $this->group_details['61']['interest_name'] = (null)
     ⬦ $this->group_details['61']['is_mc_update_grouping'] = (string [1]) `0`
     ⬦ $this->group_details['61']['civigroup_title'] = (string [28]) `mailchimp_integration_test_1`
     ⬦ $this->group_details['61']['civigroup_uses_cache'] = (bool) 0
     ⬦ $this->group_details['61']['grouping_id'] = (null)
     ⬦ $this->group_details['61']['grouping_name'] = (null)
     ⬦ $this->group_details['61']['group_id'] = (null)
     ⬦ $this->group_details['61']['group_name'] = (null)
   */
  protected $group_details;
  /**
   * As above but without membership group.
   */
  protected $interest_group_details;
  /**
   * As above but groupep by category.
   */
  protected $interest_group_details_by_category;
  /**
   * The CiviCRM group id responsible for membership at Mailchimp.
   */
  protected $membership_group_id;

  /** If true no changes will be made to Mailchimp or CiviCRM. */
  protected $dry_run = FALSE;
  public function __construct($list_id) {
    $this->list_id = $list_id;
    $this->group_details = CRM_Mailchimp_Utils::getGroupsToSync($groupIDs=[], $list_id, $membership_only=FALSE);
    foreach ($this->group_details as $group_id => $group_details) {
      if (empty($group_details['category_id'])) {
        $this->membership_group_id = $group_id;
      }
    }
    if (empty($this->membership_group_id)) {
      throw new InvalidArgumentException("Failed to find mapped membership group for list '$list_id'");
    }
    // Also cache without the membership group, i.e. interest groups only.
    $this->interest_group_details = $this->group_details;
    unset($this->interest_group_details[$this->membership_group_id]);

    // for mailchimp API v3, we need a hierarchized interest group details (group by mailchimp id)
    $group_details_by_category = array();
    foreach ($this->interest_group_details as $civi_group_id => $details) {
      if (!array_key_exists($details['category_id'], $group_details_by_category)) {
        $group_details_by_category[$details['category_id']] = array();
      }
      $group_details_by_category[$details['category_id']][$civi_group_id] = $details;
    }
    $this->interest_group_details_by_category = $group_details_by_category;
  }
  /**
   * Getter.
   */
  public function __get($property) {
    switch ($property) {
    case 'list_id':
    case 'membership_group_id':
    case 'group_details':
    case 'interest_group_details':
    case 'dry_run':
      return $this->$property;
    }
    throw new InvalidArgumentException("'$property' property inaccessible or unknown");
  }
  /**
   * Setter.
   */
  public function __set($property, $value) {
    switch ($property) {
    case 'dry_run':
      return $this->$property = (bool) $value;
    }
    throw new InvalidArgumentException("'$property' property inaccessible or unknown");
  }
  // The following methods are the key steps of the pull and push syncs.
  /**
   * Collect Mailchimp data into temporary working table.
   *
   * There are two modes of operation:
   *
   * In **pull** mode we only collect data that comes from Mailchimp that we are
   * allowed to update in CiviCRM.
   *
   * In **push** mode we collect data that we would update in Mailchimp from
   * CiviCRM.
   *
   * Crucially the difference is for CiviCRM groups mapped to a Mailchimp
   * interest: these can either allow updates *from* Mailchimp or not. Typical
   * use case is a hidden-from-subscriber 'interest' called 'donor type' which
   * might include 'major donor' and 'minor donor' based on some valuation by
   * the organisation recorded in CiviCRM groups.
   *
   * @param string $mode pull|push.
   * @return int   number of contacts collected.
   */
  public function collectMailchimp($mode) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncCollectMailchimp $this->list_id= ', $this->list_id);
    if (!in_array($mode, ['pull', 'push'])) {
      throw new InvalidArgumentException(__FUNCTION__ . " expects push/pull but called with '$mode'.");
    }
    $dao = static::createTemporaryTableForMailchimp();

    // Cheekily access the database directly to obtain a prepared statement.
    $db = $dao->getDatabaseConnection();
    $insert = $db->prepare('INSERT INTO tmp_mailchimp_push_m
             (email, first_name, last_name, hash, interests, checksum, addr1, addr2, city, state, zip, country, custom_fields, tags)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

    CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Form_Sync syncCollectMailchimp: ', $this->interest_group_details);

    $api = CRM_Mailchimp_Utils::getMailchimpApi();
    $offset = 0;
    $batch_size = 1000;
    $total = null;
    $list_id = $this->list_id;
    $fetch_batch = function() use($api, &$offset, &$total, $batch_size, $list_id) {
      if ($total !== null && $offset >= $total) {
        // End of results.
        return [];
      }
      $response = $api->get("/lists/$this->list_id/members", [
        'offset' => $offset, 'count' => $batch_size,
        'status' => 'subscribed',
        'fields' => 'total_items,members.email_address,members.merge_fields,members.interests,members.tags'
      ]);
      $total = (int) $response->data->total_items;
      $offset += $batch_size;
      return $response->data->members;
    };
    $profileFields = self::getProfileFieldsToSync();

    //
    // Main loop of all the records.
    $collected = 0;
    while ($members = $fetch_batch()) {
      $start = microtime(TRUE);
      foreach ($members as $member) {
        $first_name = isset($member->merge_fields->FNAME) ? $member->merge_fields->FNAME : '';
        $last_name  = isset($member->merge_fields->LNAME) ? $member->merge_fields->LNAME : '';

        if (!$first_name && !$last_name && !empty($member->merge_fields->NAME)) {
          // No first or last names received, but we have a NAME merge field so
          // try splitting that.
          $names = explode(' ', $member->merge_fields->NAME);
          $first_name = trim(array_shift($names));
          if ($names) {
            // Rest of names go as last name.
            $last_name = implode(' ', $names);
          }
        }
        $checksum = $mode == 'push' && isset($member->merge_fields->CHECKSUM) ? $member->merge_fields->CHECKSUM : '';

        // Address fields
        $addr1 = $addr2 = $city = $state = $zip = $country = '';
        if (isset($member->merge_fields->ADDRESS)) {
          $addr1 = $member->merge_fields->ADDRESS->addr1;
          $addr2 = $member->merge_fields->ADDRESS->addr2;
          $city = $member->merge_fields->ADDRESS->city;
          $state = $member->merge_fields->ADDRESS->state;
          $zip = $member->merge_fields->ADDRESS->zip;
          $country = $member->merge_fields->ADDRESS->country;
        }
        // Custom merge fields.
        $merge_fields = [];
        foreach ($profileFields as $name => $field) {
          $tag = strtoupper($name);
          if (isset($member->merge_fields->{$tag})) {
            $merge_fields[$tag] = $member->merge_fields->{$tag};
          }
        }
        $custom_fields = $merge_fields ? serialize($merge_fields) : '';
        // Member tags in Mailchimp
        $mailchimpTags = [];
        if (isset($member->tags)) {
          foreach ($member->tags as $ignore => $tag) {
            $mailchimpTags[] = $tag->name;
          }
        }

        $tags = '';
        if (!empty($mailchimpTags)) {
          $tags = serialize(implode(',', $mailchimpTags));
        }

        // Find out which of our mapped groups apply to this subscriber.
        // Serialize the grouping array for SQL storage - this is the fastest way.
        if (isset($member->interests)) {
          $interests = serialize($this->getComparableInterestsFromMailchimp($member->interests, $mode));
        }
        else {
          // Can't be NULL as the DB will reject this, so empty string.
          $interests = '';
        }

        // we're ready to store this but we need a hash that contains all the info
        // for comparison with the hash created from the CiviCRM data (elsewhere).
        //
        // Previous algorithms included email here, but we actually allow
        // mailchimp to have any email that belongs to the contact in the
        // membership group, even though for new additions we'd use the bulk
        // email. So we don't count an email mismatch as a problem.
        // $hash = md5($member->email_address . $first_name . $last_name . $interests);
        $hash = md5($first_name . $last_name . $interests);
        // run insert prepared statement
        $result = $db->execute($insert, [
          $member->email_address,
          $first_name,
          $last_name,
          $hash,
          $interests,
          $checksum,
          $addr1,
          $addr2,
          $city,
          $state,
          $zip,
          $country,
          $custom_fields,
          $tags
        ]);
        if ($result instanceof DB_Error) {
          throw new Exception ($result->message . "\n" . $result->userinfo);
        }
        $collected++;
      }
      CRM_Mailchimp_Utils::checkDebug('collectMailchimp took ' . round(microtime(TRUE) - $start,2) . 's to copy ' . count($members) . ' mailchimp Members to tmp table.');
    }

    // Tidy up.
    $db->freePrepared($insert);
    return $collected;
  }

  /**
   * Determine whether to push interest groups for any contacts sharing an email.
   * @return boolean
   */
  public function isCollectGroupsByEmail() {
    foreach ($this->interest_group_details as $civi_group_id => $details) {
      if ($details['is_mc_update_grouping'] == 1) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Collect CiviCRM data into temporary working table.
   *
   * Speed notes.
   *
   * Various strategies have been tried here to speed things up. Originally we
   * used the API with a chained API call, but this was very slow (~10s for
   * ~5k contacts), so now we load all the contacts, then all the emails in a
   * 2nd API call. This is about 10x faster, taking less than 1s for ~5k
   * contacts. Likewise the structuring of the emails on the contact array has
   * been tried various ways, and this structure-by-type way has reduced the
   * origninal loop time from 7s down to just under 4s.
   *
   *
   * @param string $mode pull|push.
   * @return int number of contacts collected.
   */
  public function collectCiviCrm($mode) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncCollectCiviCRM $this->list_id= ', $this->list_id);
    if (!in_array($mode, ['pull', 'push'])) {
      throw new InvalidArgumentException(__FUNCTION__ . " expects push/pull but called with '$mode'.");
    }
    // Cheekily access the database directly to obtain a prepared statement.
    $dao = static::createTemporaryTableForCiviCRM();
    $db = $dao->getDatabaseConnection();

    // There used to be a distinction between the handling of 'normal' groups
    // and smart groups. But now the API will take care of this but this
    // requires the following function to have run.
    foreach ($this->interest_group_details as $group_id => $details) {
      if ($mode == 'push' || $details['is_mc_update_grouping'] == 1) {
        // Either we are collecting for a push from C->M,
        // or we're pulling and this group is configured to allow updates.
        // Therefore we need to make sure the cache is filled.
        CRM_Contact_BAO_GroupContactCache::loadAll($group_id);
      }
    }

    // Use a nice API call to get the information for tmp_mailchimp_push_c.
    // The API will take care of smart groups.
    $start = microtime(TRUE);
    $return_fields = [
      'first_name',
      'last_name',
      'group',
      'street_address',
      'supplemental_address_1',
      'city',
      'state_province',
      'postal_code',
      'country',
      'tag'
    ];
    $custom_fields = self::getProfileFieldsToSync();
    if ($custom_fields) {
      $return_fields = array_merge($return_fields, array_keys($custom_fields));
    }
    $result = civicrm_api3('Contact', 'get', [
      'is_deleted' => 0,
      // The email filter in comment below does not work (CRM-18147)
      // 'email' => array('IS NOT NULL' => 1),
      // Now I think that on_hold is NULL when there is no e-mail, so if
      // we are lucky, the filter below implies that an e-mail address
      // exists ;-)
      'is_opt_out' => 0,
      'do_not_email' => 0,
      'on_hold' => 0,
      'is_deceased' => 0,
      // TODO: Unless the group is set as parent for the groups linked MC interest-groups, there is the possibility
      // of contacts not being synched to the audience.
      // Perhaps they should be included here.
      'group' => $this->membership_group_id,
      'return' => $return_fields,
      'options' => ['limit' => 0],
      //'api.Email.get' => ['on_hold'=>0, 'return'=>'email,is_bulkmail'],
    ]);

    if ($result['count'] == 0) {
      // No-one is in the group according to CiviCRM.
      return 0;
    }

    // Load emails for these contacts.
    $emails = civicrm_api3('Email', 'get', [
      'on_hold' => 0,
      'return' => 'contact_id,email,is_bulkmail,is_primary',
      'contact_id' => ['IN' => array_keys($result['values'])],
      'options' => ['limit' => 0],
    ]);
    $groupsByEmail = [];
    // Index emails by contact_id.
    foreach ($emails['values'] as $email) {
      if (empty($email['email']) || !filter_var($email['email'], FILTER_VALIDATE_EMAIL)) {
        continue;
      }
      if ($email['is_bulkmail']) {
        $result['values'][$email['contact_id']]['bulk_email'] = $email['email'];
      }
      elseif ($email['is_primary']) {
        $result['values'][$email['contact_id']]['primary_email'] = $email['email'];
      }
      else {
        $result['values'][$email['contact_id']]['other_email'] = $email['email'];
      }
    }
    // Determine which email to use in mailchimp subscription.
    // If this is a push, collect groups  by email so we can preserve interest groups for contacts sharing an email address.
    // mailchimp contacts are identified by email.
    $groupsByEmail = [];
    $isGroupByEmail = $this->isCollectGroupsByEmail();
    foreach ($result['values'] as $id => $contact) {
      // Which email to use?
      $email = isset($contact['bulk_email'])
        ? $contact['bulk_email']
        : (isset($contact['primary_email'])
          ? $contact['primary_email']
          : (isset($contact['other_email'])
            ? $contact['other_email']
            : NULL));

      if (!$email || empty($contact['groups'])) {
        continue;
      }
      // Add email to contact.
      $result['values'][$id]['email'] = $email;


      if ($mode != 'push' || !$isGroupByEmail) {
        continue;
      }
      if (empty($groupsByEmail[$email])) {
        // Groups are comma-separated string.
        $groupsByEmail[$email] = $contact['groups'];
      }
      else {
        $groupsByEmail[$email] .= ',' . $contact['groups'];
      }
    }

    // Add data to temp table for comparison with mailchimp data.
    $start = microtime(TRUE);

    $collected = 0;
    $insert = $db->prepare('INSERT IGNORE INTO tmp_mailchimp_push_c VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    // Note hashes being inserted to prevent db duplicate errors.
    $hashes = [];
    // Loop contacts:
    foreach ($result['values'] as $id => $contact) {
      if (empty($contact['email'])) {
        continue;
      }
      $email = $contact['email'];
      $checksum = '';
      $contact_custom = [];
      // Custom fields from profile.
      foreach ($custom_fields as $name => $field) {
        if (isset($contact[$name])) {
          $tag = strtoupper($name);
          $contact_custom[$tag] = $contact[$name];
        }
      }
      $custom_data = $contact_custom ? serialize($contact_custom) : '';

      $tags = $contact['tags'] ? serialize($contact['tags']) : '';

      // Use the groups by email or use only the contact's groups.
      $groups = !empty($groupsByEmail[$email])
        ? implode(',', array_unique(explode(',', $groupsByEmail[$email])))
        : $contact['groups'];
      $info = $this->getComparableInterestsFromCiviCrmGroups($groups, $mode);

      // OK we should now have all the info we need.
      // Serialize the grouping array for SQL storage - this is the fastest way.
      $info = serialize($info);

      // we're ready to store this but we need a hash that contains all the info
      // for comparison with the hash created from the CiviCRM data (elsewhere).
      //          email,           first name,      last name,      groupings
      // See note above about why we don't include email in the hash.
      // $hash = md5($email . $contact['first_name'] . $contact['last_name'] . $info);
      $hash = md5($contact['first_name'] . $contact['last_name'] . $info . $contact['id']);

      // Prevent duplicate rows db error.
      if (!empty($hashes[$hash . $email])) {
        continue;
      }
      else {
        $hashes[$hash . $email] = 1;
      }
      // run insert prepared statement
      try {
        $db->execute($insert, array(
          $contact['id'],
          trim($email),
          $contact['first_name'],
          $contact['last_name'],
          $hash,
          $info,
          $checksum,
          $contact['street_address'],
          $contact['supplemental_address_1'],
          $contact['city'],
          $contact['state_province_name'],
          $contact['postal_code'],
          $contact['country'],
          $custom_data,
          $tags
        ));
      }
      catch (PEAR_Exception $e) {
        if (get_class($e->getCause()) == 'DB_Error') {
          // Oops, issue #225.
          // https://github.com/veda-consulting/uk.co.vedaconsulting.mailchimp/issues/225
          // I have no time to fix this right now, but we can at least log it
          // instead of crashing the sync.
          CRM_Mailchimp_Utils::checkDebug("Issue #225 for {$contact['first_name']} {$contact['last_name']} ({$email}), list ID: {$this->list_id}.");
        }
        else {
          // Something else. Rethrow.
          throw $e;
        }
      }
      $collected++;
    }

    // Tidy up.
    $db->freePrepared($insert);

    return $collected;
  }

  /**
   * Match mailchimp records to particular contacts in CiviCRM.
   *
   * This requires that both collect functions have been run in the same mode
   * (push/pull).
   *
   * First we attempt a number of SQL based strategies as these are the fastest.
   *
   * If the fast SQL matches have failed, we need to do it the slow way.
   *
   * @return array of counts - for tests really.
   * - bySubscribers
   * - byUniqueEmail
   * - byNameEmail
   * - bySingle
   * - totalMatched
   * - newContacts (contacts that should be created in CiviCRM)
   * - failures (duplicate contacts in CiviCRM)
   */
  public function matchMailchimpMembersToContacts() {
    // Ensure we have the mailchimp_log table.
    $dao = CRM_Core_DAO::executeQuery(
      "CREATE TABLE IF NOT EXISTS mailchimp_log (
        id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        group_id int(20),
        email VARCHAR(200),
        name VARCHAR(200),
        message VARCHAR(512),
        KEY (group_id)
        );");
    // Clear out any old errors to do with this list.
    CRM_Core_DAO::executeQuery(
      "DELETE FROM mailchimp_log WHERE group_id = %1;",
      [1 => [$this->membership_group_id, 'Integer' ]]);

    $stats = [
      'bySubscribers' => 0,
      'byUniqueEmail' => 0,
      'byNameEmail' => 0,
      'bySingle' => 0,
      'totalMatched' => 0,
      'newContacts' => 0,
      'failures' => 0,
    ];

    // Do the fast SQL identification against CiviCRM contacts.
    $start = microtime(TRUE);
    $stats['bySubscribers'] = static::guessContactIdsBySubscribers();
    CRM_Mailchimp_Utils::checkDebug('guessContactIdsBySubscribers took ' . round(microtime(TRUE) - $start, 2) . 's');
    $start = microtime(TRUE);
    $stats['byUniqueEmail'] = static::guessContactIdsByUniqueEmail();
    CRM_Mailchimp_Utils::checkDebug('guessContactIdsByUniqueEmail took ' . round(microtime(TRUE) - $start, 2) . 's');
    $start = microtime(TRUE);
    $stats['byNameEmail'] = static::guessContactIdsByNameAndEmail();
    CRM_Mailchimp_Utils::checkDebug('guessContactIdsByNameAndEmail took ' . round(microtime(TRUE) - $start, 2) . 's');
    $start = microtime(TRUE);

    // Now slow match the rest.
    $dao = CRM_Core_DAO::executeQuery( "SELECT * FROM tmp_mailchimp_push_m m WHERE cid_guess IS NULL;");
    $db = $dao->getDatabaseConnection();
    $update = $db->prepare('UPDATE tmp_mailchimp_push_m
      SET cid_guess = ? WHERE email = ? AND hash = ?');
    $failures = $new = 0;
    while ($dao->fetch()) {
      try {
        $contact_id = $this->guessContactIdSingle($dao->email, $dao->first_name, $dao->last_name);
        if (!$contact_id) {
          // We use zero to mean create a contact.
          $contact_id = 0;
          $new++;
        }
        else {
          // Successful match.
          $stats['bySingle']++;
        }
      }
      catch (CRM_Mailchimp_DuplicateContactsException $e) {
        $contact_id = NULL;
        $failures++;
      }
      if ($contact_id !== NULL) {
        // Contact found, or a zero (create needed).
        $result = $db->execute($update, [
          $contact_id,
          $dao->email,
          $dao->hash,
        ]);
        if ($result instanceof DB_Error) {
          throw new Exception ($result->message . "\n" . $result->userinfo);
        }
      }
    }
    $db->freePrepared($update);

    $took = microtime(TRUE) - $start;
    $took = round($took, 2);
    $secs_per_rec = $stats['bySingle'] ?
      round($took / $stats['bySingle'],2) : 0;
    CRM_Mailchimp_Utils::checkDebug("guessContactIdSingle took {$took} sec " .
      "for {$stats['bySingle']} records ({$secs_per_rec} s/record)");

    $stats['totalMatched'] = array_sum($stats);
    $stats['newContacts'] = $new;
    $stats['failures'] = $failures;

    if ($stats['failures']) {
      // Copy errors into the mailchimp_log table.
      CRM_Core_DAO::executeQuery(
        "INSERT INTO mailchimp_log (group_id, email, name, message)
         SELECT %1 group_id,
          email,
          CONCAT_WS(' ', first_name, last_name) name,
          'titanic' message
         FROM tmp_mailchimp_push_m
         WHERE cid_guess IS NULL;",
      [1 => [$this->membership_group_id, 'Integer']]);
    }

    return $stats;
  }

  /**
   * Merges the interest groups for contacts with the same email address.
   */
  public function mergeCiviInterestGroupsForDuplicateEmails() {
    // Retrieve duplicate emails with their interest groups.
    $sql = "SELECT c1.contact_id, c1.first_name, c1.last_name, c1.email, c1.interests, c1.contact_id, m.cid_guess
      FROM tmp_mailchimp_push_c c1
      LEFT JOIN mailchimp_push_m m ON c.contact_id = m.cid_guess
      WHERE c1.email IN (
       SELECT c2.email FROM tmp_mailchimp_push_c c2 GROUP BY c2.email HAVING COUNT(distinct c2.interests) > 1)
       ORDER BY c1.email ASC;";
    $dao = CRM_Core_DAO::executeQuery($sql);
    // Groups keyed by email.
    $groupsByEmail = [];
    // Contact representing the email (where a contact has already been mapped in mailchimp).
    // We need these details to rebuild the hash with any group changes.
    $emailContacts = [];

    // Interest Group subscriptions for a contact is stored in the temp table as
    // an associative array of boolean values, keyed by the id of mc interest group.
    // To merge 2 sets of group, take the  OR of each group.
    $mergeGroups = function ($g1, $g2) {
      $merged = [];
      foreach ($g1 as $groupID => $value) {
        $merged[$groupID] = $value || !empty($g2[$groupID]);
      }
      return $merged;
    };

    // Collect the group data.
    while ($dao->fetch()) {
      if (!empty($dao->cid_guess)) {
        $emailContacts[$dao->email] = [
          'first_name' => $dao->first_name,
          'last_name' => $dao->last_name,
          'contact_id' => $dao->contact_id,
        ];
      }
      $emailGroups = !empty($groupsByEmail[$dao->email]) ? $groupsByEmail[$dao->email] : [];
      $contactGroups = unserialize($dao->interests);
      if ($contactGroups && is_array($contactGroups)) {
        $groupsByEmail[$dao->email] = $mergeGroups($emailGroups, $contactGroups);
      }
    }
    // Save to temp table.
    foreach ($groupsByEmail as $email => $groups) {
      // Format the interests to store.
      $interests = serialize($groups);
      ksort($interests);
      $params = [];
      // There is already a subscriber in mailchimp.
      if (!empty($contactGroups[$email]['contact_id'])) {
        // Rebuild the hash.
        $contact = $contactGroups[$email];
        $hash = md5($contact['first_name'] . $contact['last_name'] . $interests);
        // Save to the contact row.
        $query = "UPDATE mailchimp_push_c SET hash = %1, interests = %2 WHERE contact_id = %3";
        $params = [
          1 => [$hash, 'String'],
          2 => [$interests, 'String'],
          3 => [$contact['contact_id'], 'Integer'],
        ];
      }
      else {
        // Save interest groups
        $query = "UPDATE mailchimp_push_c SET interests = %1 WHERE email = %2";
        $params = [
          1 => [$interests, 'String'],
          2 => [$email, 'String'],
        ];
      }
      CRM_Core_DAO::executeQuery($query, $params);
    }
  }

  /**
   * Removes from the temporary tables those records that do not need processing
   * because they are identical.
   *
   * In *push* mode this will also remove any rows in the CiviCRM temp table
   * where there's an email match in the mailchimp table but the cid_guess is
   * different. This is to cover the case when two contacts in CiviCRM have the
   * same email and both are added to the membership group. Without this the
   * Push operation would attempt to craeate a 2nd Mailchimp member but with the
   * email address that's already on the list. This would mean the names kept
   * getting flipped around since it would be updating the same member twice -
   * very confusing.
   *
   * So for deleting the contacts from the CiviCRM table on *push* we avoid
   * this. However on *pull* we leave the contact in the table - they will then
   * get removed from the group, leaving just the single contact/member with
   * that particular email address.
   *
   * @param string $mode pull|push.
   * @return int
   */
  public function removeInSync($mode) {

    // In push mode, delete duplicate CiviCRM contacts.
    $doubles = 0;
    if ($mode == 'push') {
      $all_groups_by_email = [];

      $doubles = CRM_Mailchimp_Sync::runSqlReturnAffectedRows(
        'DELETE c
         FROM tmp_mailchimp_push_c c
         INNER JOIN tmp_mailchimp_push_m m ON c.email=m.email AND m.cid_guess != c.contact_id;
        ');
      if ($doubles) {
        CRM_Mailchimp_Utils::checkDebug("removeInSync removed $doubles contacts who are in the membership group but have the same email address as another contact that is also in the membership group.");
      }
    }

    // Delete records have the same hash - these do not need an update.
    // count for testing purposes.
    $dao = CRM_Core_DAO::executeQuery("SELECT COUNT(c.email) co FROM tmp_mailchimp_push_m m
      INNER JOIN tmp_mailchimp_push_c c ON m.cid_guess = c.contact_id AND m.hash = c.hash;");
    $dao->fetch();
    $count = $dao->co;
    if ($count > 0) {
      CRM_Core_DAO::executeQuery(
        "DELETE m, c
         FROM tmp_mailchimp_push_m m
         INNER JOIN tmp_mailchimp_push_c c ON m.cid_guess = c.contact_id AND m.hash = c.hash;");
    }
    CRM_Mailchimp_Utils::checkDebug("removeInSync removed $count in-sync contacts.");


    return $count + $doubles;
  }
  /**
   * "Push" sync.
   *
   * Sends additions, edits (compared to tmp_mailchimp_push_m), deletions.
   *
   * Note that an 'update' counted in the return stats could be a change or an
   * addition.
   *
   * @return array ['updates' => INT, 'unsubscribes' => INT]
   */
  public function updateMailchimpFromCivi() {
    CRM_Mailchimp_Utils::checkDebug("updateMailchimpFromCivi for group #$this->membership_group_id");
    $operations = [];
    $api = CRM_Mailchimp_Utils::getMailchimpApi();
    $dao = CRM_Core_DAO::executeQuery(
      "SELECT
      c.interests c_interests, c.first_name c_first_name, c.last_name c_last_name,
      c.email c_email, c.contact_id c_contact_id, c.checksum c_checksum,
      c.addr1 c_addr1, c.addr2 c_addr2, c.city c_city,
      c.state c_state, c.zip c_zip, c.country c_country, c.custom_fields c_custom_fields, c.tags c_tags,
      m.interests m_interests, m.first_name m_first_name, m.last_name m_last_name,
      m.email m_email, m.checksum m_checksum, m.addr1 m_addr1, m.addr2 m_addr2, m.city m_city,
      m.state m_state, m.zip m_zip, m.country m_country, m.custom_fields m_custom_fields, m.tags m_tags
      FROM tmp_mailchimp_push_c c
      LEFT JOIN tmp_mailchimp_push_m m ON c.email = m.email;");

    // Check if syncTags is enabled in mailchmip setting.
    $syncTag = Civi::settings()->get('mailchimp_sync_tags');

    $url_prefix = "/lists/$this->list_id/members/";
    $changes = $additions = 0;
    // We need to know that the mailchimp list has certain merge fields.
    $result = $api->get("/lists/$this->list_id/merge-fields", ['fields' => 'merge_fields.tag'])->data->merge_fields;
    $merge_fields = [];
    foreach ($result as $field) {
      $merge_fields[$field->tag] = TRUE;
    }

    while ($dao->fetch()) {

      $params = static::updateMailchimpFromCiviLogic(
        $merge_fields,
        ['email' => $dao->c_email, 'first_name' => $dao->c_first_name, 'last_name' => $dao->c_last_name, 'interests' => $dao->c_interests, 'contact_id' => $dao->c_contact_id, 'checksum' => $dao->c_checksum, 'addr1' => $dao->c_addr1, 'addr2' => $dao->c_addr2, 'city' => $dao->c_city, 'state' => $dao->c_state, 'zip' => $dao->c_zip, 'country' => $dao->c_country, 'custom_fields' => $dao->c_custom_fields],
        ['email' => $dao->m_email, 'first_name' => $dao->m_first_name, 'last_name' => $dao->m_last_name, 'interests' => $dao->m_interests, 'checksum' => $dao->m_checksum, 'addr1' => $dao->m_addr1, 'addr2' => $dao->m_addr2, 'city' => $dao->m_city, 'state' => $dao->m_state, 'zip' => $dao->m_zip, 'country' => $dao->m_country, 'custom_fields' => $dao->c_custom_fields]);

      if (!$params) {
        // This is the case if the changes could not be made due to policy
        // reasons, e.g. a missing name in CiviCRM should not overwrite a
        // provided name in Mailchimp; this is a difference but it's not one we
        // will correct.
        continue;
      }

      /*
      Add or remove tags from a list member.
      If a tag that does not exist is passed in and set as 'active', a new tag will be created in Mailchimp
      */
      $tagsParams = array();

      if ($syncTag == 1) {
        $civiTags = $dao->c_tags ? explode(',', unserialize($dao->c_tags)) : array();
        $mailchimpTags = $dao->m_tags ? explode(',', unserialize($dao->m_tags)) : array();

        // pushing all the tags from Civi to Mailchimp
        foreach ($civiTags as $ignore => $tagName) {
          $tagsParams[] = array(
            'name' => $tagName,
            'status' => 'active'
          );
        }

        //Remove the tags in Mailchimp, if not in Civitags array (setting status inactive will remove the tag from the member)
        foreach ($mailchimpTags as $ignore => $tagName) {
          if (!in_array($tagName, $civiTags)) {
            $tagsParams[] = array(
              'name' => $tagName,
              'status' => 'inactive'
            );
          }
        }
      }
      if ($this->dry_run) {
        // Log the operation description.
        $_ = "Would " . ($dao->m_email ? 'update' : 'create')
          . " mailchimp member: $dao->m_email";
        if (key_exists('email_address', $params)) {
          $_ .= " change email to '$params[email_address]'";
        }
        if (key_exists('merge_fields', $params)) {
          foreach ($params['merge_fields'] as $field=>$value) {
            $_ .= " set $field = $value";
          }
        }
        CRM_Mailchimp_Utils::checkDebug($_);
        if (!empty($tagsParams)) {
          $_tag = "Would update mailchimp member: $dao->m_email set tags = ";
          CRM_Mailchimp_Utils::checkDebug($_tag, $tagsParams);
        }
      }
      else {
        // Add the operation to the batch.
        $params['status'] = 'subscribed';
        $operations[] = ['PUT', $url_prefix . md5(strtolower($dao->c_email)), $params];

        if ($syncTag == 1 && !empty($tagsParams)) {
          // Add POST tag operation to the batch.
          $operations[] = ['POST', $url_prefix . md5(strtolower($dao->c_email)) . '/tags', array('tags' => $tagsParams)];
        }
      }

      if ($dao->m_email) {
        $changes++;
      } else {
        $additions++;
      }
    }

    // Now consider deletions of those not in membership group at CiviCRM but
    // there at Mailchimp.
    $removals = $this->getEmailsNotInCiviButInMailchimp();
    $unsubscribes = count($removals);
    if ($this->dry_run) {
      // Just log.
      if ($unsubscribes) {
        CRM_Mailchimp_Utils::checkDebug("Total of " . count($unsubscribes) . " Mailchimp members are not in CiviCRM: " . implode(', ', $removals));
      }
      else {
        CRM_Mailchimp_Utils::checkDebug("No Mailchimp members would be unsubscribed.");
      }
    }
    else {
      // For real, not dry run.
	  // We are not unsubcribing Mailchimp members from CiviCRM now since it is no longer reversible from the API.
	  /*
      foreach ($removals as $email) {
        $operations[] = ['PATCH', $url_prefix . md5(strtolower($email)), ['status' => 'unsubscribed']];
      }
	  */
    }

    if (!$this->dry_run && !empty($operations)) {
      // Don't print_r all operations in the debug, because deserializing
      // allocates way too much memory if you have thousands of operations.
      // Also split batches in blocks of MAILCHIMP_MAX_REQUEST_BATCH_SIZE to
      // avoid memory limit problems.
      $batches = array_chunk($operations, MAILCHIMP_MAX_REQUEST_BATCH_SIZE, TRUE);
      foreach ($batches as &$batch) {
        CRM_Mailchimp_Utils::checkDebug("Batching " . count($batch) . " operations. ");
        $api->batchAndWait($batch);
      }
      unset($batch);
    }

    return ['additions' => $additions, 'updates' => $changes, 'unsubscribes' => $unsubscribes];
  }

  /**
   * "Pull" sync.
   *
   * Updates CiviCRM from Mailchimp using the tmp_mailchimp_push_[cm] tables.
   *
   * It is assumed that collections (in 'pull' mode) and `removeInSync` have
   * already run.
   *
   * 1. Loop the full tmp_mailchimp_push_m table:
   *
   *    1. Contact identified by collectMailchimp()?
   *       - Yes: update name if different.
   *       - No:  Create or find-and-update the contact.
   *
   *    2. Check for changes in groups; record what needs to be changed for a
   *       batch update.
   *
   * 2. Batch add/remove contacts from groups.
   *
   * @return array With the following keys:
   *
   * - created: was in MC not CiviCRM so a new contact was created
   * - joined : email matched existing contact that was joined to the membership
   *            group.
   * - in_sync: was in MC and on membership group already.
   * - removed: was not in MC but was on membership group, so removed from
   *            membership group.
   * - updated: No. in_sync or joined contacts that were updated.
   *
   * The initials of these categories c, j, i, r correspond to this diagram:
   *
   *     From Mailchimp: ************
   *     From CiviCRM  :         ********
   *     Result        : ccccjjjjiiiirrrr
   *
   * Of the contacts known in both systems (j, i) we also record how many were
   * updated (e.g. name, interests).
   *
   * Work in pass 1:
   *
   * - create|find
   * - join
   * - update names
   * - update interests
   *
   * Work in pass 2:
   *
   * - remove
   */
  public function updateCiviFromMailchimp() {

    // Ensure posthooks don't trigger while we make GroupContact changes.
    CRM_Mailchimp_Utils::$post_hook_enabled = FALSE;

    // This is a functional variable, not a stats. one
    $changes = ['removals' => [], 'additions' => []];

    CRM_Mailchimp_Utils::checkDebug("updateCiviFromMailchimp for group #$this->membership_group_id");

    // Stats.
    $stats = [
      'created' => 0,
      'joined'  => 0,
      'in_sync' => 0,
      'removed' => 0,
      'updated' => 0,
      ];

    // all Mailchimp table *except* titanics: where the contact matches multiple
    // contacts in CiviCRM.
    $dao = CRM_Core_DAO::executeQuery( "SELECT m.*,
      c.contact_id c_contact_id,
      c.interests c_interests, c.first_name c_first_name, c.last_name c_last_name
      FROM tmp_mailchimp_push_m m
      LEFT JOIN tmp_mailchimp_push_c c ON m.cid_guess = c.contact_id
      WHERE m.cid_guess IS NOT NULL
      ;");

    // Create lookup hash to map Mailchimp Interest Ids to CiviCRM Groups.
    $interest_to_group_id = [];
    foreach ($this->interest_group_details as $group_id=>$details) {
      $interest_to_group_id[$details['interest_id']] = $group_id;
    }

    // Loop records found at Mailchimp, creating/finding contacts in CiviCRM.
    while ($dao->fetch()) {
      $existing_contact_changed = FALSE;

      if (!empty($dao->cid_guess)) {
        // Matched existing contact: result: joined or in_sync
        $contact_id = $dao->cid_guess;

        if ($dao->c_contact_id) {
          // Contact is already in the membership group.
          $stats['in_sync']++;
        }
        else {
          // Contact needs joining to the membership group.
          $stats['joined']++;
          if (!$this->dry_run) {
            // Live.
            $changes['additions'][$this->membership_group_id][] = $contact_id;
          }
          else {
            // Dry Run.
            CRM_Mailchimp_Utils::checkDebug("Would add existing contact to membership group. Email: $dao->email Contact Id: $dao->cid_guess");
          }
        }

        // Update the first name and last name of the contacts we know
        // if needed and making sure we don't overwrite
        // something with nothing. See issue #188.
        $edits = static::updateCiviFromMailchimpContactLogic(
          ['first_name' => $dao->first_name,   'last_name' => $dao->last_name],
          ['first_name' => $dao->c_first_name, 'last_name' => $dao->c_last_name]
        );
        if ($edits) {
          if (!$this->dry_run) {
            // There are changes to be made so make them now.
            civicrm_api3('Contact', 'create', ['id' => $contact_id] + $edits);
          }
          else {
            // Dry run.
            CRM_Mailchimp_Utils::checkDebug("Would update CiviCRM contact $dao->cid_guess "
              . (empty($edits['first_name']) ? '' : "First name from $dao->c_first_name to $dao->first_name ")
              . (empty($edits['last_name']) ? '' : "Last name from $dao->c_last_name to $dao->last_name "));
          }
          $existing_contact_changed = TRUE;
        }
      }
      else {
        // Contact does not exist, create a new one.
        if (!$this->dry_run) {
          // Live:
          $result = civicrm_api3('Contact', 'create', [
            'contact_type' => 'Individual',
            'first_name'   => $dao->first_name,
            'last_name'    => $dao->last_name,
            'email'        => $dao->email,
            'sequential'   => 1,
            ]);
          $contact_id = $result['values'][0]['id'];
          $changes['additions'][$this->membership_group_id][] = $contact_id;
        }
        else {
          // Dry Run:
          CRM_Mailchimp_Utils::checkDebug("Would create new contact with email: $dao->email, name: $dao->first_name $dao->last_name");
          $contact_id = 'dry-run';
        }
        $stats['created']++;
      }

      // Do interests need updating?
      if ($dao->c_interests && $dao->c_interests == $dao->interests) {
        // Nothing to change.
      }
      else {
        // Unpack the interests reported by MC
        $mc_interests = unserialize($dao->interests);
        if ($dao->c_interests) {
          // Existing contact.
          $existing_contact_changed = TRUE;
          $civi_interests = unserialize($dao->c_interests);
        }
        else {
          // Newly created contact is not in any interest groups.
          $civi_interests = [];
        }

        // Discover what needs changing to bring CiviCRM inline with Mailchimp.
        foreach ($mc_interests as $interest=>$member_has_interest) {
          if ($member_has_interest && empty($civi_interests[$interest])) {
            // Member is interested in something, but CiviCRM does not know yet.
            if (!$this->dry_run) {
              $changes['additions'][$interest_to_group_id[$interest]][] = $contact_id;
            }
            else {
              CRM_Mailchimp_Utils::checkDebug("Would add CiviCRM contact $dao->cid_guess to interest group "
                . $interest_to_group_id[$interest]);
            }
          }
          elseif (!$member_has_interest && !empty($civi_interests[$interest])) {
            // Member is not interested in something, but CiviCRM thinks it is.
            if (!$this->dry_run) {
              $changes['removals'][$interest_to_group_id[$interest]][] = $contact_id;
            }
            else {
              CRM_Mailchimp_Utils::checkDebug("Would remove CiviCRM contact $dao->cid_guess from interest group "
                . $interest_to_group_id[$interest]);
            }
          }
        }
      }

      if ($existing_contact_changed) {
        $stats['updated']++;
      }
    }

    // And now, what if a contact is not in the Mailchimp list?
    // We must remove them from the membership group.
    // Accademic interest (#188): what's faster, this or a 'WHERE NOT EXISTS'
    // construct?
    $dao = CRM_Core_DAO::executeQuery( "
    SELECT c.contact_id
      FROM tmp_mailchimp_push_c c
      LEFT OUTER JOIN tmp_mailchimp_push_m m ON m.cid_guess = c.contact_id
      WHERE m.email IS NULL;
      ");
    // Collect the contact_ids that need removing from the membership group.
    while ($dao->fetch()) {
      if (!$this->dry_run) {
        $changes['removals'][$this->membership_group_id][] =$dao->contact_id;
      }
      else {
        CRM_Mailchimp_Utils::checkDebug("Would remove CiviCRM contact $dao->contact_id from membership group - no longer subscribed at Mailchimp.");
      }
      $stats['removed']++;
    }

    if (!$this->dry_run) {
      // Log group contacts which are going to be added/removed to/from CiviCRM
      CRM_Mailchimp_Utils::checkDebug('Mailchimp $changes', $changes);

      // Make the changes.
      if ($changes['additions']) {
        // We have some contacts to add into groups...
        foreach($changes['additions'] as $groupID => $contactIDs) {
          CRM_Contact_BAO_GroupContact::addContactsToGroup($contactIDs, $groupID, 'Admin', 'Added');
        }
      }

      if ($changes['removals']) {
        // We have some contacts to add into groups...
        foreach($changes['removals'] as $groupID => $contactIDs) {
          CRM_Contact_BAO_GroupContact::removeContactsFromGroup($contactIDs, $groupID, 'Admin', 'Removed');
        }
      }
    }

    // Re-enable the post hooks.
    CRM_Mailchimp_Utils::$post_hook_enabled = TRUE;

    return $stats;
  }

  // Other methods follow.
  /**
   * Convert a 'groups' string as provided by CiviCRM's API to a structured
   * array of arrays whose keys are Mailchimp interest ids and whos value is
   * boolean.
   *
   * Nb. this is then key-sorted, which results in a standardised array for
   * comparison.
   *
   * @param string $groups as returned by CiviCRM's API.
   * @param string $mode pull|push.
   * @return array of interest_ids to booleans.
   */
  public function getComparableInterestsFromCiviCrmGroups($groups, $mode) {
    $civi_groups = $groups
      ? array_flip(CRM_Mailchimp_Utils::getGroupIds($groups, $this->interest_group_details))
      : [];
    $info = [];
    foreach ($this->interest_group_details as $civi_group_id => $details) {
      if ($mode == 'pull' && $details['is_mc_update_grouping'] != 1) {
        // This group is configured to disallow updates from Mailchimp to
        // CiviCRM.
        continue;
      }
      $info[$details['interest_id']] = key_exists($civi_group_id, $civi_groups);
    }
    ksort($info);
    return $info;
  }

  /**
   * Convert interests object received from the Mailchimp API into
   * a structure identical to that produced by
   * getComparableInterestsFromCiviCrmGroups.
   *
   * Note this will only return information about interests mapped in CiviCRM.
   * Any other interests that may have been created on Mailchimp are not
   * included here.
   *
   * @param object $interests 'interests' as returned by GET
   * /list/.../members/...?fields=interests
   * @param string $mode pull|push.
   */
  public function getComparableInterestsFromMailchimp($interests, $mode) {
    $info = [];
    // If pulling data from Mailchimp to CiviCRM we ignore any changes to
    // interests where such changes are disallowed by configuration.
    $ignore_non_updatables = $mode == 'pull';
    foreach ($this->interest_group_details as $details) {
      if ($ignore_non_updatables && $details['is_mc_update_grouping'] != 1) {
        // This group is configured to disallow updates from Mailchimp to
        // CiviCRM.
        continue;
      }
      $info[$details['interest_id']] = !empty($interests->{$details['interest_id']});
    }
    ksort($info);
    return $info;
  }

  /**
   * Convert an 'INTERESTS' string as provided by Mailchimp's Webhook POST to
   * an array of CiviCRM group ids.
   *
   * Nb. a Mailchimp webhook is the equivalent of a 'pull' operation so we
   * ignore any groups that Mailchimp is not allowed to update.
   *
   * @param string $group_input
   *   As POSTed to Webhook in Mailchimp's merges.INTERESTS data.
   * @return array CiviCRM group IDs.
   */
  public function splitMailchimpWebhookGroupsToCiviGroupIds($group_input) {
    return CRM_Mailchimp_Utils::splitGroupTitlesFromMailchimp($group_input, $this->interest_group_details);
  }

  public function convertMailchimpWebhookGroupsToCiviGroupIds($group_input) {
    return CRM_Mailchimp_Utils::convertMailchimpWebhookGroupsToCiviGroupIds($group_input, $this->interest_group_details_by_category);
  }

  /**
   * Get list of emails to unsubscribe.
   *
   * We *exclude* any emails in Mailchimp that matched multiple contacts in
   * CiviCRM - these have their cid_guess field set to NULL.
   *
   * @return array
   */
  public function getEmailsNotInCiviButInMailchimp() {
    $dao = CRM_Core_DAO::executeQuery(
      "SELECT m.email
       FROM tmp_mailchimp_push_m m
       WHERE cid_guess IS NOT NULL
         AND NOT EXISTS (
           SELECT c.contact_id FROM tmp_mailchimp_push_c c WHERE c.contact_id = m.cid_guess
         );");

    $emails = [];
    while ($dao->fetch()) {
      $emails[] = $dao->email;
    }
    return $emails;
  }
  /**
   * Return a count of the members on Mailchimp from the tmp_mailchimp_push_m
   * table.
   */
  public function countMailchimpMembers() {
    $dao = CRM_Core_DAO::executeQuery("SELECT COUNT(*) c  FROM tmp_mailchimp_push_m");
    $dao->fetch();
    return $dao->c;
  }

  /**
   * Return a count of the members on CiviCRM from the tmp_mailchimp_push_c
   * table.
   */
  public function countCiviCrmMembers() {
    $dao = CRM_Core_DAO::executeQuery("SELECT COUNT(*) c  FROM tmp_mailchimp_push_c");
    $dao->fetch();
    return $dao->c;
  }

  /**
   * Sync a single contact's membership and interests for this list from their
   * details in CiviCRM.
   *
   */
  public function updateMailchimpFromCiviSingleContact($contact_id) {
    $customFields = self::getProfileFieldsToSync();
    $return =  ['first_name', 'last_name', 'email_id', 'email', 'group', 'street_address', 'supplemental_address_1', 'city', 'state_province', 'postal_code', 'country', 'tag'];
    $return = $customFields ? array_merge($return, array_keys($customFields)) : $return;
    // Get all the groups related to this list that the contact is currently in.
    // We have to use this dodgy API that concatenates the titles of the groups
    // with a comma (making it unsplittable if a group title has a comma in it).
    $contact = civicrm_api3('Contact', 'getsingle', [
      'contact_id' => $contact_id,
      'return' => $return,
      'sequential' => 1
      ]);

    $in_groups = CRM_Mailchimp_Utils::getGroupIds($contact['groups'], $this->group_details);
    $currently_a_member = in_array($this->membership_group_id, $in_groups);

    if (empty($contact['email'])) {
      // Without an email we can't do anything.
      return;
    }
    $subscriber_hash = md5(strtolower($contact['email']));
    $api = CRM_Mailchimp_Utils::getMailchimpApi();

    if (!$currently_a_member) {

      // They are not currently a member.
      // We are not unsubscribing Mailchimp members from CiviCRM now.
      CRM_Core_Session::setStatus(ts('This email is not in CiviCRM group now but exists in Mailchimp list; any differences will remain until this is unsubscribed in Mailchimp.'));
      return;
      // We should ensure they are unsubscribed from Mailchimp. They might
      // already be, but as we have no way of telling exactly what just changed
      // at our end, we have to make sure.
      //
      // Nb. we don't bother updating their interests for unsubscribes.
	  /*
      try {
        $result = $api->patch("/lists/$this->list_id/members/$subscriber_hash",
          ['status' => 'unsubscribed']);
      }
      catch (CRM_Mailchimp_RequestErrorException $e) {
        if ($e->response->http_code == 404) {
          // OK. Mailchimp didn't know about them anyway. Fine.
        }
        else {
          CRM_Core_Session::setStatus(ts('There was a problem trying to unsubscribe this contact at Mailchimp; any differences will remain until a CiviCRM to Mailchimp Sync is done.'));
        }
      }
      catch (CRM_Mailchimp_NetworkErrorException $e) {
        CRM_Core_Session::setStatus(ts('There was a network problem trying to unsubscribe this contact at Mailchimp; any differences will remain until a CiviCRM to Mailchimp Sync is done.'));
      }
	  */
      return;
    }

    // Now left with 'subscribe' case.
    //
    // Do this with a PUT as this allows for both updating existing and
    // creating new members.
    $data = [
      'status' => 'subscribed',
      'email_address' => $contact['email'],
      'merge_fields' => [
        'FNAME' => $contact['first_name'],
        'LNAME' => $contact['last_name'],
        ],
    ];
    // Add contact id and checksum merge fields.
    $data['merge_fields']['CONTACT_ID'] = $contact_id;
    $data['merge_fields']['CHECKSUM'] = CRM_Contact_BAO_Contact_Utils::generateChecksum($contact_id, NULL, 24 * 90);

    // Sync Address fields - addr1, city, state, zip, country are mandatory fields to update address in mailchimp. Passing 'n/a', if any of these fields are empty in CiviCRM
    // To Do: Add a setting in CiviCRM to enable/disable sync of address fields and to set the default empty value
    $addr1 = !empty($contact['street_address']) ? $contact['street_address'] : 'n/a';
    $addr2 = !empty($contact['supplemental_address_1']) ? $contact['supplemental_address_1'] : 'n/a';
    $city = !empty($contact['city']) ? $contact['city'] : 'n/a';
    $state = !empty($contact['state_province_name']) ? $contact['state_province_name'] : 'n/a';
    $zip = !empty($contact['postal_code']) ? $contact['postal_code'] : 'n/a';
    $country = !empty($contact['country']) ? $contact['country'] : 'n/a';
    $addressFields = array(
      'addr1'   => $addr1,
      'addr2'   => $addr2,
      'city'    => $city,
      'state'   => $state,
      'zip'     => $zip,
      'country' => $country,
    );
    $data['merge_fields']['ADDRESS'] = $addressFields;
    foreach ($customFields as $name => $field) {
      $tag = strtoupper($name);
      $data['merge_fields'][$tag] = isset($contact[$name]) ? $contact[$name] : '';
    }

    // Do interest groups.
    $data['interests'] = $this->getComparableInterestsFromCiviCrmGroups($contact['groups'], 'push');
    // Do interest groups.
    $data['interests'] = $this->getComparableInterestsFromCiviCrmGroups($contact['groups'], 'push');
    if (empty($data['interests'])) {
      unset($data['interests']);
    }
    try {
      $result = $api->put("/lists/$this->list_id/members/$subscriber_hash", $data);
    }
    catch (CRM_Mailchimp_RequestErrorException $e) {
      CRM_Core_Session::setStatus(ts('There was a problem trying to subscribe this contact at Mailchimp:') . $e->getMessage());
    }
    catch (CRM_Mailchimp_NetworkErrorException $e) {
      CRM_Core_Session::setStatus(ts('There was a network problem trying to unsubscribe this contact at Mailchimp; any differences will remain until a CiviCRM to Mailchimp Sync is done.'));
    }
    // check if syncTags is enabled in mailchimp setting
    $syncTag = Civi::settings()->get('mailchimp_sync_tags');
    $tagsParams = array();
    if ($syncTag == 1) {
      $civiTags = $contact['tags'] ? explode(',', $contact['tags']) : array();

      // pushing all the tags from Civi to Mailchimp
      // FIX ME : We are not checking and removing tags from Mailchimp here
      foreach ($civiTags as $ignore => $tagName) {
        $tagsParams[] = array(
          'name' => $tagName,
          'status' => 'active'
        );
      }

      if (!empty($tagsParams)) {
        try {
          $result = $api->post("/lists/$this->list_id/members/$subscriber_hash/tags", array('tags' => $tagsParams));
	  CRM_Mailchimp_Utils::checkDebug('Push tags on single sync.', $tagsParams);
        } catch (CRM_Mailchimp_RequestErrorException $e) {
          CRM_Core_Session::setStatus(ts('There was a problem trying to sync tags of this contact at Mailchimp:') . $e->getMessage());
        }
        catch (CRM_Mailchimp_NetworkErrorException $e) {
          CRM_Core_Session::setStatus(ts('There was a network problem trying to add tags of this contact at Mailchimp; any differences will remain until a CiviCRM to Mailchimp Sync is done.'));
        }
      }
    }
  }
  /**
   * Identify a contact who is expected to be subscribed to this list.
   *
   * This is used in a couple of cases, for finding a contact from incomming
   * data for:
   * - a possibly new contact,
   * - a contact that is expected to be in this membership group.
   *
   * Here's how we match a contact:
   *
   * - Only non-deleted contacts are returned.
   *
   * - Email is unique in CiviCRM
   *   Contact identified, unless limited to in-group only and not in group.
   *
   * - Email is entered 2+ times, but always on the same contact.
   *   Contact identified, unless limited to in-group only and not in group.
   *
   * - Email belongs to 2+ different contacts. In this situation, if there are
   *   some contacts that are in the membership group, we ignore the other match
   *   candidates. If limited to in-group contacts and there aren't any, we give
   *   up now.
   *
   *   - Email identified if it belongs to only one contact that is in the
   *     membership list.
   *
   *   - Look to the candidates whose last name matches.
   *     - Email identified if there's only one last name match.
   *     - If there are any contacts that also match first name, return one of
   *       these. We say it doesn't matter if there's duplicates - just pick
   *       one since everything matches.
   *
   *   - Email identified if there's a single contact that matches on first
   *     name.
   *
   * We fail with a CRM_Mailchimp_DuplicateContactsException if the email
   * belonged to several contacts and we could not narrow it down by name.
   *
   * @param string $email
   * @param string|null $first_name
   * @param string|null $last_name
   * @param bool $must_be_on_list    If TRUE, only return an ID if this contact
   *                                 is known to be on the list. defaults to
   *                                 FALSE.
   * @throw CRM_Mailchimp_DuplicateContactsException if the email is known bit
   * it fails to identify one contact.
   * @return int|null Contact Id if found.
   */
  public function guessContactIdSingle($email, $first_name=NULL, $last_name=NULL, $must_be_on_list=FALSE) {

    // API call returns all matching emails, and all contacts attached to those
    // emails IF the contact is in our group.
    $result = civicrm_api3('Email', 'get', [
      'sequential'      => 1,
      'email'           => $email,
      'api.Contact.get' => [
              'is_deleted' => 0,
              'return'     => "first_name,last_name"],
    ]);

    // Candidates are any emails that belong to a not-deleted contact.
    $email_candidates = array_filter($result['values'], function($_) {
      return ($_['api.Contact.get']['count'] == 1);
    });
    if (count($email_candidates) == 0) {
      // Never seen that email, mate.
      return NULL;
    }

    // $email_candidates is currently a sequential list of emails. Instead map it to
    // be indexed by contact_id.
    $candidates = [];
    foreach ($email_candidates as $_) {
      $candidates[$_['contact_id']] = $_['api.Contact.get']['values'][0];
    }

    // Now we need to know which, if any of these contacts is in the group.
    // Build list of contact_ids.
    $result = civicrm_api3('Contact', 'get', [
      'group' => $this->membership_group_id,
      'contact_id' => ['IN' => array_keys($candidates)],
      'is_opt_out' => 0,
      'do_not_email' => 0,
      'on_hold' => 0,
      'is_deceased' => 0,
      'return' => 'contact_id',
      ]);
    $in_group = $result['values'];

    // If must be on the membership list, then reduce the candidates to just
    // those on the list.
    if ($must_be_on_list) {
      $candidates = array_intersect_key($candidates, $in_group);
      if (count($candidates) == 0) {
        // This email belongs to a contact *not* in the group.
        return NULL;
      }
    }

    if (count($candidates) == 1) {
      // If there's only one one contact match on this email anyway, then we can
      // assume that's the person. (we make this assumption in
      // guessContactIdsByUniqueEmail too.)
      return key($candidates);
    }

    // Now we're left with the case that the email matched more than one
    // different contact.

    if (count($in_group) == 1) {
      // There's only one contact that is in the membership group with this
      // email, use that.
      return key($in_group);
    }

    // The email belongs to multiple contacts.
    if ($in_group) {
      // There are multiple contacts that share the same email and several are
      // in this group. Narrow our serach to just those in the group.
      $candidates = array_intersect_key($candidates, $in_group);
    }

    // Make indexes on names.
    $last_name_matches = $first_name_matches = [];
    foreach ($candidates as $candidate) {
      if (!empty($candidate['first_name']) && ($first_name == $candidate['first_name'])) {
        $first_name_matches[$candidate['contact_id']] = $candidate;
      }
      if (!empty($candidate['last_name']) && ($last_name == $candidate['last_name'])) {
        $last_name_matches[$candidate['contact_id']] = $candidate;
      }
    }

    // Now see if we can find them by name match.
    if ($last_name_matches) {
      // Some of the contacts have the same last name.
      if (count($last_name_matches) == 1) {
        // Only one contact with this email has the same last name, let's say
        // it's them.
        return key($last_name_matches);
      }
      // Multiple contacts with same last name. Reduce by same first name.
      $last_name_matches = array_intersect_key($last_name_matches, $first_name_matches);
      if (count($last_name_matches) > 0) {
        // Either there was only one with same last and first name.
        // Or, there were multiple contacts, but they have the same email and
        // name so let's say that we're safe enough to pick the first one of
        // them.
        return key($last_name_matches);
      }
    }
    // Last name didn't get there. Final chance. If the email and first name
    // match a single contact, we'll grudgingly(!) say that's OK.
    if (count($first_name_matches) == 1) {
      // Only one contact with this email has the same first name, let's say
      // it's them.
      return key($first_name_matches);
    }

    // The email given belonged to several contacts and we were unable to narrow
    // it down by the names, either. There's nothing we can do here, it's going
    // to get messy.
    throw new CRM_Mailchimp_DuplicateContactsException($candidates);
  }
  /**
   * Guess the contact id for contacts whose email is found in the temporary
   * table made by collectCiviCrm.
   *
   * If collectCiviCrm has been run, then we can identify matching contacts very
   * easily. This avoids problems with multiple contacts in CiviCRM having the
   * same email address but only one of them is subscribed. On push, the interest
   * groups of the other contacts will be carried through.
   *
   * **WARNING** it would be dangerous to run this if collectCiviCrm() had been run
   * on a different list(!). For this reason, these conditions are checked by
   * collectMailchimp().
   *
   * This is in a separate method so it can be tested.
   *
   * @return int affected rows.
   */
  public static function guessContactIdsBySubscribers() {
   // First try matching on name and email.
   $r1 = static::runSqlReturnAffectedRows(
        "UPDATE tmp_mailchimp_push_m m
        INNER JOIN tmp_mailchimp_push_c c
         ON m.email = c.email
         AND m.first_name = c.first_name
         AND m.last_name = c.last_name
        SET m.cid_guess = c.contact_id
        WHERE m.cid_guess IS NULL");

    // Now just email.
    return $r1 + static::runSqlReturnAffectedRows(
       "UPDATE tmp_mailchimp_push_m m
        INNER JOIN tmp_mailchimp_push_c c ON m.email = c.email
        SET m.cid_guess = c.contact_id
        WHERE m.cid_guess IS NULL");
  }

  /**
   * Guess the contact id by there only being one email in CiviCRM that matches.
   *
   * Change in v2.0: it now checks uniqueness by contact id, so if the same
   * email belongs multiple times to one contact, we can still conclude we've
   * got the right contact.
   *
   * This is in a separate method so it can be tested.
   * @return int affected rows.
   */
  public static function guessContactIdsByUniqueEmail() {
    // If an address is unique, that's the one we need.
    return static::runSqlReturnAffectedRows(
        "UPDATE tmp_mailchimp_push_m m
        INNER JOIN (
          SELECT email, c.id AS contact_id
          FROM civicrm_email e
          JOIN civicrm_contact c ON e.contact_id = c.id AND c.is_deleted = 0
          AND c.do_not_email = 0 AND c.do_not_mail = 0 AND c.is_opt_out = 0 AND e.on_hold = 0
          GROUP BY email, c.id
          HAVING COUNT(DISTINCT c.id)=1
          ) uniques ON m.email = uniques.email
        SET m.cid_guess = uniques.contact_id
        WHERE m.cid_guess IS NULL
        ");
  }
  /**
   * Guess the contact id for contacts whose only email matches.
   *
   * This is in a separate method so it can be tested.
   * See issue #188
   *
   * v2 includes rewritten SQL because of a bug that caused the test to fail.
   * @return int affected rows.
   */
  public static function guessContactIdsByNameAndEmail() {

    // In the other case, if we find a unique contact with matching
    // first name, last name and e-mail address, it is probably the one we
    // are looking for as well.

    // look for email and names that match where there's only one match.
    return static::runSqlReturnAffectedRows(
        "UPDATE tmp_mailchimp_push_m m
        INNER JOIN (
          SELECT email, first_name, last_name, c.id AS contact_id
          FROM civicrm_email e
          JOIN civicrm_contact c ON e.contact_id = c.id AND c.is_deleted = 0
          AND c.do_not_email = 0 AND c.do_not_mail = 0 AND c.is_opt_out = 0 AND e.on_hold = 0
          GROUP BY email, first_name, last_name, c.id
          HAVING COUNT(DISTINCT c.id)=1
          ) uniques ON m.email = uniques.email AND m.first_name = uniques.first_name AND m.last_name = uniques.last_name
        SET m.cid_guess = uniques.contact_id
        WHERE m.first_name != '' AND m.last_name != ''  AND m.cid_guess IS NULL
        ");
  }
  /**
   * Drop tmp_mailchimp_push_m and tmp_mailchimp_push_c, if they exist.
   *
   * Those tables are created by collectMailchimp() and collectCiviCrm()
   * for the purposes of syncing to/from Mailchimp/CiviCRM and are not needed
   * outside of those operations.
   */
  public static function dropTemporaryTables() {
    CRM_Core_DAO::executeQuery("DROP TABLE IF EXISTS tmp_mailchimp_push_m;");
    CRM_Core_DAO::executeQuery("DROP TABLE IF EXISTS tmp_mailchimp_push_c;");
  }
  /**
   * Drop mailchimp_log table if it exists.
   *
   * This table holds errors from multiple lists in Mailchimp where the contact
   * could not be identified in CiviCRM; typically these contacts are
   * un-sync-able ("Titanics").
   */
  public static function dropLogTable() {
    CRM_Core_DAO::executeQuery("DROP TABLE IF EXISTS mailchimp_log;");
  }
  /**
   * Create new tmp_mailchimp_push_m.
   *
   * Nb. these are temporary tables but we don't use TEMPORARY table because
   * they are needed over multiple sessions because of queue.
   *
   *
   * cid_guess column is the contact id that this record will be sync-ed to.
   * It after both collections and a matchMailchimpMembersToContacts call it
   * will be
   *
   * - A contact id
   * - Zero meaning we can create a new contact
   * - NULL meaning we must ignore this because otherwise we might end up
   *   making endless duplicates.
   *
   * Because a lot of matching is done on this, it has an index. Nb. a test was
   * done trying the idea of adding the non-unique key at the end of the
   * collection; heavily-keyed tables can slow down mass-inserts, so sometimes's
   * it's quicker to add an index after an update. However this only saved 0.1s
   * over 5,000 records import, so this code was removed for the sake of KISS.
   *
   * The speed of collecting from Mailchimp, is, as you might expect, determined
   * by Mailchimp's API which seems to take about 3s for 1,000 records.
   * Inserting them into the tmp table takes about 1s per 1,000 records on my
   * server, so about 4s/1000 members.
   */
  public static function createTemporaryTableForMailchimp() {
    CRM_Core_DAO::executeQuery( "DROP TABLE IF EXISTS tmp_mailchimp_push_m;");
    $dao = CRM_Core_DAO::executeQuery(
      "CREATE TABLE tmp_mailchimp_push_m (
        email VARCHAR(200) NOT NULL,
        first_name VARCHAR(100) NOT NULL DEFAULT '',
        last_name VARCHAR(100) NOT NULL DEFAULT '',
        hash CHAR(32) NOT NULL DEFAULT '',
        interests VARCHAR(4096) NOT NULL DEFAULT '',
        cid_guess INT(10) DEFAULT NULL,
        checksum VARCHAR(200) DEFAULT NULL,
        addr1 varchar(96) DEFAULT NULL,
        addr2 varchar(96) DEFAULT NULL,
        city varchar(64) DEFAULT NULL,
        state varchar(64) DEFAULT NULL,
        zip varchar(64) DEFAULT NULL,
        country varchar(64) DEFAULT NULL,
        custom_fields VARCHAR(4096) NOT NULL DEFAULT '',
        tags VARCHAR(4096) NOT NULL DEFAULT '',
        PRIMARY KEY (email, hash),
        KEY (cid_guess))
        ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;");

    // Convenience in collectMailchimp.
    return $dao;
  }
  /**
   * Create new tmp_mailchimp_push_c.
   *
   * Nb. these are temporary tables but we don't use TEMPORARY table because
   * they are needed over multiple sessions because of queue.
   */
  public static function createTemporaryTableForCiviCRM() {
    CRM_Core_DAO::executeQuery( "DROP TABLE IF EXISTS tmp_mailchimp_push_c;");
    $dao = CRM_Core_DAO::executeQuery("CREATE TABLE tmp_mailchimp_push_c (
        contact_id INT(10) UNSIGNED NOT NULL,
        email VARCHAR(200) NOT NULL,
        first_name VARCHAR(100) NOT NULL DEFAULT '',
        last_name VARCHAR(100) NOT NULL DEFAULT '',
        hash CHAR(32) NOT NULL DEFAULT '',
        interests VARCHAR(4096) NOT NULL DEFAULT '',
        checksum VARCHAR(200) DEFAULT NULL,
        addr1 varchar(96) DEFAULT NULL,
        addr2 varchar(96) DEFAULT NULL,
        city varchar(64) DEFAULT NULL,
        state varchar(64) DEFAULT NULL,
        zip varchar(64) DEFAULT NULL,
        country varchar(64) DEFAULT NULL,
        custom_fields VARCHAR(4096) NOT NULL DEFAULT '',
        tags VARCHAR(4096) NOT NULL DEFAULT '',
        PRIMARY KEY (email, hash),
        KEY (contact_id)
        )
        ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci ;");
    return $dao;
  }
  /**
   * Logic to determine update needed.
   *
   * This is separate from the method that collects a batch update so that it
   * can be tested more easily.
   *
   * @param array $merge_fields an array where the *keys* are 'tag' names from
   * Mailchimp's merge_fields resource. e.g. FNAME, LNAME.
   * @param array $civi_details Array of civicrm details from
   * tmp_mailchimp_push_c
   * @param array $mailchimp_details Array of mailchimp details from
   * tmp_mailchimp_push_m
   * @return array changes in format required by Mailchimp API.
   */
  public static function updateMailchimpFromCiviLogic($merge_fields, $civi_details, $mailchimp_details) {
    $params = [];
    // I think possibly some installations don't have Multibyte String Functions
    // installed?
    $lower = function_exists('mb_strtolower') ? 'mb_strtolower' : 'strtolower';

    if ($civi_details['email'] && $lower($civi_details['email']) != $lower($mailchimp_details['email'])) {
      // This is the case for additions; when we're adding someone new.
      $params['email_address'] = $civi_details['email'];
    }

    if ($civi_details['interests'] && $civi_details['interests'] != $mailchimp_details['interests']) {
      // Civi's Interest field will unpack to an empty array if we don't have
      // any mapped interest groups. In this case we don't need to send the
      // interests to Mailchimp at all, so we check for that.
      // In the case of adding a new person from CiviCRM to Mailchimp, the
      // Mailchimp interests passed in will be empty, but the CiviCRM one will
      // be 'a:0:{}' since that is the serialized version of [].
      $interests = unserialize($civi_details['interests']);
      if (!empty($interests)) {
        $params['interests'] = $interests;
      }
    }

    $name_changed = FALSE;
    if ($civi_details['first_name'] && trim($civi_details['first_name']) != trim($mailchimp_details['first_name'])) {
      $name_changed = TRUE;
      // First name mismatch.
      if (isset($merge_fields['FNAME'])) {
        // FNAME field exists, so set it.
        $params['merge_fields']['FNAME'] = $civi_details['first_name'];
      }
    }
    if ($civi_details['last_name'] && trim($civi_details['last_name']) != trim($mailchimp_details['last_name'])) {
      $name_changed = TRUE;
      if (isset($merge_fields['LNAME'])) {
        // LNAME field exists, so set it.
        $params['merge_fields']['LNAME'] = $civi_details['last_name'];
      }
    }
    if ($name_changed && key_exists('NAME', $merge_fields)) {
      // The name was changed and this list has a NAME field. Supply first last
      // names to this field.
      $params['merge_fields']['NAME'] = trim("$civi_details[first_name] $civi_details[last_name]");
    }
    // Does Mailchimp have a valid checksum for the contact?
    if (!empty($merge_fields['CHECKSUM'])) {
      $checksum = !empty($mailchimp_details['checksum']) ? $mailchimp_details['checksum'] : '';
      $checksum_is_valid = !empty($civi_details['contact_id']) && $checksum && CRM_Contact_BAO_Contact_Utils::validChecksum($civi_details['contact_id'], $checksum);
      if (!$checksum_is_valid) {
        // If the validation is too expensive, we may have to consider using an
        // infinite time checksum.
        $hours = 24 * 90;
        $new_checksum = CRM_Contact_BAO_Contact_Utils::generateChecksum($civi_details['contact_id'], NULL, $hours);
        $params['merge_fields']['CONTACT_ID'] = $civi_details['contact_id'];
        $params['merge_fields']['CHECKSUM'] = $new_checksum;
      }
    }

    // Sync Address fields - addr1, city, state, zip, country are mandatory fields to update address in mailchimp. Passing 'n/a', if any of these fields are empty in CiviCRM
    // To Do: Add a setting in CiviCRM to enable/disable sync of address fields and to set the default empty value
    if (isset($merge_fields['ADDRESS'])) {
        $params['merge_fields']['ADDRESS']['addr1'] = !empty($civi_details['addr1']) ? $civi_details['addr1'] : 'n/a';
        $params['merge_fields']['ADDRESS']['addr2'] = !empty($civi_details['addr2']) ? $civi_details['addr2'] : 'n/a';
        $params['merge_fields']['ADDRESS']['city'] = !empty($civi_details['city']) ? $civi_details['city'] : 'n/a';
        $params['merge_fields']['ADDRESS']['state'] = !empty($civi_details['state']) ? $civi_details['state'] : 'n/a';
        $params['merge_fields']['ADDRESS']['zip'] = !empty($civi_details['zip']) ? $civi_details['zip'] : 'n/a';
        $params['merge_fields']['ADDRESS']['country'] = !empty($civi_details['country']) ? $civi_details['country'] : 'n/a';
    }
    $profileFields = self::getProfileFieldsToSync();
    if ($profileFields && !empty($civi_details['custom_fields'])) {
      $civiCustomData = unserialize($civi_details['custom_fields']);
      foreach ($profileFields as $name => $ignore) {
        $tag = strtoupper($name);
        if (isset($merge_fields[$tag])) {
          $params['merge_fields'][$tag] = $civiCustomData[$tag] ? $civiCustomData[$tag] : '';
        }
      }
    }
    return $params;
  }

  /**
   * Logic to determine update needed for pull.
   *
   * This is separate from the method that collects a batch update so that it
   * can be tested more easily.
   *
   * @param array $mailchimp_details Array of mailchimp details from
   * tmp_mailchimp_push_m, with keys first_name, last_name
   * @param array $civi_details Array of civicrm details from
   * tmp_mailchimp_push_c, with keys first_name, last_name
   * @return array changes in format required by Mailchimp API.
   */
  public static function updateCiviFromMailchimpContactLogic($mailchimp_details, $civi_details) {

    $edits = [];

    foreach (['first_name', 'last_name'] as $field) {
      if ($mailchimp_details[$field] && $mailchimp_details[$field] != $civi_details[$field]) {
        $edits[$field] = $mailchimp_details[$field];
      }
    }

    return $edits;
  }

  /**
   * There's probably a better way to do this.
   */
  public static function runSqlReturnAffectedRows($sql, $params = array()) {
    $dao = new CRM_Core_DAO();
    $q = CRM_Core_DAO::composeQuery($sql, $params);
    $result = $dao->query($q);
    if (is_a($result, 'DB_Error')) {
      throw new Exception ($result->message . "\n" . $result->userinfo);
    }
    $dao->free();
    return $result;
  }

  /**
   * Get the data for the fields to sync.
   */
  public static function getProfileFieldsToSync() {
    static $profileID;
    if (!isset($profileID)) {
      $profileID = Civi::settings()->get('mailchimp_sync_profile');
    }
    static $fields = [];
    $fields = [];
    if (!$profileID) {
      return $fields;
    }
    if (!$fields) {
      $ufFieldResult = civicrm_api3('UFField', 'get', ['uf_group_id' => $profileID]);
      if (!empty($ufFieldResult['values'])) {
        foreach($ufFieldResult['values'] as $field) {
          $fields[$field['field_name']] = $field;
        }
      }
    }
    return $fields;
  }
}

