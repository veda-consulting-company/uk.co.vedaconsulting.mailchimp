<?php

use Civi\Test\HeadlessInterface;

/**
 * @file
 * Tests of CRM_Mailchimp_Sync methods that do not need the Mailchimp API.
 *
 * It does not depend on a live Mailchimp account and nor does it need a mock
 * mailchimp api object - these methods don't use the Mailchimp API anyway.
 * However it is not a unit test because it does depend on and make changes to
 * the CiviCRM database.
 *
 * The CRM_Mailchimp_Sync class is also tested in:
 * - MailchimpApiIntegrationMockTest
 * - MailchimpApiIntegrationTest
 *
 * @group headless
 */

class SyncIntegrationTest extends CRM_Mailchimp_IntegrationTestBase implements HeadlessInterface {

  /**
   * If set false then the test method ended cleanly, which saves some teardown/setup
   * It is set to in setUp, so can only get set false by a successful test
   * that leaves the fixture in the same state as it was at the start.
   */
  public static $fixture_should_be_reset = TRUE;

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public static function setUpBeforeClass() {
  }
  /**
   * Create fixture in CiviCRM.
   */
  public function setUp() {
    if (static::$fixture_should_be_reset) {
      static::createCiviCrmFixtures();
    }
    static::$fixture_should_be_reset = TRUE;
  }
  /**
   * Reset the fixture's contact and group records.
   */
  public function tearDown() {
    if (static::$fixture_should_be_reset) {
      static::tearDownCiviCrmFixtureContacts();
    }
  }

  /**
   * Tests the guessContactIdsBySubscribers method.
   *
   */
  public function testGuessContactIdsBySubscribers() {
    // Create empty tables.
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
    CRM_Mailchimp_Sync::createTemporaryTableForCiviCRM();

    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_c (email, contact_id) VALUES
      ('found@example.com', 1),
      ('red-herring@example.com', 2);");
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email) VALUES
      ('found@example.com'),
      ('notfound@example.com');");

    // Check for a match.
    $matched = CRM_Mailchimp_Sync::guessContactIdsBySubscribers();
    $this->assertEquals(1, $matched);

    // Check the matched record did indeed match.
    $result = CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) c FROM tmp_mailchimp_push_m WHERE email = "found@example.com" AND cid_guess = 1');
    $this->assertEquals(1, $result);

    // Check the other one did not.
    $result = CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) c FROM tmp_mailchimp_push_m WHERE email = "notfound@example.com" AND cid_guess IS NULL');
    $this->assertEquals(1, $result);

    CRM_Mailchimp_Sync::dropTemporaryTables();
  }
  /**
   * Tests the guessContactIdsByUniqueEmail method.
   *
   */
  public function testGuessContactIdsByUniqueEmail() {
    //
    // Test 1: Primary case: match a unique email.
    //
    // Create empty tables.
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email) VALUES (%1), (%2);",[
      1 => [static::$civicrm_contact_1['email'], 'String'],
      2 => ['notfound@example.com', 'String'],
    ]);
    CRM_Mailchimp_Sync::guessContactIdsByUniqueEmail();
    // Check the matched record did indeed match.
    $dao = CRM_Core_DAO::executeQuery('SELECT COUNT(*) c FROM tmp_mailchimp_push_m WHERE email = %1 AND cid_guess = ' . static::$civicrm_contact_1['contact_id'],[
      1 => [static::$civicrm_contact_1['email'], 'String'],
    ]);
    $dao->fetch();
    $this->assertEquals(1, $dao->c);
    // Check the other one did not.
    $dao = CRM_Core_DAO::executeQuery('SELECT COUNT(*) c FROM tmp_mailchimp_push_m WHERE email = "notfound@example.com" AND cid_guess IS NULL');
    $dao->fetch();
    $this->assertEquals(1, $dao->c);

    //
    // Test 2: Secondary case: match an email unique to one person.
    //
    // Start again, this time the email will be unique to a contact, but not
    // unique in the email table, e.g. it's in twice, but for the same contact.
    //
    civicrm_api3('Email', 'create', [
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'email' => static::$civicrm_contact_1['email'],
      'is_billing' => 1,
      ]);
    CRM_Mailchimp_Sync::dropTemporaryTables();
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email) VALUES (%1);",[
      1 => [static::$civicrm_contact_1['email'], 'String'],
    ]);
    $matches = CRM_Mailchimp_Sync::guessContactIdsByUniqueEmail();
    $this->assertEquals(1, $matches);
    // Check the matched record did indeed match.
    $dao = CRM_Core_DAO::executeQuery('SELECT COUNT(*) c FROM tmp_mailchimp_push_m WHERE email = %1 AND cid_guess = ' . static::$civicrm_contact_1['contact_id'],[
      1 => [static::$civicrm_contact_1['email'], 'String'],
    ]);
    $dao->fetch();
    $this->assertEquals(1, $dao->c);

    //
    // Test 3: Primary negative case: if an email is owned by 2 different
    // contacts, we cannot match it.
    //
    static::tearDownCiviCrmFixtureContacts();
    static::createCiviCrmFixtures();
    civicrm_api3('Email', 'create', [
      'contact_id' => static::$civicrm_contact_2['contact_id'],
      'email' => static::$civicrm_contact_1['email'],
      'is_billing' => 1,
      ]);
    CRM_Mailchimp_Sync::dropTemporaryTables();
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email) VALUES (%1);",[
      1 => [static::$civicrm_contact_1['email'], 'String'],
    ]);
    CRM_Mailchimp_Sync::guessContactIdsByUniqueEmail();
    // Check no match.
    $dao = CRM_Core_DAO::executeQuery('SELECT COUNT(*) c FROM tmp_mailchimp_push_m WHERE email = %1 AND cid_guess IS NULL', [
      1 => [static::$civicrm_contact_1['email'], 'String'],
    ]);
    $dao->fetch();
    $this->assertEquals(1, $dao->c);

  }
  /**
   * Tests the guessContactIdsByUniqueEmail method ignores deleted contacts.
   *
   */
  public function testGuessContactIdsByUniqueEmailIgnoresDeletedContacts() {
    //
    // Test 1: Primary case: match a unique email.
    //
    // Create empty tables.
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email) VALUES (%1), (%2);",[
      1 => [static::$civicrm_contact_1['email'], 'String'],
      2 => ['notfound@example.com', 'String'],
    ]);

    // Delete (trash) the contact.
    civicrm_api3('Contact', 'delete', ['contact_id' => static::$civicrm_contact_1['contact_id']]);

    $result = CRM_Mailchimp_Sync::guessContactIdsByUniqueEmail();
    $this->assertEquals(0, $result);
    // Check that the email that belongs to the deleted contact did not match.
    $dao = CRM_Core_DAO::executeQuery('SELECT COUNT(*) c FROM tmp_mailchimp_push_m WHERE email = %1 AND cid_guess = ' . static::$civicrm_contact_1['contact_id'],[
      1 => [static::$civicrm_contact_1['email'], 'String'],
    ]);
    $dao->fetch();
    $this->assertEquals(0, $dao->c);
    $dao->free();

    // Check the other one did not match either.
    $dao = CRM_Core_DAO::executeQuery('SELECT COUNT(*) c FROM tmp_mailchimp_push_m WHERE email = "notfound@example.com" AND cid_guess IS NULL');
    $dao->fetch();
    $this->assertEquals(1, $dao->c);
    $dao->free();

    // Test 2: the email belongs to two separate contacts, but one is deleted.
    // so there's only one non-deleted unique contact.
    //
    civicrm_api3('Email', 'create', [
      'contact_id' => static::$civicrm_contact_2['contact_id'],
      'email' => static::$civicrm_contact_1['email'],
      'is_billing' => 1,
      ]);
    CRM_Mailchimp_Sync::dropTemporaryTables();
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email) VALUES (%1);",[
      1 => [static::$civicrm_contact_1['email'], 'String'],
    ]);
    $result = CRM_Mailchimp_Sync::guessContactIdsByUniqueEmail();
    $this->assertEquals(1, $result);

    // Test 4: the email belongs to two non-deleted contacts and one deleted
    // contact, therefore is not unique.

    // Need a third contact.
    $contact3 = civicrm_api3('Contact', 'create', [
      'contact_type' => 'Individual',
      'first_name' => 'Other ' . static::C_CONTACT_1_FIRST_NAME,
      'last_name' => static::C_CONTACT_1_LAST_NAME,
      'email' => static::$civicrm_contact_1['email'],
      ]);

    CRM_Mailchimp_Sync::dropTemporaryTables();
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email) VALUES (%1);",[
      1 => [static::$civicrm_contact_1['email'], 'String'],
    ]);
    $result = CRM_Mailchimp_Sync::guessContactIdsByUniqueEmail();
    // remove contact3.
    civicrm_api3('Contact', 'delete', [
      'contact_id' => $contact3['id'],
      'skip_undelete' => 1,
      ]);
    $this->assertEquals(0, $result);
  }
  /**
   * Tests the guessContactIdsByNameAndEmail method.
   *
   */
  public function testGuessContactIdsByNameAndEmail() {
    //
    // Test 1: Primary case: match on name, email when they only match one
    // contact.
    //
    // Create empty tables.
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email, first_name, last_name)
      VALUES (%1, %2, %3);",[
      1 => [static::$civicrm_contact_1['email'], 'String'],
      2 => [static::$civicrm_contact_1['first_name'], 'String'],
      3 => [static::$civicrm_contact_1['last_name'], 'String'],
    ]);
    CRM_Mailchimp_Sync::guessContactIdsByNameAndEmail();
    // Check the matched record did indeed match.
    $dao = CRM_Core_DAO::executeQuery('SELECT COUNT(*) c FROM tmp_mailchimp_push_m WHERE email = %1 AND cid_guess = ' . static::$civicrm_contact_1['contact_id'],[
      1 => [static::$civicrm_contact_1['email'], 'String'],
    ]);
    $dao->fetch();
    $this->assertEquals(1, $dao->c);

    //
    // Test 2: Check this still works if contact 2 shares the email address (but
    // has a different name)
    //
    civicrm_api3('Email', 'create', [
      'contact_id' => static::$civicrm_contact_2['contact_id'],
      'email' => static::$civicrm_contact_1['email'],
      'is_billing' => 1,
      ]);
    CRM_Mailchimp_Sync::dropTemporaryTables();
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email, first_name, last_name)
      VALUES (%1, %2, %3);",[
      1 => [static::$civicrm_contact_1['email'], 'String'],
      2 => [static::$civicrm_contact_1['first_name'], 'String'],
      3 => [static::$civicrm_contact_1['last_name'], 'String'],
    ]);
    CRM_Mailchimp_Sync::guessContactIdsByNameAndEmail();
    // Check the matched record did NOT match.
    $dao = CRM_Core_DAO::executeQuery('SELECT COUNT(*) c FROM tmp_mailchimp_push_m WHERE email = %1 AND cid_guess = ' . static::$civicrm_contact_1['contact_id'],[
      1 => [static::$civicrm_contact_1['email'], 'String'],
    ]);
    $dao->fetch();
    $this->assertEquals(1, $dao->c);

    //
    // Test 2: Check that if there's 2 matches, we fail to guess.
    // Give Contact2 the same email and name as contact 1
    //
    civicrm_api3('Contact', 'create', [
      'contact_id' => static::$civicrm_contact_2['contact_id'],
      'first_name' => static::$civicrm_contact_1['first_name'],
      'last_name'  => static::$civicrm_contact_1['last_name'],
      ]);
    CRM_Mailchimp_Sync::dropTemporaryTables();
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email, first_name, last_name)
      VALUES (%1, %2, %3);",[
      1 => [static::$civicrm_contact_1['email'], 'String'],
      2 => [static::$civicrm_contact_1['first_name'], 'String'],
      3 => [static::$civicrm_contact_1['last_name'], 'String'],
    ]);
    CRM_Mailchimp_Sync::guessContactIdsByNameAndEmail();
    // Check the matched record did NOT match.
    $dao = CRM_Core_DAO::executeQuery('SELECT COUNT(*) c FROM tmp_mailchimp_push_m WHERE email = %1 AND cid_guess IS NULL;',[
      1 => [static::$civicrm_contact_1['email'], 'String'],
    ]);
    $dao->fetch();
    $this->assertEquals(1, $dao->c);

  }
  /**
   * Tests the guessContactIdsByNameAndEmail method with deleted contacts in the
   * mix.
   *
   */
  public function testGuessContactIdsByNameAndEmailIgnoresDeletedContacts() {
    //
    // Test 1: Primary case: only one contact matches on name+email but it's
    // deleted. Should not match.
    //
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email, first_name, last_name)
      VALUES (%1, %2, %3);",[
      1 => [static::$civicrm_contact_1['email'], 'String'],
      2 => [static::$civicrm_contact_1['first_name'], 'String'],
      3 => [static::$civicrm_contact_1['last_name'], 'String'],
    ]);
    // Delete (trash) the contact.
    civicrm_api3('Contact', 'delete', ['contact_id' => static::$civicrm_contact_1['contact_id']]);
    $result = CRM_Mailchimp_Sync::guessContactIdsByNameAndEmail();
    $this->assertEquals(0, $result);

    //
    // Test 2: Check if contact 2 shares the email address and name
    //
    // Contact 2 should be matched.
    // change contact2's name.
    civicrm_api3('Contact', 'create', [
      'contact_id' => static::$civicrm_contact_2['contact_id'],
      'first_name' => static::$civicrm_contact_1['first_name'],
      'last_name' => static::$civicrm_contact_1['last_name'],
      ]);
    // and email.
    civicrm_api3('Email', 'create', [
      'contact_id' => static::$civicrm_contact_2['contact_id'],
      'email' => static::$civicrm_contact_1['email'],
      'is_billing' => 1,
      ]);
    CRM_Mailchimp_Sync::dropTemporaryTables();
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email, first_name, last_name)
      VALUES (%1, %2, %3);",[
      1 => [static::$civicrm_contact_1['email'], 'String'],
      2 => [static::$civicrm_contact_1['first_name'], 'String'],
      3 => [static::$civicrm_contact_1['last_name'], 'String'],
    ]);
    $result = CRM_Mailchimp_Sync::guessContactIdsByNameAndEmail();
    $this->assertEquals(1, $result);

    // Check the matched record did match contact 2.
    $result = CRM_Core_DAO::singleValueQuery(
      'SELECT COUNT(*) c FROM tmp_mailchimp_push_m
      WHERE email = %1 AND cid_guess = ' . static::$civicrm_contact_2['contact_id'],
      [1 => [static::$civicrm_contact_1['email'], 'String'],
    ]);
    $this->assertEquals(1, $result);

    // Test 3: a third contact matches name and email - no longer unique, should
    // not match.
    $contact3 = civicrm_api3('Contact', 'create', [
      'contact_type' => 'Individual',
      'first_name' => static::C_CONTACT_1_FIRST_NAME,
      'last_name' => static::C_CONTACT_1_LAST_NAME,
      'email' => static::$civicrm_contact_1['email'],
      ]);
    CRM_Mailchimp_Sync::dropTemporaryTables();
    CRM_Mailchimp_Sync::createTemporaryTableForMailchimp();
    CRM_Core_DAO::executeQuery("INSERT INTO tmp_mailchimp_push_m (email, first_name, last_name)
      VALUES (%1, %2, %3);",[
      1 => [static::$civicrm_contact_1['email'], 'String'],
      2 => [static::$civicrm_contact_1['first_name'], 'String'],
      3 => [static::$civicrm_contact_1['last_name'], 'String'],
    ]);
    $result = CRM_Mailchimp_Sync::guessContactIdsByNameAndEmail();
    // Remove 3rd contact.
    civicrm_api3('Contact', 'delete', [
      'contact_id' => $contact3['id'],
      'skip_undelete' => 1,
      ]);
    // check it did not match.
    $this->assertEquals(0, $result);

  }
}
