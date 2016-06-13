<?php
$classes_root =  implode(DIRECTORY_SEPARATOR,[dirname(dirname(__DIR__)), 'CRM', 'Mailchimp', '']);
require $classes_root . 'Exception.php';
require $classes_root . 'NetworkErrorException.php';
require $classes_root . 'RequestErrorException.php';
require $classes_root . 'Api3.php';

/**
 * Unit tests for Mailchimp API.
 */

class MailchimpApiUnitTest extends \PHPUnit_Framework_TestCase {

  protected $mock_api_key = 'shhhhhhhhhhhhhh-uk1';
  protected $api;
  /**
   * Gets instance of the API.
   */
  protected function getApi($settings='SETTINGS_NOT_PROVIDED') {
    if ($settings === 'SETTINGS_NOT_PROVIDED') {
      $settings = ['api_key' => $this->mock_api_key];
    }
    if (!isset($this->api)) {
      $this->api = new CRM_Mailchimp_Api3($settings);
      // We don't want our api actually talking to Mailchimp.
      $this->api->setNetworkEnabled(FALSE);
    }
    return $this->api;
  }

  /**
   * The API must be initiated with API key in the settings array.
   *
   * @expectedException InvalidArgumentException
   */
  public function testApiKeyRequiredNull() {
    $this->getApi(null);
  }

  /**
   * The API must be initiated with API key in the settings array.
   *
   * @expectedException InvalidArgumentException
   */
  public function testApiKeyRequiredEmptyArray() {
    $this->getApi([]);
  }

  /**
   * The API must be initiated with API key in the settings array.
   *
   * @expectedException InvalidArgumentException
   */
  public function testApiKeyRequiredEmptyKey() {
    $this->getApi(['api_key' => null]);
  }
  /**
   * The API key must end in a datacentre subdomain prefix.
   *
   * @expectedException InvalidArgumentException
   */
  public function testApiKeyFailsWithoutDatacentre() {
    $this->getApi(['api_key' => 'foo']);
  }
  /**
   * Test get API.
   *
   */
  public function testGetApi() {
    $api = $this->getApi();
    $this->assertInstanceOf('CRM_Mailchimp_Api3', $api);
  }

  /**
   * Check a request for a resource that does not start / fails.
   *
   * @expectedException InvalidArgumentException
   */
  public function testBadResourceUrl() {
    $api = $this->getApi();
    $api->get('foo');
  }
  /**
   * Check GET requests are being created properly.
   */
  public function testGetRequest() {
    $api = $this->getApi();
    $response = $api->get('/foo');
    $request  = $api->request;

    // Check the request URL was properly assembled.
    $this->assertTrue(isset($request->url));
    $this->assertEquals( "https://uk1.api.mailchimp.com/3.0/foo", $request->url);
    $this->assertEquals( "GET", $request->method);
    $this->assertEquals( "dummy:$this->mock_api_key", $request->userpwd);
    $this->assertFalse($request->verifypeer);
    $this->assertEquals(2, $request->verifyhost);
    $this->assertEquals('', $request->data);
    $this->assertEquals("Content-Type: Application/json;charset=UTF-8", $request->headers);
  }
  /**
   * Check GET requests are being created properly.
   */
  public function testGetRequestQs() {
    $api = $this->getApi();
    $response = $api->get('/foo', ['name'=>'bar']);
    $request  = $api->request;

    // Check the request URL was properly assembled.
    $this->assertTrue(isset($request->url));
    $this->assertEquals( "https://uk1.api.mailchimp.com/3.0/foo?name=bar", $request->url);
  }
  /**
   * Check GET requests are being created properly.
   */
  public function testGetRequestQsAppend() {
    $api = $this->getApi();
    $response = $api->get('/foo?x=1', ['name'=>'bar']);
    $request  = $api->request;

    // Check the request URL was properly assembled.
    $this->assertTrue(isset($request->url));
    $this->assertEquals( "https://uk1.api.mailchimp.com/3.0/foo?x=1&name=bar", $request->url);
  }
  /**
   * Check GET requests throws exception if resource not found.
   *
   * @expectedException CRM_Mailchimp_RequestErrorException
   * @expectedExceptionMessage Mailchimp API said: not found
   */
  public function testNotFoundException() {
    $api = $this->getApi();
    $request  = $api->curlResultToResponse(['http_code'=>404,'content_type'=>'application/json'],'{"title":"not found"}');
  }
  /**
   * Check network exception.
   *
   * @expectedException CRM_Mailchimp_NetworkErrorException
   * @expectedExceptionMessage Mailchimp API said: witty error ha ha so funny.
   */
  public function testNetworkError() {
    $api = $this->getApi();
    $request  = $api->curlResultToResponse(['http_code'=>500,'content_type'=>'application/json'],'{"title":"witty error ha ha so funny."}');
  }
}

