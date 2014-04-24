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
    cj("input[data-crm-custom='Mailchimp_Settings:Mailchimp_List_ID']").parent().parent().after(mailchimp_settings);

    cj("input[data-crm-custom='Mailchimp_Settings:Mailchimp_List_ID']").parent().parent().hide();
    cj("input[data-crm-custom='Mailchimp_Settings:Mailchimp_Group']").parent().parent().hide();

    cj("#mailchimp_list").change(function() {
        var list_id = cj("#mailchimp_list :selected").val();
        populateGroups(list_id);
    });

    cj("#mailchimp_group").change(function() {
        cj("input[data-crm-custom='Mailchimp_Settings:Mailchimp_Group']").val(cj("#mailchimp_group :selected").val());
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
        cj("input[data-crm-custom='Mailchimp_Settings:Mailchimp_List_ID']").val(list_id);
        cj('#mailchimp_group').find('option').remove().end().append('<option value="0">- select -</option>');
        CRM.api('Mailchimp', 'getgroups', {'id': list_id},
        {success: function(data) {
            if (data.values) {
                cj.each(data.values, function(key, value) {
                    if (key == mailing_group_id) {
                        cj('#mailchimp_group').append(cj("<option selected='selected'></option>").attr("value",key).text(value)); 
                    } else {
                        cj('#mailchimp_group').append(cj("<option></option>").attr("value",key).text(value)); 
                    }
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
