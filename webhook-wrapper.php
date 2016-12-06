<?php
/**
 * @file
 * Wordpress has an issue receiving data from Mailchimp because Mailchimp uses
 * some of Wordpress's reserved keys (https://codex.wordpress.org/Reserved_Terms), 
 * namely, $_POST['type'].
 *
 * To get around this problem, Wordpress users can point Mailchimp at this file
 * instead, which will act as a proxy but wrap the payload from Mailchimp in a
 * civicrm_mailchimp key.
 *
 * This requires you to have hosting that allows fopen(remote http).
 *
 */

use GuzzleHttp\Client;

// Construct the URL. We do some quick validation here, too.
if (empty($_GET['key'])) {
  header("$_SERVER[SERVER_PROTOCOL] 400 Invalid Request - Missing key");
  exit;
}

// Load dependencies (Guzzle)
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'vendor/autoload.php');

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$url = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST']
  . '/?'
  . http_build_query([
    'key' => $_GET['key'],
    'page' => 'CiviCRM',
    'q' => 'civicrm/mailchimp/webhook',
  ]);

// Wrap the POST data.
$data = ['civicrm_mailchimp' => $_POST];

// POST the data with Guzzle.
$client = new Client();
$response = $client->request('POST', $url, [
  'form_params' => $data,
  'verify' => FALSE,
]);

// Pass it on.
header(
  "$_SERVER[SERVER_PROTOCOL] "
  . $response->getStatusCode()
  . " "
  . $response->getReasonPhrase()
);
// We return JSON
header('Content-type: application/json');
echo $response->getBody();
