<?php

class CRM_Mailchimp_BAO_MCSync extends CRM_Mailchimp_DAO_MCSync {

  public static function create($params) {
    $instance = new self();
    $instance->email_id = $params['email_id'];
    $instance->find(TRUE);

    $instance->copyValues($params);
    $instance->save();
  }

  public static function getSyncStats() {
    $stats = array('Added' => 0, 'Updated' => 0, 'Error' => 0);
    $query = "SELECT sync_status as status, count(*) as count FROM civicrm_mc_sync GROUP BY sync_status";
    $dao   = CRM_Core_DAO::executeQuery($query);
    while ($dao->fetch()) {
      $stats[$dao->status] = $dao->count;
    }
    return $stats;
  }
}
