<?php
/**
 * @file
 * Mailchimp API v3.0 service wrapper.
 *
 * ## Errors ##
 *
 * According to:
 * http://developer.mailchimp.com/documentation/mailchimp/guides/get-started-with-mailchimp-api-3/#errors
 * Errors are always reported with a 4xx (fault probably ours) or 5xx (probably
 * theirs) http status code, *and* a data structure like this:
 * {
 *  "type":"http://kb.mailchimp.com/api/error-docs/405-method-not-allowed",
 *  "title":"Method Not Allowed",
 *  "status":405,
 *  "detail":"The requested method and resource are not compatible. See the
 *            Allow header for this resource's available methods.",
 *  "instance":""
 * }
 *
 * It also says that if you don't get JSON back, it's probably a timeout error.
 *
 * The request functions below should set up the response object and return it
 * in the case of a non-error response. Otherwise they should throw one of
 * CRM_Mailchimp_NetworkErrorException or CRM_Mailchimp_RequestErrorException
 *
 */
class CRM_Mailchimp_Api3 {
  /** string Mailchimp API key */
  protected $api_key;

  /** string URL to API end point. All API resources extend this. */
  protected $server;

  /** bool If set will use curl to talk to Mailchimp's API. Otherwise no
    *  networking. */
  protected $network_enabled=TRUE;

  /** Callback for testing */
  protected $mock_curl = NULL;
  /** Object that holds details used in the latest request.
   *  Public access just for testing purposes.
   */
  public $request;
  /** Object that holds the latest response.
   *  Props are http_code (e.g. 200 for success, found) and data.
   *
   *  This is returned from any of the get() post() etc. methods, but may be
   *  accessed directly as a property of this object, too.
   */
  public $response;
  /** For debugging. */
  protected static $request_id=0;
  /** callback - if set logging will happen via the log() method.
   *
   *  Nb. a CiviCRM_Core_Error::debug_log_message facility is injected if you
   *  enable debugging on the Mailchimp settings screen. But you can inject
   *  something different, e.g. for testing.
   */ 
  protected $log_facility;
  /**
   * @param array $settings contains key 'api_key', possibly other settings.
   */
  public function __construct($settings) {

    // Check we have an api key.
    if (empty($settings['api_key'])) {
      throw new InvalidArgumentException("API Key required.");
    }
    $this->api_key = $settings['api_key'];

    // Set URL based on datacentre identifier at end of api key.                                       
    preg_match('/^.*-([^-]+)$/', $this->api_key, $matches);
    if (empty($matches[1])) {
      throw new InvalidArgumentException("Invalid API key - could not extract datacentre from given API key.");      
    }

    if (!empty($settings['log_facility'])) {
      $this->setLogFacility($settings['log_facility']);
    }

    $datacenter = $matches[1];
    $this->server = "https://$datacenter.api.mailchimp.com/3.0";
  }
  /**
   * Sets the log_facility to a callback
   */
  public function setLogFacility($callback) {
    if (!is_callable($callback)) {
      throw new InvalidArgumentException("Log facility callback is not callable.");      
    }
    $this->log_facility = $callback;
  }

  /**
   * Perform a GET request.
   */
  public function get($url, $data=null) {
    return $this->makeRequest('GET', $url, $data);
  }

  /**
   * Perform a POST request.
   */
  public function post($url, Array $data) {
    return $this->makeRequest('POST', $url, $data);
  }

  /**
   * Perform a PUT request.
   */
  public function put($url, Array $data) {
    return $this->makeRequest('PUT', $url, $data);
  }

  /**
   * Perform a PATCH request.
   */
  public function patch($url, Array $data) {
    return $this->makeRequest('PATCH', $url, $data);
  }

  /**
   * Perform a DELETE request.
   */
  public function delete($url, $data=null) {
    return $this->makeRequest('DELETE', $url);
  }

  /**
   * Perform a /batches POST request and sit and wait for the result.
   *
   * It quicker to run small ops directly for <15 items.
   *
   * @param array $batch - operations to batch. By reference to save memory.
   * @param string $method
   * @return the result of the last Mailchimp API call.
   */
  public function batchAndWait(Array &$batch, $method=NULL) {
    // This can take a long time...
    set_time_limit(0);

    if ($method === NULL) {
      // Automatically determine fastest method.
      $method = (count($batch) < 15) ? 'multiple' : 'batch';
    }
    elseif (!in_array($method, ['multiple', 'batch'])) {
      throw new InvalidArgumentException("Method argument must be mulitple|batch|NULL, given '$method'");
    }

    // Validate the batch operations.
    foreach ($batch as $i=>$request) {
      if (count($request)<2) {
        throw new InvalidArgumentException("Batch item $i invalid - at least two values required.");
      }
      if (!preg_match('/^get|post|put|patch|delete$/i', $request[0])) {
        throw new InvalidArgumentException("Batch item $i has invalid method '$request[0]'.");
      }
      if (substr($request[1], 0, 1) != '/') {
        throw new InvalidArgumentException("Batch item $i has invalid path should begin with /. Given '$request[1]'");
      }
    }

    // Choose method and submit.
    if ($method == 'batch') {
      // Submit a batch request and wait for it to complete.
      $batch_result = $this->makeBatchRequest($batch);

      do {
        sleep(3);
        $result = $this->get("/batches/{$batch_result->data->id}");
      } while ($result->data->status != 'finished');

      // Now complete.
      // Note: we have no way to check the errors. Mailchimp make a downloadable
      // .tar.gz file with one file per operation available, however PHP (as of
      // writing) has a bug (I've reported it
      // https://bugs.php.net/bug.php?id=72394) in its PharData class that
      // handles opening of tar files which means there's no way we can access
      // that info. So we have to ignore errors.
      return $result;
    }
    else {
      // Submit the requests one after another.
      foreach ($batch as $item) {
        $method = strtolower($item[0]);
        $path = $item[1];
        $data = isset($item[2]) ? $item[2] : [];
        try {
          $this->$method($path, $data);
        }
        catch (CRM_Mailchimp_RequestErrorException $e) {
          // Here we ignore exceptions from Mailchimp not because we want to,
          // but because we have no way of handling such errors when done for
          // 15+ items in a proper batch, so we don't handle them here either.
        }
      }
    }
  }
  /**
   * Sends a batch request.
   *
   * @param array batch array of arrays which contain three values: the method,
   *   the path (e.g. /lists) and the data describing a set of requests.
   *   We pass it by reference to save memory.
   *
   * @return array The result of the Mailchimp API call.
   */
  public function makeBatchRequest(Array &$batch) {
    $ops = [];
    foreach ($batch as $request) {
      $op = ['method' => strtoupper($request[0]), 'path' => $request[1]];
      if (!empty($request[2])) {
        if ($op['method'] == 'GET') {
          $op['params'] = $request[2];
        }
        else {
          $op['body'] = json_encode($request[2]);
        }
      }
      $ops []= $op;
    }
    // Reference to $ops to save memory.
    $result = $this->post('/batches', ['operations' => &$ops]);

    return $result;
  }
  /**
   * Setter for $network_enabled.
   */
  public function setNetworkEnabled($enable=TRUE) {
    $this->network_enabled = (bool) $enable;
  }
  /**
   * Provide mock for curl.
   *
   * The callback will be called with the 
   * request object in $this->request. It must return an array with optional
   * keys:
   *
   * - exec the mocked output of curl_exec(). Defaults to '{}'.
   * - info the mocked output of curl_getinfo(), defaults to an array:
   *   - http_code    => 200
   *   - content_type => 'application/json'
   *
   * Note the object must be operating with network-enabled for this to be
   * called; it exactly replaces the curl work.
   *
   * @param null|callback $callback. If called with anything other than a
   * callback, this functionality is disabled.
   */
  public function setMockCurl($callback) {
    if (is_callable($callback)) {
      $this->mock_curl = $callback;
    }
    else {
      $this->mock_curl = NULL;
    }
  }
  /**
   * All request types handled here.
   *
   * Set up all parameters for the request.
   * Submit the request.
   * Return the response.
   *
   * Implemenations should call this first, then do their curl-ing (or not),
   * then return the response.
   *
   * @throw InvalidArgumentException if called with a url that does not begin
   * with /.
   * @throw CRM_Mailchimp_NetworkErrorException
   * @throw CRM_Mailchimp_RequestErrorException
   */
  protected function makeRequest($method, $url, $data=null) {
    if (substr($url, 0, 1) != '/') {
      throw new InvalidArgumentException("Invalid URL - must begin with root /");
    }
    $this->request = (object) [
      'id' => static::$request_id++,
      'created' => microtime(TRUE),
      'completed' => NULL,
      'method' => $method,
      'url' => $this->server . $url,
      'headers' => ["Content-Type: Application/json;charset=UTF-8"],
      'userpwd' => "dummy:$this->api_key",
      // Set ZLS for default data.
      'data' => '',
      // Mailchimp's certificate chain does not include trusted root for cert for
      // some popular OSes (e.g. Debian Jessie, April 2016) so disable SSL verify
      // peer.
      'verifypeer' => FALSE,
      // ...but we can check that the certificate has the domain we were
      // expecting.@see http://curl.haxx.se/libcurl/c/CURLOPT_SSL_VERIFYHOST.html
      'verifyhost' => 2,
    ];

    if ($data !== null) {
      if ($this->request->method == 'GET') {
        // For GET requests, data must be added as query string.
        // Append if there's already a query string.
        $query_string = http_build_query($data);
        if ($query_string) {
          $this->request->url .= ((strpos($this->request->url, '?')===false) ? '?' : '&')
            . $query_string;
        }
      }
      else {
        // Other requests have it added as JSON
        $this->request->data = json_encode($data);
        $this->request->headers []= "Content-Length: " . strlen($this->request->data);
      }
    }

    // We set up a null response.
    $this->response = (object) [
      'http_code' => null,
      'data' => null,
      ];

    if ($this->network_enabled) {
      $this->sendRequest();
    }
    else {
      // We're not going to send a request.
      // So this is our chance to log something.
      $this->log();
    }
    return $this->response;
  }
  /**
   * Send the request and prepare the response.
   */
  protected function sendRequest() {
    if (!$this->mock_curl) {
      $curl = curl_init();
      curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $this->request->method);
      curl_setopt($curl, CURLOPT_POSTFIELDS, $this->request->data);
      curl_setopt($curl, CURLOPT_HTTPHEADER, $this->request->headers);
      curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
      curl_setopt($curl, CURLOPT_USERPWD, $this->request->userpwd);
      curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $this->request->verifypeer);
      curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, $this->request->verifyhost);
      curl_setopt($curl, CURLOPT_URL, $this->request->url);
      curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
      $result = curl_exec($curl);
      $info = curl_getinfo($curl);
      curl_close($curl);
    }
    else {
      $callback = $this->mock_curl;
      $output = $callback($this->request);
      // Apply defaults to result.
      $output += [
        'exec' => '{}',
        'info' => [],
        ];
      $output['info'] += [
        'http_code' => 200,
        'content_type' => 'application/json',
        ];
      $result = $output['exec'];
      $info   = $output['info'];
    }

    return $this->curlResultToResponse($info, $result);
  }

  /**
   * For debugging purposes.
   *
   * Does nothing without $log_facility being set to a callback.
   *
   */
  protected function log() {
    if (!$this->log_facility) {
      return;
    }

    $msg    = "Request #{$this->request->id}\n=============================================\n";
    if (!$this->network_enabled) {
      $msg .= "Network      : DISABLED\n";
    }
    $msg   .= "Method       : {$this->request->method}\n";
    $msg   .= "Url          : {$this->request->url}\n";

    if (isset($this->request->created)) {
      $msg .= "Took         : " . round((microtime(TRUE) - $this->request->created), 2) . "s\n";
    }
    $msg   .= "Response Code: "
        . (isset($this->response->http_code) ? $this->response->http_code : 'NO RESPONSE HTTP CODE')
        . "\n";

    $msg   .= "Request Body : " . str_replace("\n", "\n               ",
      var_export(json_decode($this->request->data), TRUE)) . "\n";
    $msg   .= "Response Body: " . str_replace("\n", "\n               ",
      var_export($this->response->data, TRUE));
    $msg .= "\n\n";

    // Log response.
    $callback = $this->log_facility;
    $callback($msg);
  }
  /**
   * Prepares the response object from the result of a cURL call.
   *
   * Public to allow testing.
   *
   * @return Array response object.
   * @throw CRM_Mailchimp_RequestErrorException
   * @throw CRM_Mailchimp_NetworkErrorException
   * @param array $info output of curl_getinfo().
   * @param string|null $result output of curl_exec().
   */
  public function curlResultToResponse($info, $result) {

    // Check response.
    if (empty($info['http_code'])) {
      $this->log();
      throw new CRM_Mailchimp_NetworkErrorException($this);
    }

    // Check response object is set up.
    if (!isset($this->response)) {
      $this->response = (object) [
        'http_code' => null,
        'data' => null,
        ];
    }

    // Copy http_code into response object. (May yet be used by exceptions.)
    $this->response->http_code = $info['http_code'];

    // was JSON returned, as expected?
    $json_returned = isset($info['content_type'])
      && preg_match('@^application/(problem\+)?json\b@i', $info['content_type']);


    if (!$json_returned) {
      // According to Mailchimp docs it may return non-JSON in event of a
      // timeout.
      $this->log();
      throw new CRM_Mailchimp_NetworkErrorException($this);
    }

    $this->response->data = $result ? json_decode($result) : null;
    $this->log();

    // Check for errors and throw appropriate CRM_Mailchimp_ExceptionBase.
    switch (substr((string) $this->response->http_code, 0, 1)) {
    case '4': // 4xx errors
      throw new CRM_Mailchimp_RequestErrorException($this);
    case '5': // 5xx errors
      throw new CRM_Mailchimp_NetworkErrorException($this);
    }

    // All good return response as a convenience.
    return $this->response;
  }
}
