<h2>Mailchimp syn Error Details</h2><br/>
<table id="mailchimp_syn_error" cellspacing="0" width="100%">
  <thead>
    <tr>
      <th>Email</th>
      <th>Error</th>
    </tr>
  </thead>

  <tbody> 
    {foreach from=$errordetails item=details}
      <tr>
        <td >{$details.email}</td>
        <td >{$details.error}</td>
      </tr>
    {/foreach}
  </tbody>    
</table>