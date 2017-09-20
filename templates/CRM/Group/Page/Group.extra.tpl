<script>
var mailchimp_groups = {$mailchimp_groups};

{literal}
function mailchimpGroupsPageAlter() {

  // Add header only once
  if (cj('table.crm-group-selector thead th.crm-mailchimp').length < 1) {
    cj('table.crm-group-selector thead th.crm-group-visibility').after(
       '<th class="crm-mailchimp">Mailchimp Sync</th>');
  }
  
  var rows = cj('table.crm-group-selector tbody tr');
  rows.each(function() {
    var row = cj(this);
    var group_id_index = 'id' + row.data('id');
    var mailchimp_td = cj('<td class="crm-mailchimp" />');
    if (mailchimp_groups[group_id_index]) {
      mailchimp_td.text(mailchimp_groups[group_id_index]);
    }
    row.find('td.crm-group-visibility').after(mailchimp_td);
  });
}
{/literal}
{if $action eq 16}
{* action 16 is VIEW, i.e. the Manage Groups page.*}
{literal}
  cj('table.crm-group-selector').on( 'draw.dt', function () {
    mailchimpGroupsPageAlter();
  });
{/literal}
{/if}
</script>

{if $action eq 2}
    {* action 16 is EDIT a group *}
    {include file="CRM/Group/MailchimpSettings.tpl"}
{/if}
