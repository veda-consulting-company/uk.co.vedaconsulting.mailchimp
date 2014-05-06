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
      	    <span class="description">{ts}Define a security key to be used in Mail Chimp{/ts}
	          </span>
          </td>
        </tr> 
         <tr class="crm-mailchimp-setting-default-group-block">
          <td class ="label" >{$form.default_group.label}</td>
          <td>{$form.default_group.html}<br/>
      	    <span class="description">{ts}Define a default group which is not mapped in Mail Chimp{/ts}
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
