<div class="crm-block crm-form-block crm-campaignmonitor-sync-form-block">

  {if $smarty.get.state eq 'done'}
    <div class="help">
      {ts}Sync completed with result counts as:{/ts}<br/> 
      <!--<tr><td>{ts}Civi Blocked{/ts}:</td><td>{$stats.Blocked}&nbsp; (no-email / opted-out / do-not-email / on-hold)</td></tr>-->
      {foreach from=$stats item=group}
      <h2>{$group.name}</h2>
      <table class="form-layout-compressed bold">
      <tr><td>{ts}Contacts on CiviCRM{/ts}:</td><td>{$group.stats.c_count}</td></tr>
      <tr><td>{ts}Contacts on Mailchimp (originally){/ts}:</td><td>{$group.stats.mc_count}</td></tr>
      <tr><td>{ts}Contacts that were in sync already{/ts}:</td><td>{$group.stats.in_sync}</td></tr>
      <tr><td>{ts}Contacts Subscribed or updated at Mailchimp{/ts}:</td><td>{$group.stats.added}</td></tr>
      <tr><td>{ts}Contacts Unsubscribed from Mailchimp{/ts}:</td><td>{$group.stats.removed}</td></tr>
      </table>
      {/foreach}
    </div>
  {/if}
  
  <div class="crm-block crm-form-block crm-campaignmonitor-sync-form-block">
    <div class="crm-submit-buttons">
      {include file="CRM/common/formButtons.tpl"}
    </div>
  </div>
  
</div>
