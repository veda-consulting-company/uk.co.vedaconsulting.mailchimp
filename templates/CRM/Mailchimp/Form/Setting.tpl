<div class="crm-block crm-form-block crm-mailchimp-setting-form-block">
  <div class="crm-accordion-wrapper crm-accordion_mailchimp_setting-accordion crm-accordion-open">
    <div class="crm-accordion-header">
      <div class="icon crm-accordion-pointer"></div> 
      {ts}API Key Setting{/ts}
    </div><!-- /.crm-accordion-header -->
    <div class="crm-accordion-body">

      <table class="form-layout-compressed">
    	  <tr class="crm-mailchimp-setting-api-key-block">
          <td class="label">{$form.api_key.label}</td>
          <td>{$form.api_key.html}<br/>
      	    <span class="description">{ts}API Key from Mail Chimp{/ts}
	          </span>
          </td>
        </tr>
          <tr class="crm-mailchimp-setting-security-key-block">
          <td class="label">{$form.security_key.label}</td>
          <td>{$form.security_key.html}<br/>
      	    <span class="description">{ts} Define a security key to be used with webhooks{/ts}
	          </span><br/>
            <span class="description" id ="webhook_url">{$webhook_url}
                  </span>
          </td>
        </tr> 
         <tr class="crm-mailchimp-setting-default-group-block">
          <td class ="label" >{$form.default_group.label}</td>
          <td>{$form.default_group.html}<br/>
      	    <span class="description">{ts}Set a default group where contacts are sync'd to, when a mailchimp group is not mapped to any civicrm group{/ts}
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
    var URL = "{/literal}{$webhook_url}{literal}" + '&key=';
    cj("#security_key").on('keyup', function() {
      var focusedValue = cj(this).val();
      cj('#webhook_url').text(URL + focusedValue);
    });
 });

</script>
{/literal}
{*end*}