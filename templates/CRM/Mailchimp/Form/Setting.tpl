<div class="crm-block crm-form-block crm-mailchimp-setting-form-block">
  <div class="crm-accordion-wrapper crm-accordion_mailchimp_setting-accordion crm-accordion-open">
    <div class="crm-accordion-header">
      <div class="icon crm-accordion-pointer"></div> 
      {ts}API Key Setting{/ts}
    </div><!-- /.crm-accordion-header -->
    <div class="crm-accordion-body">

      <table class="form-layout-compressed">
    	  <tr class="crm-mailchimp-setting-api-key-block">
          <td class="label">{$form.mailchimp_api_key.label}</td>
          <td>{$form.mailchimp_api_key.html}<br/>
      	    <span class="description">{ts}API Key from Mail Chimp{/ts}
	          </span>
          </td>
        </tr>
          <tr class="crm-mailchimp-setting-security-key-block">
          <td class="label">{$form.mailchimp_security_key.label}</td>
          <td>{$form.mailchimp_security_key.html}
            &nbsp;&nbsp;
            <input class="crm-button" type="button" name="generate_webhook_key" id="generate_webhook_key" onclick="generateWebhookKey();" value="{ts}Generate Key{/ts}" />
            <br/>
            <span class="description">{ts}Define a security key to be used with
            webhooks. e.g. a 12+ character random string of upper- and
            lower-case letters and numbers. Note if you change this once lists
            are set up you'll need to update all the groups that serve as
            memberships for Mailchimp lists.{/ts}
	          </span><br/>
            <span class="description" id ="webhook_url">{ts}{$webhook_url}{/ts}
                  </span>
          </td>
        </tr>
        <tr class="crm-mailchimp-setting-enabledebugging-block">
          <td class="label">{$form.mailchimp_enable_debugging.label}</td>
          <td>{$form.mailchimp_enable_debugging.html}<br/>
          </td>
        </tr>
        <tr class="crm-mailchimp-setting-sync-checksum-block">
          <td class="label">{$form.mailchimp_sync_checksum.label}</td>
          <td>{$form.mailchimp_sync_checksum.html}<br/>
          </td>
        </tr>
        {if $form.mailchimp_sync_profile}
        <tr class="crm-mailchimp-setting-sync-profile-block">
          <td class="label">{$form.mailchimp_sync_profile.label}</td>
          <td>{$form.mailchimp_sync_profile.html}<br/>
          <span class="description">Optionally select a profile to include in contact push syncronization.
          These may be useful for segmentation, etc. on mailchimp. <br />Synchronizing custom fields will slow
          down the process so only do so if necessary and use a profile with only the fields you need.
          Name, email and address fields will be synched by default so should not be included in the profile.
          </span>
          </td>
        </tr>
        <tr class="crm-mailchimp-setting-create-merge-fields-block">
          <td class="label">{$form.mailchimp_create_merge_fields.label}</td>
          <td>{$form.mailchimp_create_merge_fields.html}<br/>
          <span class="description">Select yes here to create merge fields on lists when this form is submitted.
          By default the fields will be non-public text fields.
          </span>
          </td>
        </tr>
        {/if}
        <tr class="crm-mailchimp-setting-synctags-block">
          <td class="label">{$form.mailchimp_sync_tags.label}</td>
          <td>
            {$form.mailchimp_sync_tags.html}<br/>
            <span class="description">
              {ts}Select 'Yes' here to sync CiviCRM contact tags with Mailchimp member tags during <strong>'Sync Civi Contacts to Mailchimp'</strong> operation.<br/>
              If a tag doesn't exist in mailchimp, new tag will be created in mailchimp and the member record will be updated with the new tag.<br/>
              Existing tag will be removed from mailchimp member record, if the CiviCRM contact doesn't have it.{/ts}
            </span>
          </td>
        </tr>
      </table>
    </div>
    <div class="crm-submit-buttons">
      {include file="CRM/common/formButtons.tpl"}
    </div>
  </div>
</div>
    
{*script*}
{literal}

<script type="text/javascript">
 cj(document).ready(function(){
 // xxx
    var URL = "{/literal}{$webhook_url}{literal}" + '&key=';
    var content = cj('#mailchimp_security_key').val();
    cj('#webhook_url').text(URL + content);
    cj("#mailchimp_security_key").on('keyup', function() {
      var focusedValue = cj(this).val();
      cj('#webhook_url').text(URL + focusedValue);
    });
    
    cj("#mailchimp_security_key").blur(function(){
        var content = cj('#mailchimp_security_key').val();
        cj('#webhook_url').text(URL + content);
    });
 });
function generateWebhookKey() {
  var sourceUrl = CRM.url('civicrm/mailchimp/generate/webhookkey');
  var URL = "{/literal}{$webhook_url}{literal}" + '&key=';
  cj.ajax({
    url: sourceUrl,
    type: 'post',
    data: {ajaxurl : 1 },
    success: function (response) {
      cj('#mailchimp_security_key').val(response);
      cj('#webhook_url').text(URL + response);
    }
  });
} 
</script>
{/literal}
{*end*}
