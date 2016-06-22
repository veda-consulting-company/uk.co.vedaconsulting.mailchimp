{*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.7                                                |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2015                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
*}
<div class="view-content">
{if $action eq 1 or $action eq 2} {* action is add or update *}
  <div class="crm-block crm-form-block crm-note-form-block">
    <div class="content crm-submit-buttons">{include file="CRM/common/formButtons.tpl" location="top"}</div>
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
            <span class="description" id ="webhook_url">{ts}{$webhook_url}{/ts}
                  </span>
          </td>
        </tr>
	{*
        <tr class="crm-mailchimp-setting-enabledebugging-block">
          <td class="label">{$form.enable_debugging.label}</td>
          <td>{$form.enable_debugging.html}<br/>
          </td>
        </tr>*}
        <tr class="crm-mailchimp-setting-remove-from-mc-block">
          <td class="label">{$form.list_removal.label}</td>
          <td>{$form.list_removal.html}<br/>
      	    <span class="description">{ts} Delete or Unsubscribe at MailChimp when corresponding CiviCRM contact is removed?{/ts}
	          </span>
          </td>
        </tr>
      </table>

  <div class="crm-section note-buttons-section no-label">
   <div class="content crm-submit-buttons">{include file="CRM/common/formButtons.tpl" location="bottom"}</div>
   <div class="clear"></div>
  </div>
    </div>
{/if}
{if ($action eq 8)}
<div class=status>{ts}Are you sure you want to delete this account?{/ts}</div>
<div class="crm-submit-buttons">{include file="CRM/common/formButtons.tpl"}</div>

{/if}

{if $action eq 16}
   <div class="action-link">
   <a accesskey="N" href="{crmURL p='civicrm/mailchimp/view/account' q="action=add"}" class="button medium-popup"><span><i class="crm-i fa-plus-circle"></i> {ts}Add Mailchimp Account{/ts}</span></a>
   </div>
   <div class="clear"></div>
{/if}
<div class="crm-content-block">

{if $accounts and $action eq 16}

<div class="crm-results-block">
{* show browse table for any action *}
<div id="notes">
    {strip}
        <table id="options" class="display crm-sortable">
        <thead>
        <tr>
          <th>{ts}API Key{/ts}</th>
          <th>{ts}Security Key{/ts}</th>
          <th>{ts}List Removal{/ts}</th>
          <th data-orderable="false"></th>
        </tr>
        </thead>

        {foreach from=$accounts item=account}
        <tr id="cnote_{$account.id}" class="{cycle values="odd-row,even-row"} crm-note">
            <td class="crm-mailchimp-apiKey">
	      {$account.api_key}
            </td>
            <td class="crm-mailchimp-securityKey">{$account.security_key}</td>
            <td class="crm-mailchimp-listRemoval">
                {$account.list_removal}
            </td>
            <td class="nowrap">{$account.action}</td>
        </tr>
        {/foreach}
        </table>
    {/strip}
 </div>
</div>
{elseif ($action eq 16)}
   <div class="messages status no-popup">
      <div class="icon inform-icon"></div>
      {ts}There are no Mailchimp Accounts.{/ts}
   </div>
{/if}
</div>
</div>
