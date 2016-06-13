<?php

/**
 * Collection of upgrade steps
 */
class CRM_Mailchimp_Upgrader extends CRM_Mailchimp_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Example: Run an external SQL script when the module is installed
   *
  public function install() {
    $this->executeSqlFile('sql/myinstall.sql');
  }

  /**
   * Example: Run an external SQL script when the module is uninstalled
   *
  public function uninstall() {
   $this->executeSqlFile('sql/myuninstall.sql');
  }

  /**
   * Example: Run a simple query when a module is enabled
   *
  public function enable() {
    CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 1 WHERE bar = "whiz"');
  }

  /**
   * Example: Run a simple query when a module is disabled
   *
  public function disable() {
    CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 0 WHERE bar = "whiz"');
  }

  /**
   * Example: Run a couple simple queries
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_13() {
    $this->ctx->log->info('Applying update v1.3');

    $cgId = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_CustomGroup', 'Mailchimp_Settings', 'id', 'name');
    if ($cgId) {
      $query = "INSERT INTO `civicrm_custom_field` (`custom_group_id`, `name`, `label`, `data_type`, `html_type`, `default_value`, `is_required`, `is_searchable`, `is_search_range`, `weight`, `is_active`, `is_view`, `options_per_line`, `text_length`, `start_date_years`, `end_date_years`, `date_format`, `time_format`, `note_columns`, `note_rows`, `column_name`) VALUES ($cgId, 'is_mc_update_grouping', 'Are subscriber able to update this grouping from mailchimp?', 'Boolean', 'Radio', NULL, 0, 0, 0, 4, 1, 0, NULL, 255, NULL, NULL, NULL, NULL, 60, 4, 'is_mc_update_grouping')";
      CRM_Core_DAO::executeQuery($query);

      $query = "Alter table civicrm_value_mailchimp_settings add column is_mc_update_grouping BOOLEAN  DEFAULT NULL COMMENT 'Are subscribers able to update this grouping using mailchimp?'";
      CRM_Core_DAO::executeQuery($query);
    }
    return TRUE;
  }
  
  /**
   * Upgrading to version 1.7
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_17() {
    $this->ctx->log->info('Applying update v1.7');
		
		// Disabled the pull cron job, as we have moved the pull and push to same cron job
		$query = "DELETE FROM civicrm_job WHERE api_entity = 'Mailchimp' AND api_action = 'pull'";
    CRM_Core_DAO::executeQuery($query);
		
    return TRUE;
  }

  /**
   * Mailchimp in their wisdom changed all the Ids for interests.
   *
   * So we have to map on names and then update our stored Ids.
   *
   * Also change cronjobs.
   */
  public function upgrade_20() {
    $this->ctx->log->info('Applying update to v2.0 Updating Mailchimp Interest Ids to fit their new API');
    // New
    $api = CRM_Mailchimp_Utils::getMailchimpApi();
    // Old
    $mcLists = new Mailchimp_Lists(CRM_Mailchimp_Utils::mailchimp());

    // Use new API to get lists. Allow for 10,000 lists so we don't bother
    // batching.
    $lists = [];
    foreach ($api->get("/lists", ['fields'=>'lists.id,lists.name','count'=>10000])->data->lists
      as $list) {
      $lists[$list->id] = ['name' => $list->name];
    }

    $queries = [];
    // Loop lists.
    foreach (array_keys($lists) as $list_id) {
      // Fetch Interest categories.
      $categories = $api->get("/lists/$list_id/interest-categories", ['count' => 10000, 'fields' => 'categories.id,categories.title'])->data->categories;
      if (!$categories) {
        continue;
      }

      // Old: fetch all categories (groupings) and interests (groups) in one go:
      $old = $mcLists->interestGroupings($list_id);
      // New: fetch interests for each category.
      foreach ($categories as $category) {
        // $lists[$list_id]['categories'][$category->id] = ['name' => $category->title];

        // Match this category by name with the old 'groupings'
        $matched_old_grouping = FALSE;
        foreach($old as $old_grouping) {
          if ($old_grouping['name'] == $category->title) {
            $matched_old_grouping = $old_grouping;
            break;
          }
        }
        if ($matched_old_grouping) {
          // Found a match.
          $cat_queries []= ['list_id' => $list_id, 'old' => $matched_old_grouping['id'], 'new' => $category->id];

          // Now do interests (old: groups)
          $interests = $api->get("/lists/$list_id/interest-categories/$category->id/interests", ['fields'=>'interests.id,interests.name','count'=>10000])->data->interests;
          foreach ($interests as $interest) {
            // Can we find this interest by name?
            $matched_old_group = FALSE;
            foreach($matched_old_grouping['groups'] as $old_group) {
              if ($old_group['name'] == $interest->name) {
                $int_queries []= ['list_id' => $list_id, 'old' => $old_group['id'], 'new' => $interest->id];
                break;
              }
            }
          }
        }
      }
    }

    foreach ($cat_queries as $params) {
      CRM_Core_DAO::executeQuery('UPDATE civicrm_value_mailchimp_settings '
        . 'SET mc_grouping_id = %1 '
        . 'WHERE mc_list_id = %2 AND mc_grouping_id = %3;'
        , [
          1 => [$params['new'], 'String'],
          2 => [$params['list_id'], 'String'],
          3 => [$params['old'], 'String'],
        ]);
    }

    foreach ($int_queries as $params) {
      CRM_Core_DAO::executeQuery('UPDATE civicrm_value_mailchimp_settings '
        . 'SET mc_group_id = %1 '
        . 'WHERE mc_list_id = %2 AND mc_group_id = %3;'
        , [
          1 => [$params['new'], 'String'],
          2 => [$params['list_id'], 'String'],
          3 => [$params['old'], 'String'],
        ]);
    }

    // Now cron jobs. Delete all mailchimp ones.
    $result = civicrm_api3('Job', 'get', array(
      'sequential' => 1,
      'api_entity' => "mailchimp",
    ));
    if ($result['count']) {
      // Should only be one, but just in case...
      foreach ($result['values'] as $old) {
        // Double check id exists!
        if (!empty($old['id'])) {
          civicrm_api3('Job', 'delete', ['id' => $old['id']]);
        }
      }
    }

    // Create Push Sync job.
    $params = array(
      'sequential' => 1,
      'name'          => 'Mailchimp Push Sync',
      'description'   => 'Sync contacts between CiviCRM and MailChimp, assuming CiviCRM to be correct. Please understand the implications before using this.',
      'run_frequency' => 'Daily',
      'api_entity'    => 'Mailchimp',
      'api_action'    => 'pushsync',
      'is_active'     => 0,
    );
    $result = civicrm_api3('job', 'create', $params);
    // Create Pull Sync job.
    $params = array(
      'sequential' => 1,
      'name'          => 'Mailchimp Pull Sync',
      'description'   => 'Sync contacts between CiviCRM and MailChimp, assuming Mailchimp to be correct. Please understand the implications before using this.',
      'run_frequency' => 'Daily',
      'api_entity'    => 'Mailchimp',
      'api_action'    => 'pullsync',
      'is_active'     => 0,
    );
    $result = civicrm_api3('job', 'create', $params);

    return TRUE;
  }
  /**
   * Example: Run an external SQL script
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4201() {
    $this->ctx->log->info('Applying update 4201');
    // this path is relative to the extension base dir
    $this->executeSqlFile('sql/upgrade_4201.sql');
    return TRUE;
  } // */


  /**
   * Example: Run a slow upgrade process by breaking it up into smaller chunk
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4202() {
    $this->ctx->log->info('Planning update 4202'); // PEAR Log interface

    $this->addTask(ts('Process first step'), 'processPart1', $arg1, $arg2);
    $this->addTask(ts('Process second step'), 'processPart2', $arg3, $arg4);
    $this->addTask(ts('Process second step'), 'processPart3', $arg5);
    return TRUE;
  }
  public function processPart1($arg1, $arg2) { sleep(10); return TRUE; }
  public function processPart2($arg3, $arg4) { sleep(10); return TRUE; }
  public function processPart3($arg5) { sleep(10); return TRUE; }
  // */


  /**
   * Example: Run an upgrade with a query that touches many (potentially
   * millions) of records by breaking it up into smaller chunks.
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4203() {
    $this->ctx->log->info('Planning update 4203'); // PEAR Log interface

    $minId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(min(id),0) FROM civicrm_contribution');
    $maxId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(max(id),0) FROM civicrm_contribution');
    for ($startId = $minId; $startId <= $maxId; $startId += self::BATCH_SIZE) {
      $endId = $startId + self::BATCH_SIZE - 1;
      $title = ts('Upgrade Batch (%1 => %2)', array(
        1 => $startId,
        2 => $endId,
      ));
      $sql = '
        UPDATE civicrm_contribution SET foobar = whiz(wonky()+wanker)
        WHERE id BETWEEN %1 and %2
      ';
      $params = array(
        1 => array($startId, 'Integer'),
        2 => array($endId, 'Integer'),
      );
      $this->addTask($title, 'executeSql', $sql, $params);
    }
    return TRUE;
  } // */

}
