<table id="mailchimp_settings" style="display:none;">
<tr class="custom_field-row" id="mc_integration_option_0">
  <td colspan=2>
    {$form.mc_integration_option.0.html}
  </td>
</tr>
<tr class="custom_field-row" id="mc_integration_option_1">
  <td colspan=2>
    {$form.mc_integration_option.1.html}
  </td>
</tr>
<tr class="custom_field-row" id="mc_integration_option_2">
  <td colspan=2>
    {$form.mc_integration_option.2.html}
  </td>
</tr>
<tr class="custom_field-row mailchimp_list" id="mailchimp_list_tr">
    <td class="label">{$form.mailchimp_list.label}</td>
    <td class="html-adjust">{$form.mailchimp_list.html}</td>
</tr>
<tr class="custom_field-row mailchimp_group" id="mailchimp_group_tr">
    <td class="label">{$form.mailchimp_group.label}</td>
    <td class="html-adjust">{$form.mailchimp_group.html}</td>
</tr>
<tr class="custom_field-row is_mc_update_grouping" id="is_mc_update_grouping_tr">
    <td class="label">{$form.is_mc_update_grouping.label}</td>
    <td class="html-adjust">{$form.is_mc_update_grouping.html}</td>
</tr>
<tr class="custom_field-row mailchimp_fixup" id="mailchimp_fixup_tr">
    <td colspan=2>{$form.mc_fixup.html}{$form.mc_fixup.label}<br />
      <span class="description">{ts}If this is ticked when you press Save,
      CiviCRM will edit the webhook settings of this list at Mailchimp to make
      sure they're configured correctly. The only time you would want to
      <em>untick</em> this box is if you are doing some development on a local
      server because that would result in supplying an invalid webhook URL to a
      possibly production list at mailchimp. So basically leave this ticked,
      unless you know what you're doing :-){/ts}</span>
    </td>
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

    cj("#mailchimp_fixup_tr").hide();
    cj("input[data-crm-custom='Mailchimp_Settings:is_mc_update_grouping']").parent().parent().hide();
    cj("#mailchimp_list_tr").hide();
    cj("#mailchimp_group_tr").hide();
    cj("#is_mc_update_grouping_tr").hide();

    // action on selection of integration radio options
    cj("input:radio[name=mc_integration_option]").change(function() {
      var intopt = cj(this).val();
      if (intopt == 1) {
        cj("#mailchimp_list_tr").insertAfter(cj("#mc_integration_option_1"));
        cj("#mailchimp_list_tr").show();
        cj("#mailchimp_group_tr").hide();
        cj("#mailchimp_fixup_tr").show();
        cj("#is_mc_update_grouping_tr").hide();
        cj("#mailchimp_group").val('').trigger('change');
        cj("input:radio[name=is_mc_update_grouping][value=0]").prop('checked', true);
      } else if (intopt == 2) {
        cj("#mailchimp_list_tr").insertAfter(cj("#mc_integration_option_2"));
        cj("#mailchimp_list_tr").show();
        cj("#mailchimp_group_tr").show();
        cj("#mailchimp_fixup_tr").hide();
        cj("#is_mc_update_grouping_tr").show();
      } else {
        cj("#mailchimp_list_tr").hide();
        cj("#mailchimp_group_tr").hide();
        cj("#mailchimp_fixup_tr").hide();
        cj("#is_mc_update_grouping_tr").hide();
        cj("input:radio[name=is_mc_update_grouping][value=0]").prop('checked', true);
        cj("#mailchimp_list").val('').trigger('change');
        cj("#mailchimp_group").val('').trigger('change');
      }
    }).filter(':checked').trigger('change');

    cj("input:radio[name=is_mc_update_grouping]").change(function() {
      var gval = cj(this).val();
      cj("input:radio[name^='custom_'][data-crm-custom^='Mailchimp_Settings:'][value='" + gval + "']").prop('checked', true);
    });

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

function populateGroups(list_id, mailing_group_id) {
    mailing_group_id = typeof mailing_group_id !== 'undefined' ?  mailing_group_id : null;
    if (list_id) {
        cj('#mailchimp_group').find('option').remove().end().append('<option value="0">- select -</option>');
        CRM.api('Mailchimp', 'getinterests', {'id': list_id},
        {success: function(data) {
            if (data.values) {
                cj.each(data.values, function(key, value) {
                    cj.each(value.interests, function(group_key, group_value) {
                        if (group_key == mailing_group_id) {
                            cj('#mailchimp_group').append(cj("<option selected='selected'></option>").attr("value", key + '|' + group_key).text(group_value)); 
                        } else {
                            cj('#mailchimp_group').append(cj("<option></option>").attr("value", key + '|' + group_key).text(group_value)); 
                        }
                    });
                });
           ['interests'] }
          }
        }
      );
    } else {
        cj('#mailchimp_group').find('option').remove().end().append('<option value="0">- select -</option>');
    }
}


</script>
{/literal}
