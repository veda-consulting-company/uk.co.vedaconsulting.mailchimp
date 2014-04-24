<div class="crm-block crm-form-block crm-campaignmonitor-sync-form-block">

  {if $smarty.get.state eq 'done'}
    <div class="help">
      {ts}Sync completed with result counts as:{/ts}<br/> 
      <table class="form-layout-compressed bold">
      <tr><td>{ts}Added{/ts}:</td><td>{$stats.Added}</td></tr>
      <tr><td>{ts}Updated{/ts}:</td><td>{$stats.Updated}</td></tr>
      <tr><td>{ts}Sync Error{/ts}:</td><td>{$stats.Error}</td></tr>
      <tr><td>{ts}Civi Blocked{/ts}:</td><td>{$stats.Blocked}&nbsp; (no-email / opted-out / do-not-email / on-hold)</td></tr>
      <tr colspan=2><td>{ts}Total{/ts}:</td><td>{$stats.Total}</td></tr>
      </table>
    </div>
  {/if}
  
  <div class="crm-block crm-form-block crm-campaignmonitor-sync-form-block">
    <div class="crm-submit-buttons">
      {include file="CRM/common/formButtons.tpl"}
    </div>
  </div>
  
</div>
