<script>
var mailchimp_groups = {$mailchimp_groups};

{literal}
function mailchimpGroupsPageAlter() {
  var rows = cj('table.crm-group-selector tbody tr');
  if (rows.length == 0) {
    // Not loaded yet, try in half a second.
    window.setTimeout(mailchimpGroupsPageAlter, 500);
    return;
  }
  // Add header.
  cj('table.crm-group-selector thead th.crm-group-name').after(
    '<th class="crm-mailchimp">Mailchimp Sync</th>');
  rows.each(function() {
    var row = cj(this);
    var group_id_index = 'id' + row.data('id');
    var mailchimp_td = cj('<td class="crm-mailchimp" />');
    if (mailchimp_groups[group_id_index]) {
      mailchimp_td.text(mailchimp_groups[group_id_index]);
    }
    row.find('td.crm-group-name').after(mailchimp_td);
  });
}
{/literal}
{if $action eq 16}
{* action 16 is VIEW, i.e. the Manage Groups page.*}
CRM.$(mailchimpGroupsPageAlter);
{/if}
</script>

{if $action eq 2}
    {* action 16 is EDIT a group *}
    {include file="CRM/Group/MailchimpSettings.tpl"}
{/if}
