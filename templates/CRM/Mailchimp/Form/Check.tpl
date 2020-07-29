  {if $groupData}
      {foreach from=$groupData item=group}
     <div class="crm-block crm-form-block crm-campaignmonitor-sync-form-block">
      <h2><strong>{$group.civigroup_title}</strong></h2>
       <div><p>
        {if $group.list_name}
        {ts} Linked to list: {/ts} <em>{$group.list_name}</em> 
        {else}
        {ts} Linked to a list not available on Mailchimp ({$group.list_id}){/ts}
        {/if}
        </p>
        <p>
         <a href="{$group.url}" class="crm-popup">Edit Group</a>
        </p>
       </div>
      <table class="form-layout-compressed">
        <tr><td><strong>{ts}Total Members{/ts}</strong></td><td>{$group.stats.total_members}</td><td></td></tr>
        <tr><td><strong>{ts}On Hold{/ts}</strong></td><td>{$group.stats.on_hold}</td></td></td></tr>
        <tr><td><strong>{ts}Do not email{/ts}</strong></td><td>{$group.stats.do_not_email}</td><td></td></tr>
        <tr><td><strong>{ts}Is Deceased{/ts}</strong></td><td>{$group.stats.is_deceased}</td><td></td></tr>
        <tr><td><strong>{ts}No valid email{/ts}</strong></td><td>{$group.stats.no_valid_email}</td><td></td></tr>
        <tr><td><strong>{ts}Duplicated email{/ts}</strong></td><td>{$group.stats.duplicated_emails}</td><td></td></tr>
        <tr><td><strong>{ts}Total held back{/ts}</strong></td><td>{$group.stats.total_invalid}</td><td></td></tr>
        {if $group.mailchimp.list_exists}        
         
        <tr><td colspan="3"><em>{ts}Mailchimp list: {/ts} {$group.list_name}</em></td></tr>
        <tr><td><strong>{ts}Member count{/ts}</strong></td><td>{$group.mailchimp.stats.member_count}</td><td></td></tr>
        <tr><td><strong>{ts}Unsubscribed{/ts}</strong></td><td>{$group.mailchimp.stats.unsubscribe_count}</td><td></td></tr>
        <tr><td><strong>{ts}Cleaned{/ts}</strong></td><td>{$group.mailchimp.stats.cleaned_count}</td><td></td></tr>
        <tr><td><strong>{ts}Last subsription{/ts}</strong></td><td>{$group.mailchimp.stats.last_sub_date}</td><td></td></tr>
        {else}
         
        <tr><td colspan="3"><strong>{ts}Mailchimp List {$group.list_id} not available{/ts}</td></tr>
        {/if}
      </table>
      
     {if $group.sub_groups}
       {foreach from=$group.sub_groups key=k item=subGroup}
        <h3>{$subGroup.civigroup_title}</h3>
        <div>
        <p>
        Linked to interest group: <em>{$subGroup.interest_name}</em> 
        </p>
        <p>
         <a href="{$subGroup.url}" class="crm-popup">Edit Group</a>
        </p>
        </div>
        <table class="form-layout-compressed">
         <tr><td><strong>{ts}Total members{/ts}</strong></td><td>{$subGroup.stats.total_members}</td></tr>
         <tr><td><strong>{ts}Members also in <em>{$group.civigroup_title}</em>{/ts}</strong></td><td>{$subGroup.stats.members_in_main_group}</td></tr>
         <tr><td><strong>{ts}Valid members{/ts}</strong></td><td>{$subGroup.stats.valid_members_in_main_group}</td></tr>
         <tr><td><strong>{ts}Subscribed to {/ts}</strong><em>{$subGroup.interest_name}</em></td><td>{$group.mailchimp.sub_groups.$k.stats.subscriber_count}</td></tr>
        </table>
       {/foreach}
     {/if} {* sub_groups *} 
     </div>
      {/foreach}
    </div>
   {else} {* groupData *}
  <div class="crm-block crm-form-block crm-campaignmonitor-sync-form-block">
    <h3>{ts}Check Mailchimp {/ts}</h3>
    <div class="crm-submit-buttons">
      {include file="CRM/common/formButtons.tpl"}
    </div>
  </div>
  {/if}
