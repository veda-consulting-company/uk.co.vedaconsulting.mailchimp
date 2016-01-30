<?php

require_once 'CRM/Core/Page.php';

class CRM_Mailchimp_Page_Mailchimp extends CRM_Core_Page {
  function run() {
    // Example: Set the page-title dynamically; alternatively, declare a static title in xml/Menu/*.xml
    CRM_Utils_System::setTitle(ts('Mailchimp'));
    
    $error_count = $_GET['error_count'];
    $group_id = $_GET['group_id'];

    $queryParams = array(
      1 => array($error_count, 'Integer'),
    );
    $seletQuery = "SELECT `email`, `error` FROM `mailchimp_civicrm_syn_errors` WHERE `error_count` = %1 AND `group_id` IN( $group_id )";
    $displayResults = CRM_Core_DAO::executeQuery($seletQuery, $queryParams);
    $result = array();

    while ($displayResults->fetch()) {
      $result [$displayResults->email] = $displayResults->toArray();
    }
    $this->assign('errordetails', $result);
    parent::run();
  }
}
