<?php
/**
 * @file
 * Exception base class for all Mailchimp API exceptions.
 */

abstract class CRM_Mailchimp_Exception extends Exception {

  public $request;

  public $response;

  public function __construct(CRM_Mailchimp_Api3 $api, $message_prefix='') {
    $this->request = isset($api->request) ? clone($api->request) : NULL;
    $this->response = isset($api->response) ? clone($api->response) : NULL;

    if (isset($this->response->data->title)) {
      $message = $message_prefix . 'Mailchimp API said: ' . $this->response->data->title
        . (empty($this->response->data->detail) ? '' : " (" . $this->response->data->detail . ")");
    }
    else {
      $message = $message_prefix . 'No data received, possibly a network timeout';
    }
    parent::__construct($message, $this->response->http_code);
  }

}
