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
  // FIX ME - adding mailchimp sync as second column causes issue when the datatable is updated using AJAX by newer version CiviCRM, hence appending mailchimp sync as last column
  if(cj("table.crm-group-selector thead th.crm-mailchimp").length === 0) {
    cj('table.crm-group-selector thead tr').append('<th class="crm-mailchimp">Mailchimp Sync</th>');
  }
  rows.each(function() {
    var row = cj(this);
    var group_id_index = 'id' + row.data('id');
    var mailchimp_td = cj('<td class="crm-mailchimp" />');
    if (mailchimp_groups[group_id_index]) {
      mailchimp_td.text(mailchimp_groups[group_id_index]);
    }
    // FIX ME - adding mailchimp sync as second column causes issue when the datatable is updated using AJAX by newer version CiviCRM, hence appending mailchimp sync as last column
    row.append(mailchimp_td);
  });
}
{/literal}
{if $action eq 16}
{* action 16 is VIEW, i.e. the Manage Groups page.*}
{* GK03082017 Group search func uses Ajax to update the datatable, hence triggerring mailchimpGroupsPageAlter func everytime the table redraws to ensure mailchimp related columns are added*}
  {literal}
    var dataTable = cj('table.crm-group-selector');
    dataTable.on( 'draw.dt', function () {
      CRM.$(mailchimpGroupsPageAlter);
    });
  {/literal}
{/if}
</script>

{if $action eq 2}
    {* action 16 is EDIT a group *}
    {include file="CRM/Group/MailchimpSettings.tpl"}
{/if}
