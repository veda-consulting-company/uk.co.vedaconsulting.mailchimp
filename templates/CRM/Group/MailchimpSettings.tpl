<table id="mailchimp_settings" style="display:none;">
<tr class="custom_field-row mailchimp_list">
    <td class="label">{$form.mailchimp_list.label}</td>
    <td class="html-adjust">{$form.mailchimp_list.html}</td>
</tr>
<tr class="custom_field-row mailchimp_group">
    <td class="label">{$form.mailchimp_group.label}</td>
    <td class="html-adjust">{$form.mailchimp_group.html}</td>
</tr>
</table>

{literal}
<script>
cj( document ).ready(function() {
    var mailchimp_settings = cj('#mailchimp_settings').html();
    mailchimp_settings = mailchimp_settings.replace("<tbody>", "");
    mailchimp_settings = mailchimp_settings.replace("</tbody>", "");
    cj("input[data-crm-custom='Mailchimp_Settings:Mailchimp_List']").parent().parent().after(mailchimp_settings);

    cj("input[data-crm-custom='Mailchimp_Settings:Mailchimp_List']").parent().parent().hide();
    cj("input[data-crm-custom='Mailchimp_Settings:Mailchimp_Grouping']").parent().parent().hide();
    cj("input[data-crm-custom='Mailchimp_Settings:Mailchimp_Group']").parent().parent().hide();

    cj("#mailchimp_list").change(function() {
        var list_id = cj("#mailchimp_list :selected").val();
        if (list_id  == 0) {
            list_id = '';
        }
        cj("input[data-crm-custom='Mailchimp_Settings:Mailchimp_List']").val(list_id);
        cj("input[data-crm-custom='Mailchimp_Settings:Mailchimp_Grouping']").val('');
        cj("input[data-crm-custom='Mailchimp_Settings:Mailchimp_Group']").val('');
        populateGroups(list_id);
    });

    cj("#mailchimp_group").change(function() {
        var group_id = cj("#mailchimp_group :selected").val();
        if (group_id == 0) {
            grouping_id = '';
            group_id = '';
        } else {
            var grouping = group_id.split('|');
            grouping_id = grouping[0];
            group_id = grouping[1];
        }
        cj("input[data-crm-custom='Mailchimp_Settings:Mailchimp_Grouping']").val(grouping_id);
        cj("input[data-crm-custom='Mailchimp_Settings:Mailchimp_Group']").val(group_id);
    });

    {/literal}{if $action eq 2}{literal}
        //var mailchimp_list_id = {/literal}{$mailchimp_list_id}{literal};
        var list_id = cj("#mailchimp_list :selected").val();
        var mailing_group_id
        {/literal}{if $mailchimp_group_id}{literal}
            var mailing_group_id = '{/literal}{$mailchimp_group_id}{literal}';
        {/literal}{/if}{literal}
        populateGroups(list_id , mailing_group_id);
        //cj("#mailchimp_group").val(mailing_group_id);
    {/literal}{/if}{literal}

});

function populateGroups(list_id , mailing_group_id = null) {
    if (list_id) {
        cj('#mailchimp_group').find('option').remove().end().append('<option value="0">- select -</option>');
        CRM.api('Mailchimp', 'getgroups', {'id': list_id},
        {success: function(data) {
            if (data.values) {
                cj.each(data.values, function(key, value) {
                    cj.each(value.groups, function(group_key, group_value) {
                        if (group_key == mailing_group_id) {
                            cj('#mailchimp_group').append(cj("<option selected='selected'></option>").attr("value", key + '|' + group_key).text(group_value)); 
                        } else {
                            cj('#mailchimp_group').append(cj("<option></option>").attr("value", key + '|' + group_key).text(group_value)); 
                        }
                    });
                });
            }
          }
        }
      );
    } else {
        cj('#mailchimp_group').find('option').remove().end().append('<option value="0">- select -</option>');
    }
}


</script>
{/literal}
