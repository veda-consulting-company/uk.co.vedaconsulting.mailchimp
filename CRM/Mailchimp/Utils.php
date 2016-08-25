<?php

class CRM_Mailchimp_Utils {

  const MC_SETTING_GROUP = 'MailChimp Preferences';

  /** Mailchimp API object to use. */
  static protected $mailchimp_api;

  /** Holds runtime cache of group details */
  static protected $mailchimp_interest_details = [];

  /** Holds a cache of list names from Mailchimp */
  static protected $mailchimp_lists;

  /**
   * Checked by mailchimp_civicrm_post before it acts on anything.
   *
   * That post hook might send requests to Mailchimp's API, but in the cases
   * where we're responding to data from Mailchimp, this could possibly result
   * in a loop, so we have a central on/off switch here.
   *
   * In previous versions it was a session variable, but this is not necessary.
   */
  public static $post_hook_enabled = TRUE;

  /**
   * Split a string of group titles into an array of groupIds.
   *
   * The Contact:get API is the only place you can get a list of all the groups
   * (smart and normal) that a contact has membership of. But it returns them as
   * a comma separated string. You can't split on a comma because there is no
   * restriction on commas in group titles. So instead we take a list of
   * candidate titles and look for those.
   *
   * This function solves the problem of:
   * Group name: "Sponsored walk, 2015"
   * Group name: "Sponsored walk"
   *
   * Contact 1's groups: "Sponsored walk,Sponsored walk, 2015"
   * This contact is in both groups.
   *
   * Contact 2's groups: "Sponsored walk"
   * This contact is only in the one group.
   *
   * If we just split on comma then the contacts would only be in the "sponsored
   * walk" group and never the one with the comma in.
   *
   * @param string $group_titles As output by the CiviCRM api for a contact when
   * you request the 'group' output (which comes in a key called 'groups').
   * @param array $group_details As from CRM_Mailchimp_Utils::getGroupsToSync
   * but only including groups you're interested in.
   * @return array CiviCRM groupIds.
   */
  public static function splitGroupTitles($group_titles, $group_details) {
    $groups = [];

    // Sort the group titles by length, longest first.
    uasort($group_details, function($a, $b) {
      return (strlen($b['civigroup_title']) - strlen($a['civigroup_title']));
    });
    // Remove the found titles longest first.
    $group_titles = ",$group_titles,";

    foreach ($group_details as $civi_group_id => $detail) {
      $i = strpos($group_titles, ",$detail[civigroup_title],");
      if ($i !== FALSE) {
        $groups[] = $civi_group_id;
        // Remove this from the string.
        $group_titles = substr($group_titles, 0, $i+1) . substr($group_titles, $i + strlen(",$detail[civigroup_title],"));
      }
    }
    return $groups;
  }
  /**
   * Returns the webhook URL.
   */
  public static function getWebhookUrl($accountId) {
    $security_key = CRM_Core_BAO_Setting::getItem(self::MC_SETTING_GROUP, 'security_key', NULL, FALSE);
    if ($accountId) {
      $security_key = CRM_Mailchimp_Utils::getSecurityKeyFromAccountId($accountId);
    }
    if (empty($security_key)) {
      // @Todo what exception should this throw?
      throw new InvalidArgumentException("You have not set a security key for your Mailchimp integration. Please do this on the settings page at civicrm/mailchimp/settings");
    }
    $webhook_url = CRM_Utils_System::url('civicrm/mailchimp/webhook',
      $query = 'reset=1&key=' . urlencode($security_key),
      $absolute = TRUE,
      $fragment = NULL,
      $htmlize = FALSE,
      $fronteend = TRUE);

    return $webhook_url;
  }
  /**
   * Returns an API class for talking to Mailchimp.
   *
   * This is a singleton pattern with a factory method to create an object of
   * the normal API class. You can set the Api object with
   * CRM_Mailchimp_Utils::setMailchimpApi() which is essential for being able to
   * passin mocks for testing.
   *
   * @param bool $reset If set it will replace the API object with a default.
   * Only useful after changing stored credentials.
   */
  public static function getMailchimpApi($accountId, $reset=FALSE, $apiKey = FALSE) {
    if ($reset) {
      static::$mailchimp_api[$accountId] = NULL;
    }

    // Singleton pattern.
    if (!isset(static::$mailchimp_api[$accountId])) {
      if ($accountId) {
        $params = ['api_key' => CRM_Mailchimp_Utils::getApiKeyFromAccountId($accountId)];
      } elseif ($apiKey) {
        $params = ['api_key' => $apiKey];
      }
      $debugging = CRM_Core_BAO_Setting::getItem(self::MC_SETTING_GROUP, 'enable_debugging', NULL, FALSE);
      if ($debugging == 1) {
        // We want debugging. Inject a logging callback.
        $params['log_facility'] = function($message) {
          CRM_Core_Error::debug_log_message($message, FALSE, 'mailchimp');
        };
      }
      $api = new CRM_Mailchimp_Api3($params);
      static::setMailchimpApi($api, $accountId);
    }
    
    return static::$mailchimp_api[$accountId];
  }
  
  // Test purpose
  public static function getMailchimpSingleAccountId() {
    $query = "SELECT id FROM mailchimp_civicrm_account ORDER BY id ASC LIMIT 1";
    $accountId = CRM_Core_DAO::singleValueQuery($query);
    return $accountId;
  }
  // Test purpose
   public static function getMailchimpSecondAccountId() {
    $query = "SELECT id FROM mailchimp_civicrm_account ORDER BY id ASC LIMIT 2";
    $dao = CRM_Core_DAO::executeQuery($query);
    $accountIds = array();
    while ($dao->fetch()) {
      $accountIds[] = $dao->id;
    }
    return $accountIds[1]; 
  }
  // Test purpose
  public static function getMailchimpSingleApiKey() {
    $query = "SELECT api_key FROM mailchimp_civicrm_account ORDER BY id ASC LIMIT 1";
    $apiKey = CRM_Core_DAO::singleValueQuery($query);
    return $apiKey;
  }
  // Test purpose
  public static function getMailchimpSecondApiKey() {
    $query = "SELECT api_key FROM mailchimp_civicrm_account ORDER BY id ASC LIMIT 2";
    $dao = CRM_Core_DAO::executeQuery($query);
    $apiKeys = array();
    while ($dao->fetch()) {
      $apiKeys[] = $dao->api_key;
    }
    return $apiKeys[1];
  }
  // Test purpose
  public static function getCountMailchimpAccounts() {
    $query = "SELECT count(id) FROM mailchimp_civicrm_account";
    $noOfAccounts = CRM_Core_DAO::singleValueQuery($query);
    return $noOfAccounts;
  }
  // Test purpose
  public static function keepFirstTwoDeleteRest() {
    $query = "DELETE FROM mailchimp_civicrm_account WHERE id NOT IN (
              SELECT id FROM mailchimp_civicrm_account ORDER BY id ASC LIMIT 2)";
    CRM_Core_DAO::executeQuery($query);
  }

  // For php unit test 
  public static function insertApiDetailsToDb($file) {
    list ($xml, $error) = CRM_Utils_XML::parseFile($file);
    if ($xml === FALSE) {
      throw new Exception("Failed to parse info XML: $error");
    }
    $apiDetails = array();
    $xmlArray = CRM_Utils_XML::xmlObjToArray($xml);
    if (isset($xmlArray['ApiDetails']['@attributes'])) {
      $apiDetails[] = $xmlArray['ApiDetails']['@attributes'];
    }else {
      foreach ($xmlArray['ApiDetails'] as $key => $value) {
        $apiDetails[] = $value['@attributes'];
      }
    }    
    
    if (empty($apiDetails)) {
      throw new Exception("Failed to get api key. api key required to test");
    }
    
    foreach ($apiDetails as $apiDetail) {
      $accountName = htmlspecialchars($apiDetail['account_name']);
      $insertQuery = "INSERT INTO `mailchimp_civicrm_account` (`api_key`, `security_key`, `account_name`)
        VALUES (%1, %2, %3) ON DUPLICATE KEY UPDATE `security_key` = %2, `account_name` = %3";
      $insertQueryParams = array(1=>array($apiDetail['api_key'], 'String'), 2=>array($apiDetail['security_key'], 'String'), 3=>array($accountName, 'String'));
      CRM_Core_DAO::executeQuery($insertQuery, $insertQueryParams);
    }
    
  }

  /**
   * Set the API object.
   *
   * This is for testing purposes only.
   */
  public static function setMailchimpApi(CRM_Mailchimp_Api3 $api, $accountId) {
    static::$mailchimp_api[$accountId] = $api;
  }

  /**
   * Reset caches.
   */
  public static function resetAllCaches() {
    static::$mailchimp_api = NULL;
    static::$mailchimp_lists = NULL;
    static::$mailchimp_interest_details = [];
  }
  /**
   * Check all mapped groups' lists.
   *
   * Nb. this does not output anything itself so we can test it works. It is
   * used by the settings page.
   *
   * @param null|Array $groups array of membership groups to check, or NULL to
   *                   check all.
   *
   * @return Array of message strings that should be output with CRM_Core_Error
   * or such.
   *
   */
  public static function checkGroupsConfig($accountId, $groups=NULL) {
    if ($groups === NULL) {
      $groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), null, $membership_only = TRUE);
    }
    if (!is_array($groups)) {
      throw new InvalidArgumentException("expected array argument, if provided");
    }
    $api = CRM_Mailchimp_Utils::getMailchimpApi($accountId);

    $warnings = [];
    // Check all our groups do not have the sources:API set in the webhook, and
    // that they do have the webhook set.
    foreach ($groups as $group_id => $details) {

      $group_settings_link = "<a href='/civicrm/group?reset=1&action=update&id=$group_id' >"
        . htmlspecialchars($details['civigroup_title']) . "</a>";

      $message_prefix = ts('CiviCRM group "%1" (Mailchimp list %2): ',
        [1 => $group_settings_link, 2 => $details['list_id']]);

      try {
        $test_warnings = CRM_Mailchimp_Utils::configureList($accountId, $details['list_id'], $dry_run=TRUE);
        foreach ($test_warnings as $_) {
          $warnings []= $message_prefix . $_;
        }
      }
      catch (CRM_Mailchimp_NetworkErrorException $e) {
        $warnings []= $message_prefix . ts("Problems (possibly temporary) fetching details from Mailchimp. ") . $e->getMessage();
      }
      catch (CRM_Mailchimp_RequestErrorException $e) {
        $message = $e->getMessage();
        if ($e->response->http_code == 404) {
          // A little more helpful than "resource not found".
          $warnings []= $message_prefix . ts("The Mailchimp list that this once worked with has "
            ."been deleted on Mailchimp. Please edit the CiviCRM group settings to "
            ."either specify a different Mailchimp list that exists, or to remove "
            ."the Mailchimp integration for this group.");
        }
        else {
          $warnings []= $message_prefix . ts("Problems fetching details from Mailchimp. ") . $e->getMessage();
        }
      }
    }

    if ($warnings) {
      CRM_Core_Error::debug_log_message('Mailchimp list check warnings' . var_export($warnings,1));
    }
    return $warnings;
  }
  /**
   * Configure webhook with Mailchimp.
   *
   * Returns a list of messages to display to the user.
   *
   * @param string $list_id Mailchimp List Id.
   * @param bool $dry_run   If set no changes are made.
   * @return array
   */
  public static function configureList($accountId, $list_id, $dry_run = FALSE) {
    $api = CRM_Mailchimp_Utils::getMailchimpApi($accountId);
    $expected = [
      'url' => CRM_Mailchimp_Utils::getWebhookUrl($accountId),
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
    ];
    $verb = $dry_run ? 'Need to change ' : 'Changed ';
    try {
      $result = $api->get("/lists/$list_id/webhooks");
      $webhooks = $result->data->webhooks;
      //$webhooks = $api->get("/lists/$list_id/webhooks")->data->webhooks;

      if (empty($webhooks)) {
        $messages []= ts(($dry_run ? 'Need to create' : 'Created') .' a webhook at Mailchimp');
      }
      else {
        // Existing webhook(s) - check thoroughly.
        if (count($webhooks) > 1) {
          // Unusual case, leave it alone.
          $messages [] = "Mailchimp list $list_id has more than one webhook configured. This is unusual, and so CiviCRM has not made any changes. Please ensure the webhook is set up correctly.";
          return $messages;
        }

        // Got a single webhook, check it looks right.
        $messages = [];
        // Correct URL?
        if ($webhooks[0]->url != $expected['url']) {
          $messages []= ts($verb . 'webhook URL from %1 to %2', [1 => $webhooks[0]->url, 2 => $expected['url']]);
        }
        // Correct sources?
        foreach ($expected['sources'] as $source => $expected_value) {
          if ($webhooks[0]->sources->$source != $expected_value) {
            $messages []= ts($verb . 'webhook source %1 from %2 to %3', [1 => $source, 2 => (int) $webhooks[0]->sources->$source, 3 => (int)$expected_value]);
          }
        }
        // Correct events?
        foreach ($expected['events'] as $event => $expected_value) {
          if ($webhooks[0]->events->$event != $expected_value) {
            $messages []= ts($verb . 'webhook event %1 from %2 to %3', [1 => $event, 2 => (int) $webhooks[0]->events->$event, 3 => (int) $expected_value]);
          }
        }

        if (empty($messages)) {
          // All fine.
          return;
        }

        if (!$dry_run) {
          // As of May 2016, there doesn't seem to be an update method for
          // webhooks, so we just delete this and add another.
          $api->delete("/lists/$list_id/webhooks/" . $webhooks[0]->id);
        }
      }
      if (!$dry_run) {
        // Now create the proper one.
        $result = $api->post("/lists/$list_id/webhooks", $expected);
      }

    }
    catch (CRM_Mailchimp_RequestErrorException $e) {
      if ($e->request->method == 'GET' && $e->response->http_code == 404) {
        $messages [] = ts("The Mailchimp list that this once worked with has been deleted");
      }
      else {
        $messages []= ts("Problems updating or fetching from Mailchimp. Please manually check the configuration. ") . $e->getMessage();
      }
    }
    catch (CRM_Mailchimp_NetworkErrorException $e) {
      $messages []= ts("Problems (possibly temporary) talking to Mailchimp. ") . $e->getMessage();
    }

    return $messages;
  }
  /**
   * Look up an array of CiviCRM groups linked to Maichimp groupings.
   *
   *
   *
   * @param $groupIDs mixed array of CiviCRM group Ids to fetch data for; or empty to return ALL mapped groups.
   * @param $mc_list_id mixed Fetch for a specific Mailchimp list only, or null.
   * @param $membership_only bool. Only fetch mapped membership groups (i.e. NOT linked to a MC grouping).
   * @return array keyed by CiviCRM group id whose values are arrays of details
   *         including:
   *         // Details about Mailchimp
   *         'list_id'
   *         'list_name'
   *         'category_id'
   *         'category_name'
   *         'interest_id'
   *         'interest_name'
   *         // Details from CiviCRM
   *         'civigroup_title'
   *         'civigroup_uses_cache'
   *         'is_mc_update_grouping'  bool: is the subscriber allowed to update this
   *                                  via MC interface?
   *         // Deprecated DO NOT USE from Mailchimp.
   *         'grouping_id'
   *         'grouping_name'
   *         'group_id'
   *         'group_name'
   */
  public static function getGroupsToSync($groupIDs = array(), $mc_list_id = null, $membership_only = FALSE) {

    $params = $groups = $temp = array();
    $groupIDs = array_filter(array_map('intval',$groupIDs));

    if (!empty($groupIDs)) {
      $groupIDs = implode(',', $groupIDs);
      $whereClause = "entity_id IN ($groupIDs)";
    } else {
      $whereClause = "1 = 1";
    }

    $whereClause .= " AND mc_list_id IS NOT NULL AND mc_list_id <> ''";

    if ($mc_list_id) {
      // just want results for a particular MC list.
      $whereClause .= " AND mc_list_id = %1 ";
      $params[1] = array($mc_list_id, 'String');
    }

    if ($membership_only) {
      $whereClause .= " AND (mc_grouping_id IS NULL OR mc_grouping_id = '')";
    }

    $query  = "
      SELECT  entity_id, mc_list_id, mc_grouping_id, mc_group_id, is_mc_update_grouping, cg.title as civigroup_title, cg.saved_search_id, cg.children, mcs.account_id as account_id, mca.api_key
 FROM    civicrm_value_mailchimp_settings mcs
      INNER JOIN civicrm_group cg ON mcs.entity_id = cg.id
      INNER JOIN mailchimp_civicrm_account mca ON mca.id = mcs.account_id
      WHERE $whereClause";
    $dao = CRM_Core_DAO::executeQuery($query, $params);
    while ($dao->fetch()) {
      $list_name = CRM_Mailchimp_Utils::getMCListName($dao->account_id, $dao->mc_list_id);
      $interest_name = CRM_Mailchimp_Utils::getMCInterestName($dao->account_id, $dao->mc_list_id, $dao->mc_grouping_id, $dao->mc_group_id);
      $category_name = CRM_Mailchimp_Utils::getMCCategoryName($dao->account_id, $dao->mc_list_id, $dao->mc_grouping_id);
      $groups[$dao->entity_id] =
        array(
          // Details about Mailchimp
          'account_id'               => $dao->account_id,
          'api_key'               => $dao->api_key,
          'list_id'               => $dao->mc_list_id,
          'list_name'             => $list_name,
          'category_id'           => $dao->mc_grouping_id,
          'category_name'         => $category_name,
          'interest_id'           => $dao->mc_group_id,
          'interest_name'         => $interest_name,
          // Details from CiviCRM
          'is_mc_update_grouping' => $dao->is_mc_update_grouping,
          'civigroup_title'       => $dao->civigroup_title,
          'civigroup_uses_cache'    => (bool) (($dao->saved_search_id > 0) || (bool) $dao->children),

          // Deprecated from Mailchimp.
          'grouping_id'           => $dao->mc_grouping_id,
          'grouping_name'         => $category_name,
          'group_id'              => $dao->mc_group_id,
          'group_name'            => $interest_name,
        );
    }

    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getGroupsToSync $groups', $groups);

    return $groups;
  }

  /**
   * Return the name at mailchimp for the given Mailchimp list id.
   *
   * @return string.
   */
  public static function getMCListName($accountId, $list_id) {
    if (!isset(static::$mailchimp_lists[$accountId])) {
      static::$mailchimp_lists[$accountId][$list_id] = [];
      $api = CRM_Mailchimp_Utils::getMailchimpApi($accountId);
      $lists = $api->get('/lists', ['fields' => 'lists.id,lists.name','count'=>10000])->data->lists;
      foreach ($lists as $list) {
        static::$mailchimp_lists[$accountId][$list->id] = $list->name;
      }
    }

    if (!isset(static::$mailchimp_lists[$accountId][$list_id])) {
      // Return ZLS if not found.
      return '';
    }
    return static::$mailchimp_lists[$accountId][$list_id];
  }

  /**
   * Get interest groupings for given ListID (cached).
   *
   * Nb. general API function used by several other helper functions.
   *
   * Returns an array like {
   *   [category_id] => array(
   *     'id' => category_id,
   *     'name' => Category name
   *     'interests' => array(
   *        [interest_id] => array(
   *          'id' => interest_id,
   *          'name' => interest name
   *          ),
   *        ...
   *        ),
   *   ...
   *   )
   *
   */
  public static function getMCInterestGroupings($accountId, $listID) {

    if (empty($listID)) {
      CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Utils::getMCInterestGroupings called without list id');
      return NULL;
    }

    $mapper = &static::$mailchimp_interest_details;
    if (!array_key_exists($listID, $mapper)) {
      $mapper[$listID] = array();

      try {
        // Get list name.
        $api = CRM_Mailchimp_Utils::getMailchimpApi($accountId);
        $categories = $api->get("/lists/$listID/interest-categories",
            ['fields' => 'categories.id,categories.title','count'=>10000])
          ->data->categories;
      }
      catch (CRM_Mailchimp_RequestErrorException $e) {
        if ($e->response->http_code == 404) {
          // Controlled response
          CRM_Core_Error::debug_log_message("Mailchimp error: List $listID is not found.");
          return NULL;
        }
        else {
          CRM_Core_Error::debug_log_message('Unhandled Mailchimp error: ' . $e->getMessage());
          throw $e;
        }
      }
      catch (CRM_Mailchimp_NetworkErrorException $e) {
        CRM_Core_Error::debug_log_message('Unhandled Mailchimp network error: ' . $e->getMessage());
        throw $e;
        return NULL;
      }
      // Re-map $categories from this:
      //    id = (string [10]) `f192c59e0d`
      //    title = (string [7]) `CiviCRM`

      foreach ($categories as $category) {
        // Need to look up interests for this category.
        $interests = CRM_Mailchimp_Utils::getMailchimpApi($accountId)
          ->get("/lists/$listID/interest-categories/$category->id/interests",
            ['fields' => 'interests.id,interests.name','count'=>10000])
          ->data->interests;

        $mapper[$listID][$category->id] = [
          'id' => $category->id,
          'name' => $category->title,
          'interests' => [],
          ];
        foreach ($interests as $interest) {
          $mapper[$listID][$category->id]['interests'][$interest->id] =
            ['id' => $interest->id, 'name' => $interest->name];
        }
      }
    }
    CRM_Mailchimp_Utils::checkDebug("CRM_Mailchimp_Utils::getMCInterestGroupings for list '$listID' returning ", $mapper[$listID]);
    return $mapper[$listID];
  }

  /**
   * return the group name for given list, grouping and group
   *
   */
  public static function getMCInterestName($accountId, $listID, $category_id, $interest_id) {
    $info = static::getMCInterestGroupings($accountId, $listID);

    // Check list, grouping, and group exist
    if (empty($info[$category_id]['interests'][$interest_id])) {
      $name = null;
    }
    else {
      $name = $info[$category_id]['interests'][$interest_id]['name'];
    }
    CRM_Mailchimp_Utils::checkDebug(__FUNCTION__ . " called for list '$listID', category '$category_id', interest '$interest_id', returning '$name'");
    return $name;
  }

  /**
   * Return the grouping name for given list, grouping MC Ids.
   */
  public static function getMCCategoryName($accountId, $listID, $category_id) {
    $info = static::getMCInterestGroupings($accountId, $listID);

    // Check list, grouping, and group exist
    $name = NULL;
    if (!empty($info[$category_id])) {
      $name = $info[$category_id]['name'];
    }
    CRM_Mailchimp_Utils::checkDebug("CRM_Mailchimp_Utils::getMCCategoryName for list $listID cat $category_id returning $name");
    return $name;
  }

  /**
   * Get Mailchimp group ID group name
   */
  public static function getMailchimpGroupIdFromName($listID, $groupName) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getMailchimpGroupIdFromName $listID', $listID);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getMailchimpGroupIdFromName $groupName', $groupName);

    if (empty($listID) || empty($groupName)) {
      return NULL;
    }

    $mcLists = new Mailchimp_Lists(CRM_Mailchimp_Utils::mailchimp());
    try {
      $results = $mcLists->interestGroupings($listID);
    } 
    catch (Exception $e) {
      return NULL;
    }
    
    foreach ($results as $grouping) {
      foreach ($grouping['groups'] as $group) {
        if ($group['name'] == $groupName) {
          CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getMailchimpGroupIdFromName= ', $group['id']);
          return $group['id'];
        }
      }
    }
  }
  
  /**
   * Log a message and optionally a variable, if debugging is enabled.
   */
  public static function checkDebug($description, $variable='VARIABLE_NOT_PROVIDED') {
    $debugging = CRM_Core_BAO_Setting::getItem(self::MC_SETTING_GROUP, 'enable_debugging', NULL, FALSE);

    if ($debugging == 1) {
      if ($variable === 'VARIABLE_NOT_PROVIDED') {
        // Simple log message.
        CRM_Core_Error::debug_log_message($description, FALSE, 'mailchimp');
      }
      else {
        // Log a variable.
        CRM_Core_Error::debug_log_message(
          $description . "\n" . var_export($variable,1)
          , FALSE, 'mailchimp');
      }
    }
  }

  // Deprecated - remove these once Mailchimp turn off APIv2, scheduled end of
  // 2016.
  /**
   * deprecated (soon!) v1, v2 API
   */
  public static function mailchimp() {
    $apiKey   = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'api_key');
    $mcClient = new Mailchimp($apiKey);
    //CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils mailchimp $mcClient', $mcClient);
    return $mcClient;
  }
  
  public static function getWebhookSecurityKeys() {
    $webhookSecurityKeys = array();
    $query = "SELECT security_key FROM mailchimp_civicrm_account";
    $dao = CRM_Core_DAO::executeQuery($query);
    while ($dao->fetch()) {
      $webhookSecurityKeys[] = $dao->security_key;
    }
    return $webhookSecurityKeys;
  }
  
  public static function getApiKeyFromSecurityKey($securityKey) {
    if (!$securityKey) {
      return;
    }
    $query = "SELECT api_key FROM mailchimp_civicrm_account WHERE security_key = %1";
    $dao = CRM_Core_DAO::executeQuery($query, array(1=>array($securityKey, 'String')));
    if ($dao->fetch()) {
      $apiKey = $dao->api_key;
    }
    return $apiKey;
  }
  
   public static function getAccountIdFromSecurityKey($securityKey) {
    if (!$securityKey) {
      return;
    }
    $accountId = NULL;
    $query = "SELECT id FROM mailchimp_civicrm_account WHERE security_key = %1";
    $dao = CRM_Core_DAO::executeQuery($query, array(1=>array($securityKey, 'String')));
    if ($dao->fetch()) {
      $accountId = $dao->id;
    }
    return $accountId;
  }
  
  public static function getSecurityKeyFromApiKey($apiKey) {
    if (!$apiKey) {
      return;
    }
    $query = "SELECT security_key FROM mailchimp_civicrm_account WHERE api_key = %1";
    $dao = CRM_Core_DAO::executeQuery($query, array(1=>array($apiKey, 'String')));
    if ($dao->fetch()) {
      $securityKey = $dao->security_key;
    }
    return $securityKey;
  }
  
   public static function getSecurityKeyFromAccountId($accountId) {
    if (!$accountId) {
      return;
    }
    $query = "SELECT security_key FROM mailchimp_civicrm_account WHERE id = %1";
    $dao = CRM_Core_DAO::executeQuery($query, array(1=>array($accountId, 'Int')));
    if ($dao->fetch()) {
      $securityKey = $dao->security_key;
    }
    return $securityKey;
  }
  
  public static function getAllApiKeys() {
    $apiKeys = array();
    $query = "SELECT api_key FROM mailchimp_civicrm_account";
    $dao = CRM_Core_DAO::executeQuery($query);
    while ($dao->fetch()) {
      $apiKeys[] = $dao->api_key;
    }
    return $apiKeys;
  }
  
  public static function getAllAccountIds() {
    $accountIds = array();
    $query = "SELECT id FROM mailchimp_civicrm_account";
    $dao = CRM_Core_DAO::executeQuery($query);
    while ($dao->fetch()) {
      $accountIds[] = $dao->id;
    }
    return $accountIds;
  }
  
  public static function getAccountDetailsFromApiKey($apiKey) {
    if (!$apiKey) {
      return;
    }
    $query = "SELECT account_name, id FROM mailchimp_civicrm_account WHERE api_key = %1";
    $dao = CRM_Core_DAO::executeQuery($query, array(1=>array($apiKey, 'String')));
    $accountDetails = array();
    if ($dao->fetch()) {
      $accountDetails['account_name'] = $dao->account_name;
      $accountDetails['account_id'] = $dao->id;
    }
    return $accountDetails;
  }
  
  public static function getAccountNameFromAccountId($accountId) {
    if (!$accountId) {
      return;
    }
    $query = "SELECT account_name FROM mailchimp_civicrm_account WHERE id = %1";
    $accountName = CRM_Core_DAO::singleValueQuery($query, array(1=>array($accountId, 'Int')));
    return $accountName;
  }
  
  public static function getApiKeyFromAccountId($accountId) {
    if (!$accountId) {
      return;
    }
    $query = "SELECT api_key FROM mailchimp_civicrm_account WHERE id = %1";
    $dao = CRM_Core_DAO::executeQuery($query, array(1=>array($accountId, 'Int')));
    $accountDetails = array();
    if ($dao->fetch()) {
      $apiKey = $dao->api_key;
    }
    return $apiKey;
  }
  /*
   * This function is used when we don't have account id for example upgrading from single account to multiple account v2 to v3
   * or when we create new account setup in civi
   */
  public static function getMailchimpApiFromApiKey($apiKey) {
    $params = ['api_key' => $apiKey];
    $api = new CRM_Mailchimp_Api3($params);
    return $api;
  }
}
