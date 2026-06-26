<?php
declare(strict_types = 1);

// phpcs:disable PSR1.Files.SideEffects
require_once 'de_cipico_saldap.civix.php';
// phpcs:enable

use CRM_DeCipicoSaldap_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function de_cipico_saldap_civicrm_config(\CRM_Core_Config $config): void {
  _de_cipico_saldap_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function de_cipico_saldap_civicrm_install(): void {
  _de_cipico_saldap_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function de_cipico_saldap_civicrm_enable(): void {
  _de_cipico_saldap_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_alterSettingsMetaData().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsMetaData
 */
function de_cipico_saldap_civicrm_alterSettingsMetaData(array &$settingsMetaData): void {
  // Kept for future metadata modifications if needed
}

/**
 * Implements hook_civicrm_buildForm().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_buildForm
 */
function de_cipico_saldap_civicrm_buildForm($formName, &$form): void {
  if ($formName !== 'CRM_Admin_Form_Generic' || $form->getSettingPageFilter() !== 'de_cipico_saldap') {
    return;
  }

  $testUrl = CRM_Utils_System::url('civicrm/saldap/test', 'reset=1');
  CRM_Core_Region::instance('page-body')->add([
    'markup' => '<div class="crm-section">
      <div class="label">&nbsp;</div>
      <div class="content">
        <a href="' . $testUrl . '" class="button"><span><i class="crm-i fa-flask"></i> ' . ts('Test Connection') . '</span></a>
        <div class="description">' . ts('Click to verify LDAP connectivity using the currently saved settings.') . '</div>
      </div>
      <div class="clear"></div>
    </div>',
    'weight' => -1,
  ]);

  $roles = [];
  try {
    $r = \Civi\Api4\Role::get(FALSE)
      ->addSelect('id', 'name', 'label')
      ->addOrderBy('id')
      ->execute();
    foreach ($r as $role) {
      $roles[$role['id']] = $role['label'] . ' (' . $role['name'] . ')';
    }
  }
  catch (\Exception $e) {
  }

  $domainBase = '';
  $baseDn = Civi::settings()->get('saldap_ldap_base_dn') ?: '';
  if ($baseDn) {
    $p = explode(',', $baseDn);
    $domainBase = implode(',', array_filter($p, fn($s) => stripos(trim($s), 'dc=') === 0));
  }

  $raw = (string) (Civi::settings()->get('saldap_ldap_role_mappings') ?? '');
  $rows = [];
  foreach (explode("\n", $raw) as $line) {
    $line = trim($line);
    if (!$line) continue;
    $parts = explode('|', $line);
    if (count($parts) === 2) {
      $dn = trim($parts[0]);
      $roleId = trim($parts[1]);
      $name = preg_match('/^cn=([^,]+)/i', $dn, $m) ? $m[1] : $dn;
      $rows[] = ['group' => $name, 'role_id' => $roleId];
    }
  }

  $form->assign('saldap_role_options', $roles);
  $form->assign('saldap_mapping_rows', $rows);
  $form->assign('saldap_group_base_dn', $domainBase ? 'ou=groups,' . $domainBase : '');

  $jsonRoles = json_encode($roles);
  $jsonBase = json_encode($domainBase ? 'ou=groups,' . $domainBase : '');

  CRM_Core_Resources::singleton()->addScript('var saldapRoles = ' . $jsonRoles . '; var saldapGroupBase = ' . $jsonBase . ';');

  $inline = <<<JS
(function(\$) {
  var t = \$('textarea[name="saldap_ldap_role_mappings"]');
  if (!t.length) return;
  t.hide();
  var b = \$('<tbody>');
  function r(g, i) {
    var o = \$('<input type="text" style="width:200px;" placeholder="e.g. employees">').val(g);
    var s = \$('<select class="crm-select2" style="width:280px;">').append(\$('<option value="">- select role -</option>'));
    \$.each(saldapRoles, function(id, lb) { s.append(\$('<option>').val(id).text(lb).prop('selected', String(id)===String(i))); });
    var x = \$('<a href="#" class="button small">Remove</a>').on('click', function(e) { e.preventDefault(); o.closest('tr').remove(); y(); });
    var w = \$('<tr>').append(\$('<td>').append(o)).append(\$('<td>').append(s)).append(\$('<td>').append(x));
    o.add(s).on('change', y);
    return w;
  }
  function y() {
    var ls = [];
    b.find('tr').each(function() {
      var g = \$(this).find('input').val().trim(), i = \$(this).find('select').val();
      if (g && i) ls.push('cn=' + g + ',ou=groups' + (saldapGroupBase ? ',' + saldapGroupBase : '') + '|' + i);
    });
    t.val(ls.join('\\n'));
  }
  \$.each(t.val().split('\\n'), function(_, l) {
    l = l.trim(); if (!l) return;
    var p = l.split('|'); if (p.length < 2) return;
    var m = p[0].match(/^cn=([^,]+)/i);
    b.append(r(m ? m[1] : p[0], p[1]));
  });
  var tbl = \$('<table class="selector" style="min-width:520px;"><thead><tr><th>LDAP Group</th><th>CiviCRM Role</th><th></th></tr></thead>').append(b);
  var a = \$('<a href="#" class="button"><span>Add Row</span></a>').on('click', function(e) {
    e.preventDefault();
    var w = r('', ''); b.append(w); w.find('.crm-select2').crmSelect2(); y();
  });
  t.after(\$('<div>').append(tbl).append(\$('<div style="padding-top:6px;">').append(a)));
  t.closest('form').on('submit', y);
  b.find('.crm-select2').crmSelect2();
})(CRM.\$);
JS;

  CRM_Core_Resources::singleton()->addScript($inline);
}
