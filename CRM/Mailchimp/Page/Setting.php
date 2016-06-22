<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.7                                                |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2015                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
 */

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC (c) 2004-2015
 */

/**
 * Main page for viewing Notes.
 */
class CRM_Mailchimp_Page_Setting extends CRM_Core_Page {

  /**
   * The action links for notes that we need to display for the browse screen
   *
   * @var array
   */
  static $_links = NULL;

  /**
   * The action links for comments that we need to display for the browse screen
   *
   * @var array
   */
  static $_commentLinks = NULL;

  /**
   * View details of a note.
   */
  public function view() {

  }

  /**
   * called when action is browse.
   */
  public function browse() {
    $dao = CRM_Core_DAO::executeQuery("SELECT id, api_key, security_key, list_removal FROM mailchimp_civicrm_account");
    $values = array();
    $links = self::links();
    $action = array_sum(array_keys($links));

    //$dao->find();
    while ($dao->fetch()) {
        $values[$dao->id]['api_key']= $dao->api_key;
        $values[$dao->id]['security_key']= $dao->security_key;
        $values[$dao->id]['list_removal']= $dao->list_removal;
        $values[$dao->id]['action'] = CRM_Core_Action::formLink($links,
          $action,
          array(
            'id' => $dao->id,
          )
        );
    }
    $this->assign('accounts', $values);
  }

  /**
   * called when action is update or new.
   */
  public function edit() {
    $controller = new CRM_Core_Controller_Simple('CRM_Mailchimp_Form_Setting', ts('Mailchimp Setting'), $this->_action);
    $controller->setEmbedded(TRUE);

    // set the userContext stack
    $session = CRM_Core_Session::singleton();
    $url = CRM_Utils_System::url('civicrm/mailchimp/view/account',
      'action=browse'
    );
    $session->pushUserContext($url);

    if (CRM_Utils_Request::retrieve('confirmed', 'Boolean',
      CRM_Core_DAO::$_nullObject
    )
    ) {
      //CRM_Core_BAO_Note::del($this->_id);
      $deleteQuery = "DELETE FROM mailchimp_civicrm_account WHERE id = %1";
      $deleteQueryParams = array(1=>array($this->_id));
      CRM_Core_DAO::executeQuery($deleteQuery, $deleteQueryParams);
      CRM_Utils_System::redirect($url);
    }

    $controller->reset();
    $controller->set('id', $this->_id);

    $controller->process();
    $controller->run();
  }

  public function preProcess() {
    $this->_id = CRM_Utils_Request::retrieve('id', 'Positive', $this);
    $this->_action = CRM_Utils_Request::retrieve('action', 'String', $this, FALSE, 'browse');
    $this->assign('action', $this->_action);
  }

  /**
   * the main function that is called when the page loads,
   * it decides the which action has to be taken for the page.
   *
   * @return null
   */
  public function run() {
    $this->preProcess();

    if ($this->_action & CRM_Core_Action::VIEW) {
      $this->view();
    }
    elseif ($this->_action & (CRM_Core_Action::UPDATE | CRM_Core_Action::ADD)) {
      $this->edit();
    }
    elseif ($this->_action & CRM_Core_Action::DELETE) {
      // we use the edit screen the confirm the delete
      $this->edit();
    }

    $this->browse();
    return parent::run();
  }

  /**
   * Delete the note object from the db.
   */
  public function delete() {
    //CRM_Core_BAO_Note::del($this->_id);
  }

  /**
   * Get action links.
   *
   * @return array
   *   (reference) of action links
   */
  public static function &links() {
    if (!(self::$_links)) {
      $deleteExtra = ts('Are you sure you want to delete this account?');

      self::$_links = array(
        CRM_Core_Action::UPDATE => array(
          'name' => ts('Edit'),
          'url' => 'civicrm/mailchimp/view/account',
          'qs' => 'action=update&reset=1&id=%%id%%',
          'title' => ts('Edit Account'),
        ),
        CRM_Core_Action::DELETE => array(
          'name' => ts('Delete'),
          'url' => 'civicrm/mailchimp/view/account',
          'qs' => 'action=delete&reset=1&id=%%id%%',
          'title' => ts('Delete Account'),
        ),
      );
    }
    return self::$_links;
  }

}
