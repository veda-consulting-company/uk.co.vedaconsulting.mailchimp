<div class="crm-block crm-form-block crm-campaignmonitor-sync-form-block">

  {if $smarty.get.state eq 'done'}
    <div class="help">
      {if $dry_run}
        {ts}<strong>Dry Run: no contacts/members actually changed.</strong>{/ts}
      {/if}
      {ts}Import completed with result counts as:{/ts}<br/>
      {foreach from=$stats item=group}
      <h2>{$group.name}</h2>
      <table class="form-layout-compressed">
      <tr><td>{ts}Contacts on CiviCRM and in membership group (originally){/ts}:</td><td>{$group.stats.c_count}</td></tr>
      <tr><td>&nbsp;&nbsp;&nbsp;{ts}Of these, kept because subscribed at Mailchimp:{/ts}:</td><td>{$group.stats.in_sync}</td></tr>
      <tr><td>&nbsp;&nbsp;&nbsp;{ts}Of these, removed because not subscribed at Mailchimp:{/ts}:</td><td>{$group.stats.removed}</td></tr>
      <tr><td>{ts}Contacts on Mailchimp{/ts}:</td><td>{$group.stats.mc_count}</td></tr>
      <tr><td>&nbsp;&nbsp;&nbsp;{ts}Of these, already in membership group{/ts}:</td><td>{$group.stats.in_sync}</td></tr>
      <tr><td>&nbsp;&nbsp;&nbsp;{ts}Of these, existing contacts added to membership group{/ts}:</td><td>{$group.stats.joined}</td></tr>
      <tr><td>&nbsp;&nbsp;&nbsp;{ts}Of these, new contacts created{/ts}:</td><td>{$group.stats.created}</td></tr>
      <tr><td>{ts}Existing contacts updated{/ts}:</td><td>{$group.stats.updated}</td></tr>
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
    <p>{ts}Running this will assume that the information in Mailchimp about who is
    supposed to be a in the CiviCRM membership group is correct.{/ts}</p>
    <p>{ts}Points to know:{/ts}</p>
    <ul>
      <li>{ts}If a contact is not subscribed at Mailchimp, they will be removed from the CiviCRM membership group (if they were in it).{/ts}</li>
      <li>{ts}If a contact is subscribed at Mailchimp, they will be added to the CiviCRM membership group (if they were in it). If the contact cannot be found in CiviCRM, a new contact will be created. {/ts}</li>
      <li>{ts}Any and all CiviCRM groups set up to sync to Mailchimp Interests and configured to allow updates from Mailchimp will be consulted and changes made as needed, adding/removing contacts from these CiviCRM groups.{/ts}</li>
      <li>{ts}If somone's name is different, the CiviCRM name is replaced by the Mailchimp name (unless there is a name at CiviCRM but no name at Mailchimp).{/ts}</li>
      <li>{ts}This is a "pull" <em>from</em> Mailchimp operation. You may want the "push" <em>to</em> Mailchimp instead.{/ts}</li>
    </ul>
    {$summary}
    {$form.mc_dry_run.html} {$form.mc_dry_run.label}
    <div class="crm-submit-buttons">
      {include file="CRM/common/formButtons.tpl"}
    </div>
  {/if}
</div>
