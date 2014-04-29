
{if $action eq 16}
    {literal}
    <script>
    var lists_and_groups = {/literal}{$lists_and_groups}{literal};
    
    cj(document).ajaxComplete(function(event, xhr, settings){
        if (settings.url.indexOf("civicrm/ajax/grouplist") >= 0) {
            var groups = [];
            cj('#crm-group-selector > tbody  > tr').each(function() {
                var group_id = cj( this ).find( ".crm-group-group_id" ).html();
                groups.push( group_id );
            });
            var groupIds = groups.join(',');
            getCiviCRMGroupMailchimpSettings(groupIds , this);
        }
    });
    
    function getCiviCRMGroupMailchimpSettings(groupIds , element) {
        CRM.api('Mailchimp', 'getcivicrmgroupmailchimpsettings', {'sequential': 1 , 'ids': groupIds}, 
            {success: function(data) {
                var values = data.values;    
                cj('#crm-group-selector > thead  > tr').find('th').eq(5).after('<th class="crm-group-mailchimp_list_id ui-state-default">Mailchimp List</th><th class="crm-group-mailchimp_group_id ui-state-default">Mailchimp Group</th>');
                cj('#crm-group-selector > tbody  > tr').each(function() {
                    var gid = cj( this ).find( ".crm-group-group_id" ).html();
                    if (gid in values) {
                        var list_id = values[gid]['list_id'];
                        var grouping_id = values[gid]['grouping_id'];
                        var group_id = values[gid]['group_id'];
                    }
                    var list_name = '';
                    var group_name = '';
                    if (list_id) {
                        list_name = lists_and_groups[list_id]['name'];
                    }
                    if (list_id && grouping_id && group_id) {
                        group_name = lists_and_groups[list_id]['grouping'][grouping_id]['groups'][group_id];
                    }
                    cj(this).find('td').eq(5).after('<td>' + list_name + '</td><td>' + group_name + '</td>');
                });        
              }
            }
        );
    }
    </script>
    {/literal}
{/if}

{if $action eq 2}
    {include file="CRM/Group/MailchimpSettings.tpl"}
{/if}