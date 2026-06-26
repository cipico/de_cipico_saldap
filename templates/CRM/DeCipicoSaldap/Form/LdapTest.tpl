<div class="crm-block crm-form-block">
  <div class="help">
    <h3>{ts}Connection Test{/ts}</h3>
    <p>{ts}Click "Test Connection Only" to verify the LDAP server is reachable and the bind/search work.{/ts}</p>
    <h3>{ts}User Login Test{/ts}</h3>
    <p>{ts}Enter an LDAP username and password to verify that a specific user can authenticate.{/ts}</p>
  </div>

  <div class="crm-section">
    <div class="label">{$form.test_username.label}</div>
    <div class="content">{$form.test_username.html}</div>
    <div class="clear"></div>
  </div>
  <div class="crm-section">
    <div class="label">{$form.test_password.label}</div>
    <div class="content">{$form.test_password.html}</div>
    <div class="clear"></div>
  </div>

  {if $test_results}
  <div class="crm-section">
    <div class="label">&nbsp;</div>
    <div class="content">
      <table class="report">
        {foreach from=$test_results item=r}
        <tr style="vertical-align:top;">
          <td style="padding-right:8px;white-space:nowrap;">
            {if $r.severity eq 'ok'}<span style="color:green;">&#10003;</span>
            {elseif $r.severity eq 'error'}<span style="color:red;">&#10007;</span>
            {else}<span style="color:#888;">&#8594;</span>{/if}
            <strong>{$r.title}</strong>
          </td>
          <td>{$r.message}</td>
        </tr>
        {/foreach}
      </table>
    </div>
    <div class="clear"></div>
  </div>
  {/if}

  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="top"}
  </div>
</div>
