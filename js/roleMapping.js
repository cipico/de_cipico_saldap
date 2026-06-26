(function($) {
  'use strict';

  if (typeof saldapRoles === 'undefined') return;

  var $textarea, $tbody;

  function buildRow(groupName, roleId) {
    var $row = $('<tr>');
    var $input = $('<input type="text" class="saldap-group" style="width:200px;" placeholder="e.g. employees">');
    if (groupName) $input.val(groupName);

    var $select = $('<select class="saldap-role crm-select2" style="width:280px;">');
    $select.append($('<option value="">- select role -</option>'));
    $.each(saldapRoles, function(id, label) {
      $select.append($('<option>').val(id).text(label).prop('selected', String(id) === String(roleId)));
    });

    var $remove = $('<a href="#" class="button small" style="margin-left:4px;">Remove</a>').on('click', function(e) {
      e.preventDefault();
      $row.remove();
      sync();
    });

    $input.on('change', sync);
    $select.on('change', sync);

    $row.append($('<td>').append($input));
    $row.append($('<td style="padding:0 4px;">').append($select));
    $row.append($('<td>').append($remove));
    return $row;
  }

  function sync() {
    var lines = [];
    $tbody.find('tr').each(function() {
      var group = $(this).find('.saldap-group').val().trim();
      var role = $(this).find('.saldap-role').val();
      if (group && role) {
        var full = 'cn=' + group + ',ou=groups';
        if (saldapGroupBase) full += ',' + saldapGroupBase;
        lines.push(full + '|' + role);
      }
    });
    $textarea.val(lines.join('\n'));
  }

  function init() {
    $textarea = $('textarea[name="saldap_ldap_role_mappings"]');
    if (!$textarea.length) return;

    $textarea.hide();

    var $wrapper = $('<div class="saldap-role-mappings" style="margin-top:4px;">');
    var $table = $('<table class="selector" style="width:auto;min-width:520px;"><thead><tr>' +
      '<th>LDAP Group</th>' +
      '<th>CiviCRM Role</th>' +
      '<th></th>' +
      '</tr></thead><tbody></tbody></table>');
    $tbody = $table.find('tbody');

    var $addBtn = $('<a href="#" class="button"><span>Add Row</span></a>').on('click', function(e) {
      e.preventDefault();
      var $r = buildRow('', '');
      $tbody.append($r);
      $r.find('.saldap-role').crmSelect2();
      sync();
    });

    $.each($textarea.val().split('\n'), function(_, line) {
      line = line.trim();
      if (!line) return;
      var parts = line.split('|');
      if (parts.length === 2) {
        var dn = parts[0].trim();
        var roleId = parts[1].trim();
        var m = dn.match(/^cn=([^,]+)/i);
        $tbody.append(buildRow(m ? m[1] : dn, roleId));
      }
    });

    $wrapper.append($table);
    $wrapper.append($('<div style="padding-top:6px;">').append($addBtn));
    $textarea.after($wrapper);
    $wrapper.find('.saldap-role').crmSelect2();

    $textarea.closest('form').on('submit', sync);
  }

  $(init);

})(CRM.$);
