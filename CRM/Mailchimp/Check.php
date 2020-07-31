<?php 
class CRM_Mailchimp_Check {
  const CACHE_KEY = 'mailchimp_check_data';
  
  private $subGroupDetails = [];
  
  private $listGroupDetails = [];
  
  private $listId = '';
  
  private $civiGroupId = NULL;
  
  private $results = [];
  
  
  /**
   * Perform Queue Task to report on CiviCRM Group structure and membership.
   * @param CRM_Queue_TaskContext $ctx
   * @param unknown $listID
   * @return number
   */
  public static function checkCiviGroupTask(CRM_Queue_TaskContext $ctx, $listID) {
    $data = CRM_Mailchimp_Utils::cacheGet(self::CACHE_KEY);
    $listCheck = new self($listID);
    $data[$listCheck->civiGroupId] = $listCheck->checkCiviGroups(); 
    CRM_Mailchimp_Utils::cacheSet(self::CACHE_KEY, $data);
    return CRM_Queue_Task::TASK_SUCCESS;   
  }

  /**
   * Perform Queue Task to report on Mailchimp lists and interest groups.
   * @param CRM_Queue_TaskContext $ctx
   * @param string $listID
   * @return number
   */
  public static function checkMailchimpListTask(CRM_Queue_TaskContext $ctx, $listID) {
    $data = CRM_Mailchimp_Utils::cacheGet(self::CACHE_KEY);
    $listCheck = new self($listID);
    $data[$listCheck->civiGroupId]['mailchimp'] = $listCheck->checkMailchimpLists(); 
    CRM_Mailchimp_Utils::cacheSet(self::CACHE_KEY, $data);
    return CRM_Queue_Task::TASK_SUCCESS;   
  }
  
  /**
   * Create an instance.
   * 
   * @param unknown $listID
   */
  public function __construct($listID) {
    $this->listId = $listID;
    $groupDetails = CRM_Mailchimp_Utils::getGroupsToSync($groupIDs=[], $listID, $membership_only=FALSE);
    foreach ($groupDetails as $groupId => $detail) {
      $detail['civigroup_id'] = $groupId;
      // Split into main group (for audience/list) and sub-groups (mapped to interest groups).
      if (!empty($detail['category_name'])) {
        $this->subGroupDetails[$groupId] = $detail;
      }
      else {
        $this->civiGroupId = $groupId;
        $this->listGroupDetails = $detail; 
      }
    }
  }
  
  /**
   * Get the name of a temp table used to store group/list membership data.
   * 
   * @param string $type
   *  'c' for civicrm groups, 'm' for mailchimp lists.
   *  
   *  @return string
   *  
   */
  public function getTempTableName($type) {
    if ($this->civiGroupId) {
      return 'tmp_mailchimp_check_' . $type;
    }
  }
  
  /**
   * Check mailchimp lists.
   * @return []
   */  
  public function checkMailchimpLists() {
    $returnData = [];
    if (empty($this->listGroupDetails['list_name'])) {
      $returnData['list_exists'] = FALSE;
      return $returnData;
    }
    $returnData['list_exists'] = TRUE;
    $api = CRM_Mailchimp_Utils::getMailchimpApi();
    $listId = $this->listId;
    $response = $api->get("/lists/$this->listId", [
      'fields' => 'stats',
    ]);
    $stats = !empty($response->data->stats) ? (array) $response->data->stats : [];
    $returnData['stats'] = $stats;
    foreach ($this->subGroupDetails as $groupId => $group) {
      if ($group['category_id']) {
        $data = $group;
        $response = $api->get(
            "/lists/$this->listId/interest-categories/" . $group['category_id'] . '/interests/' . $group['interest_id'],[
              'fields' => 'subscriber_count',
            ]);
        $data['stats']['subscriber_count'] = 
           !empty($response->data->subscriber_count) 
           ?  $response->data->subscriber_count 
           : 0;
        $returnData['sub_groups'][$groupId] = $data;
      }
    }
    return $returnData;
  }
  
  /**
   * Check CiviCRM groups.
   *
   * @return []
   *  Array of group details, keyed by the mailchimp list id.
   */
  public function checkCiviGroups() {
    $data = $this->listGroupDetails;
    
    $data['stats'] = $this->collectCiviMembershipDetails(); 
    $data['sub_groups'] = $this->checkCiviSubGroups();
    return $data;
  }
  
  public function checkCiviSubGroups() {
    $returnData = []; 
    $clauses = [
      'members_in_main_group'  => '',
      'valid_members_in_main_group' => " AND tmp.invalid != 1 AND tmp.is_duplicate != 1",
      'is_duplicate' => " AND tmp.is_duplicate = 1",
      'invalid' => " AND tmp.invalid = 1",
      'on_hold' => " AND tmp.on_hold = 1",
      'do_not_email' => " AND tmp.do_not_email = 1",
      'is_deceased' => " AND tmp.is_deceased = 1",
      'is_opt_out' => " AND tmp.is_opt_out = 1",
    ];
    foreach ($this->subGroupDetails as $groupId => $details) {
      $data = $details;
      $tmpTable = $this->getTempTableName('c');
      $data['stats']['total_members'] = $this->getGroupContacts($groupId, TRUE);
      
      $table = !empty($details['civigroup_uses_cache']) ? 'civicrm_group_contact_cache' : 'civicrm_group_contact';
      if ($details['civigroup_uses_cache']) {
        $query = "
          SELECT COUNT(*) FROM civicrm_group_contact_cache g 
          INNER JOIN $tmpTable tmp 
          ON g.contact_id = tmp.contact_id 
          WHERE g.group_id = %1  
        ";
      }
      else {
        $query = "
          SELECT COUNT(*) FROM civicrm_group_contact g 
          INNER JOIN $tmpTable tmp 
          ON g.contact_id = tmp.contact_id 
          WHERE g.group_id = %1 AND g.status != 'Removed'  
        ";
        
      }
      foreach ($clauses as $key => $clause) {
        $newQuery = $query . $clause;
        $dao = CRM_Core_DAO::executeQuery($newQuery, [1 => [$groupId, 'Integer']]);
        $data['stats'][$key] = $dao->fetchValue();
      }
      $returnData[$groupId] = $data;      
    }
    return $returnData;
  }
  
  
  public static function crm($entity, $method, $params) {
    CRM_Core_Error::debug_var(__FUNCTION__, func_get_args());
    $result = civicrm_api3($entity, $method, $params);
    return $result;
  }
  
  /**
   * Primes the group contact cache for all related groups.
   */
  protected function fillGroupCache() {
    $group_ids = !empty($this->subGroupDetails) ? array_keys($this->subGroupDetails) : [];
    $group_ids[] = $this->civiGroupId;
    foreach ($group_ids as $group_id) {
      CRM_Contact_BAO_GroupContactCache::loadAll($group_id);
    }    
  }
  
  /**
   * Get the contacts in a group.
   * 
   * @param unknown $groupId
   * @param unknown $countOnly
   * @return mixed
   */
  public function getGroupContacts($groupId, $countOnly = FALSE) {
    if ($countOnly) {
      $result = $this->crm('Contact', 'getcount',[
        'is_deleted' => 0,
        'group' => $groupId, 
      ]);
      CRM_Core_Error::debug_var(__FUNCTION__, $result);
      return $result;
    }
    else {
      $memberResult = $this->crm('Contact', 'get',[
        'is_deleted' => 0,
        'group' => $groupId, 
        'sequential' => 0,
        'return' => ['is_opt_out', 'do_not_email', 'on_hold', 'is_deceased'],
        'options' => ['limit' => 0],
      ]);
      return !empty($memberResult['values']) ? $memberResult['values'] : [];
    }
  }
  
  
  /**
   * Collect a breakdown of contact/email status for a group.
   * @return number[]
   */
  public function collectCiviMembershipDetails() {
   
    // Get all contacts in group.
    
    $resultData = [
      'total_members' => 0,
      'total_invalid' => 0,
      'on_hold' => 0,
      'is_opt_out' => 0,
      'do_not_email' => 0,
      'is_deceased' => 0,
      'no_valid_email' => 0,
      'duplicated_emails' => 0,
    ];
    $this->fillGroupCache();
     
    $members = $this->getGroupContacts($this->civiGroupId);
    if (!$members) {
      return $resultData; 
    }
    $emails = $this->crm('Email', 'get', [
      'on_hold' => 0,
      'return' => ['contact_id', 'email', 'is_bulkmail', 'is_primary', 'on_hold'],
      'contact_id' => ['IN' => array_keys($members)],
      'options' => ['limit' => 0],
    ]);
    
    
    foreach ($emails['values'] as $email) {
      if ($email['on_hold'] || empty($email['email']) || !filter_var($email['email'], FILTER_VALIDATE_EMAIL)) {
        continue;
      }
      if ($email['is_bulkmail']) {
        $members[$email['contact_id']]['bulk_email'] = $email['email'];
      }
      elseif ($email['is_primary']) {
        $members[$email['contact_id']]['primary_email'] = $email['email'];
      }
      else {
        $members[$email['contact_id']]['other_email'] = $email['email'];
      }
    }
    unset($emails);
    $emailDupes = [];
    $resultData['total_members'] = count($members);
   
    // Create temp table to store member data.    
    $tableName = $this->getTempTableName('c');
    $dao = $this->createTempTable($tableName);
    $db = $dao->getDatabaseConnection();    
   
    $insert = $db->prepare("INSERT IGNORE INTO $tableName VALUES(?, ?, ?, ?, ?, ?, ?, ?)");
    
    foreach ($members as $contact) {
      $email = '';
      // Which email to use?
      foreach (['bulk_email', 'primary_email', 'other_email'] as $emailKey) {
        if (!empty($contact[$emailKey])) {
          $email = $contact[$emailKey];
          break;
        }
      }
      // 
      $invalid = $isDuplicate = FALSE;
      if (!$email) {
        $resultData['no_valid_email']++;
        $invalid = TRUE;
      }
      elseif (!empty($emailDupes[$email])) {
        $resultData['duplicated_emails']++;       
        $isDuplicate = TRUE;
      }
      else {
        $emailDupes[$email] = 1;
      }
      foreach (['is_opt_out', 'on_hold', 'do_not_email', 'is_deceased'] as $validKey) {
        if (!empty($contact[$validKey])) {
          $resultData[$validKey]++;
          $invalid = TRUE;
        }
      }
      if ($invalid || $isDuplicate) {
        $resultData['total_invalid']++;
      }
      $db->execute($insert, [
         intval($contact['id']), 
         $email,
         intval($invalid),
         intval($isDuplicate),
         intval($contact['on_hold']),
         intval($contact['do_not_email']),
         intval($contact['is_deceased']),
         intval($contact['is_opt_out']),
       ]);
      
    }
    $db->freePrepared($insert);
    return $resultData;   
  }
    
  /*
   * Create table for storing membership data.
   * 
   * @param string $tableName
   * 
   * @return CRM_Core_DAO
   */
  public function createTempTable($tableName) {
    if (!$tableName) {
      return FALSE;
    }
    CRM_Core_DAO::executeQuery( "DROP TABLE IF EXISTS $tableName;");
    $dao = CRM_Core_DAO::executeQuery(
    "CREATE TABLE $tableName (
        contact_id INT(10) NOT NULL,
        email VARCHAR(200) NOT NULL,
        invalid TINYINT DEFAULT 0,
        is_duplicate TINYINT DEFAULT 0,
        on_hold TINYINT DEFAULT 0,
        do_not_email TINYINT DEFAULT 0,
        is_deceased TINYINT DEFAULT 0,
        is_opt_out TINYINT DEFAULT 0,
        PRIMARY KEY (contact_id),
        KEY (email))
        ENGINE=InnoDB DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ;");
     return $dao;
  }
  
}
