<?php
/**
 * @file
 * Exception when duplicate contacts are found.
 */

class CRM_Mailchimp_DuplicateContactsException extends Exception {
  public $contacts;
  public function __construct($contacts) {
    $this->contacts = $contacts;
    parent::__construct("Duplicate Contacts found.");
  }
}
