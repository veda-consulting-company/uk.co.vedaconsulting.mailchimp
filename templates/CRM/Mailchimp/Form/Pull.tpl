<div class="crm-block crm-form-block crm-campaignmonitor-sync-form-block">

  {if $smarty.get.state eq 'done'}
    <div class="help">
      {ts}Import completed with result counts as:{/ts}<br/> 
      <table class="form-layout-compressed bold">
      <tr><td>{ts}Contacts Added{/ts}:</td><td>{$stats.Added}</td></tr>
      <tr><td>{ts}Contacts Updated{/ts}:</td><td>{$stats.Updated}</td></tr>
      <tr><td>{ts}Contacts Ignored{/ts}:</td><td>{$stats.Ignored}&nbsp; (Multiple Contacts Matches)</td></tr>
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