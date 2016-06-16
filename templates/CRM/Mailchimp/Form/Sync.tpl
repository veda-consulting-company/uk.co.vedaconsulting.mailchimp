<div class="crm-block crm-form-block crm-campaignmonitor-sync-form-block">
  {if $smarty.get.state eq 'done'}
    <div class="help">
      {if $dry_run}
        {ts}<strong>Dry Run: no contacts/members actually changed.</strong>{/ts}
      {/if}
      {ts}Sync completed with result counts as:{/ts}<br/> 
      {foreach from=$stats item=group}
      <h2>{$group.name}</h2>
      <table class="form-layout-compressed">
      <tr><td>{ts}Contacts on CiviCRM{/ts}:</td><td>{$group.stats.c_count}</td></tr>
      <tr><td>{ts}Contacts on Mailchimp (originally){/ts}:</td><td>{$group.stats.mc_count}</td></tr>
      <tr><td>{ts}Contacts that were in sync already{/ts}:</td><td>{$group.stats.in_sync}</td></tr>
      <tr><td>{ts}Contacts updated at Mailchimp{/ts}:</td><td>{$group.stats.updates}</td></tr>
      <tr><td>{ts}Contacts Subscribed{/ts}:</td><td>{$group.stats.additions}</td></tr>
      <tr><td>{ts}Contacts Unsubscribed from Mailchimp{/ts}:</td><td>{$group.stats.unsubscribes}</td></tr>
      </table>
      {/foreach}
    </div>
    {if $error_messages}
    <h2>Error messages</h2>
    <p>These errors have come from the last sync operation (whether that was a 'pull' or a 'push').</p>
    <table>
    <thead><tr><th>Group Id</th><th>Name and Email</th><th>Error</th></tr>
    </thead>
    <tbody>
    {foreach from=$error_messages item=msg}
      <tr><td>{$msg.group}</td>
      <td>{$msg.name} {$msg.email}</td>
      <td>{$msg.message}</td>
    </tr>
    {/foreach}
    </table>
    {/if}
  {else}
    <h3>{ts}Push contacts from CiviCRM to Mailchimp{/ts}</h3>
    <p>{ts}Running this will assume that the information in CiviCRM about who is
    supposed to be subscribed to the Mailchimp list is correct.{/ts}</p>
    <p>{ts}Points to know:{/ts}</p>
    <ul>
      <li>{ts}If a contact is not in the membership group at CiviCRM, they will be unsubscribed from Mailchimp (assuming they are currently subscribed at Mailchimp).{/ts}</li>
      <li>{ts}If a contact is in the membership group, they will be subscribed at Mailchimp. <strong>This could cost you money if adding subscribers exceeds your current tariff.</strong>
      Check the numbers of contacts in each group and/or do a Dry Run first.{/ts}</li>
      <li>{ts}Any and all CiviCRM groups set up to sync to Mailchimp Interests will be consulted and changes made to members' interests at Mailchimp, as needed.{/ts}</li>
      <li>{ts}If somone's name is different, the Mailchimp name is replaced by the CiviCRM name (unless there is a name at Mailchimp but no name at CiviCRM).{/ts}</li>
      <li>{ts}This is a "push" <em>to</em> Mailchimp operation. You may want the "pull" <em>from</em> Mailchimp instead.{/ts}</li>
    </ul>
    {$summary}
    {$form.mc_dry_run.html} {$form.mc_dry_run.label}
    <div class="crm-submit-buttons">
      {include file="CRM/common/formButtons.tpl"}
    </div>
  {/if}
</div>
