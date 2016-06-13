<?php
/**
 * @file
 * Exception for all Mailchimp API operations that result in a 5xx error, or do
 * not return JSON (network timeouts).
 */
class CRM_Mailchimp_NetworkErrorException extends CRM_Mailchimp_Exception {}
