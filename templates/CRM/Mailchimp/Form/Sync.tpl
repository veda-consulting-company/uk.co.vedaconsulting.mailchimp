<div class="crm-block crm-form-block crm-campaignmonitor-sync-form-block">

  {if $smarty.get.action eq 'finish'}
    <div class="help">
      {ts}Sync has completed successfully.{/ts} 
    </div>
  {/if}
  
  <div class="crm-block crm-form-block crm-campaignmonitor-sync-form-block">
    <div class="crm-submit-buttons">
      {include file="CRM/common/formButtons.tpl"}
    </div>
  </div>
  
</div>
