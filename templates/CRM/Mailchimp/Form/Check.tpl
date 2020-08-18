  {if $groupData}
      {foreach from=$groupData item=group}
     <div class="crm-block crm-form-block mailchimp-group-stats">
      <div class="group-container">
        <h2><strong>{$group.civigroup_title}</strong></h2>
         <div><p>
          {if $group.list_name}
          {ts} Linked to list: {/ts} <em>{$group.list_name}</em> 
          {else}
          {ts} Linked to a list not available on Mailchimp ({$group.list_id}){/ts}
          {/if}
          </p>
          <p>
           <a href="{$group.url}" class="crm-popup group-edit">Edit Group</a>
          </p>
         </div>
        <table class="form-layout-compressed group">
          <tr><td><strong>{ts}Total Members{/ts}</strong></td><td>{$group.stats.total_members}</td><td></td></tr>
          <tr><td><strong>{ts}On Hold{/ts}</strong></td><td>{$group.stats.on_hold}</td></td></td></tr>
          <tr><td><strong>{ts}No Bulk Email/Opt-Out{/ts}</strong></td><td>{$group.stats.is_opt_out}</td></td></td></tr>
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
           
          <tr><td colspan="3" class="highlight"><strong>{ts}Mailchimp List {$group.list_id} not available{/ts}</td></tr>
          {/if}
        </table>
      </div>{* .container*}
      
     {if $group.sub_groups}
       {foreach from=$group.sub_groups key=k item=subGroup}
      <div class="subgroup-container">
        <h3 class="subgroup-title">{$subGroup.civigroup_title}</h3>
        <div>
        <p>
        Linked to interest group: <em>{$subGroup.interest_name}</em> 
        </p>
        <p>
         <a href="{$subGroup.url}" class="crm-popup group-edit">Edit Group</a>
        </p>
        </div>
        <table class="form-layout-compressed">
         <tr><td><strong>{ts}Total members{/ts}</strong></td><td>{$subGroup.stats.total_members}</td></tr>
         <tr><td><strong>{ts}Members also in <em>{$group.civigroup_title}</em>{/ts}</strong></td><td>{$subGroup.stats.members_in_main_group}</td></tr>
         <tr><td><strong>{ts}Valid members{/ts}</strong></td><td>{$subGroup.stats.valid_members_in_main_group}</td></tr>
        <tr><td><strong>{ts}No Bulk Email/Opt-Out{/ts}</strong></td><td>{$subGroup.stats.is_opt_out}</td><td></td></tr>
        <tr><td><strong>{ts}Do not email{/ts}</strong></td><td>{$subGroup.stats.do_not_email}</td><td></td></tr>
        <tr><td><strong>{ts}Is Deceased{/ts}</strong></td><td>{$subGroup.stats.is_deceased}</td><td></td></tr>
        <tr><td><strong>{ts}No valid email{/ts}</strong></td><td>{$subGroup.stats.invalid}</td><td></td></tr>
        <tr><td><strong>{ts}Duplicated email{/ts}</strong></td><td>{$subGroup.stats.is_duplicate}</td><td></td></tr>
         <tr><td><strong>{ts}Subscribed to {/ts}</strong><em>{$subGroup.interest_name}</em></td><td>{$group.mailchimp.sub_groups.$k.stats.subscriber_count}</td></tr>
        {if $subGroup.stats.total_members > $subGroup.stats.members_in_main_group}
        <tr><td colspan="2">
        <div class="help highlight">
        <p>This group has members that are not in <em>{$group.civigroup_title}</em> so will not be pushed to Mailchimp.
         </p><p>You can edit the group settings and set {$group.civigroup_title} as its parent to ensure all its members are included.  
        </p>
        </div>
        </td></tr>
        {/if}
        </table>
      </div>
       {/foreach}
     {/if} {* sub_groups *} 
     </div>
      {/foreach}
    </div>
   {else} {* groupData *}
  <div class="crm-block crm-form-block mailchimp-group-stats">
  <div class="help">
   <p>Click the button below to generate a report on Mailchimp-related groups.</p>
   <p>This will not affect the existing set-up. </p>    
    </div>
    <div class="crm-submit-buttons">
      {include file="CRM/common/formButtons.tpl"}
    </div>
  </div>
  {/if}
