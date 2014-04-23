<div class="crm-block crm-form-block crm-campaignmonitor-sync-form-block">

  {if $smarty.get.state eq 'done'}
    <div class="help">
      {ts}Sync completed with result counts as:{/ts}<br/> 
      <table class="form-layout-compressed bold">
      <tr><td>{ts}Added:{/ts}</td><td>{$stats.Added}</td></tr>
      <tr><td>{ts}Updated:{/ts}</td><td>{$stats.Updated}</td></tr>
      <tr><td>{ts}Error:{/ts}</td><td>{$stats.Error}</td></tr>
      </table>
    </div>
  {/if}
  
  <div class="crm-block crm-form-block crm-campaignmonitor-sync-form-block">
    <div class="crm-submit-buttons">
      {include file="CRM/common/formButtons.tpl"}
    </div>
  </div>
  
</div>
