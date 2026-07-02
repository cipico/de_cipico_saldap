<?php
declare(strict_types=1);

namespace Civi\Api4\Action\Saldap;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Set all LDAP configuration settings in a single call.
 *
 * Only the parameters you provide are updated;
 * omitted parameters keep their current value.
 *
 * Usage:
 *
 *   cv api4 Saldap.set '{
 *     "saldap_ldap_host": "ldap.example.com",
 *     "saldap_ldap_port": 636,
 *     "saldap_ldap_base_dn": "dc=example,dc=com",
 *     "saldap_ldap_bind_dn": "cn=readonly,ou=system,dc=example,dc=com",
 *     "saldap_ldap_bind_password": "s3cret",
 *     "saldap_ldap_tls": true,
 *     "saldap_ldap_auto_create_user": true,
 *     "saldap_ldap_sync_contact": false,
 *     "saldap_ldap_user_filter": "(\u0026(objectClass=person)(uid=%s))",
 *     "saldap_ldap_group_dn": "cn=admins,ou=groups,dc=example,dc=com",
 *     "saldap_ldap_attr_mail": "mail",
 *     "saldap_ldap_attr_first_name": "givenName",
 *     "saldap_ldap_attr_last_name": "sn",
 *     "saldap_ldap_role_mappings": "cn=employees,ou=groups,dc=example,dc=com|3"
 *   }'
 *
 * Role mappings must use full group DNs (e.g. `cn=employees,ou=groups,dc=example,dc=com|3`).
 * Short names like `employees|3` will not match.
 * For multiple mappings, use \n between entries in the JSON string.
 *
 * Or with cv's key=value syntax (note the + prefix for setting values):
 *
 *   cv api Saldap.set \
 *     saldap_ldap_host=ldap.example.com \
 *     saldap_ldap_port=636 \
 *     saldap_ldap_base_dn="dc=example,dc=com"
 */
class Set extends AbstractAction {

  public function __construct(string $entityName, string $actionName) {
    parent::__construct($entityName, $actionName);
    foreach (self::settingNames() as $name) {
      $meta = self::settingMeta($name);
      $param = $this->createParam($name, $meta['data_type'], FALSE)
        ->setLabel($meta['title'] ?? $name)
        ->setDescription($meta['description'] ?? '');
      if (array_key_exists('default', $meta)) {
        $param->setDefault($meta['default']);
      }
    }
  }

  public function _run(Result $result): void {
    $settings = \Civi::settings();
    $updated = [];

    foreach (self::settingNames() as $name) {
      if ($this->paramExists($name)) {
        $val = $this->getParam($name);
        $settings->set($name, $val);
        $updated[$name] = $val;
      }
    }

    $result[] = [
      'success' => TRUE,
      'updated' => $updated,
    ];
  }

  /**
   * @return list<string>
   */
  public static function settingNames(): array {
    return [
      'saldap_ldap_host',
      'saldap_ldap_port',
      'saldap_ldap_bind_dn',
      'saldap_ldap_bind_password',
      'saldap_ldap_base_dn',
      'saldap_ldap_user_filter',
      'saldap_ldap_group_dn',
      'saldap_ldap_tls',
      'saldap_ldap_auto_create_user',
      'saldap_ldap_sync_contact',
      'saldap_ldap_attr_mail',
      'saldap_ldap_attr_first_name',
      'saldap_ldap_attr_last_name',
      'saldap_ldap_role_mappings',
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public static function settingMeta(string $name): array {
    $map = [
      'saldap_ldap_host' => [
        'title' => 'LDAP Server URI',
        'description' => 'LDAP server URI, e.g. "ldap://ldap.example.com" or "ldaps://ldap.example.com".',
        'data_type' => 'String',
        'default' => '',
      ],
      'saldap_ldap_port' => [
        'title' => 'LDAP Port',
        'description' => 'LDAP server port (default: 389, LDAPS: 636).',
        'data_type' => 'Integer',
        'default' => 389,
      ],
      'saldap_ldap_bind_dn' => [
        'title' => 'Bind DN',
        'description' => 'Distinguished Name for the service account used to search the directory. Leave empty for anonymous bind.',
        'data_type' => 'String',
        'default' => '',
      ],
      'saldap_ldap_bind_password' => [
        'title' => 'Bind Password',
        'description' => 'Password for the bind DN service account.',
        'data_type' => 'String',
        'default' => '',
      ],
      'saldap_ldap_base_dn' => [
        'title' => 'Base DN',
        'description' => 'Base Distinguished Name for user searches, e.g. "dc=example,dc=com".',
        'data_type' => 'String',
        'default' => '',
      ],
      'saldap_ldap_user_filter' => [
        'title' => 'User Search Filter',
        'description' => 'LDAP filter to locate user entries. Use %s as placeholder for the username.',
        'data_type' => 'String',
        'default' => '(&(objectClass=person)(uid=%s))',
      ],
      'saldap_ldap_group_dn' => [
        'title' => 'Required Group DN',
        'description' => 'If set, only users whose memberOf attribute contains this DN will be authorized. Leave empty to skip group check.',
        'data_type' => 'String',
        'default' => '',
      ],
      'saldap_ldap_tls' => [
        'title' => 'Use TLS',
        'description' => 'Enable TLS for the LDAP connection (startTLS).',
        'data_type' => 'Boolean',
        'default' => FALSE,
      ],
      'saldap_ldap_auto_create_user' => [
        'title' => 'Auto-Create Users',
        'description' => 'Automatically create a CiviCRM user and contact when an LDAP user logs in for the first time.',
        'data_type' => 'Boolean',
        'default' => TRUE,
      ],
      'saldap_ldap_sync_contact' => [
        'title' => 'Sync Contact on Every Login',
        'description' => 'Update contact name and email from LDAP on every login. Disable to only sync on initial creation.',
        'data_type' => 'Boolean',
        'default' => TRUE,
      ],
      'saldap_ldap_attr_mail' => [
        'title' => 'Mail Attribute',
        'description' => 'LDAP attribute containing the email address.',
        'data_type' => 'String',
        'default' => 'mail',
      ],
      'saldap_ldap_attr_first_name' => [
        'title' => 'First Name Attribute',
        'description' => 'LDAP attribute containing the first/given name.',
        'data_type' => 'String',
        'default' => 'givenName',
      ],
      'saldap_ldap_attr_last_name' => [
        'title' => 'Last Name Attribute',
        'description' => 'LDAP attribute containing the last name/surname.',
        'data_type' => 'String',
        'default' => 'sn',
      ],
      'saldap_ldap_role_mappings' => [
        'title' => 'LDAP Group → Role Mappings',
        'description' => 'Define which LDAP groups map to which CiviCRM roles. One mapping per line: cn=<group>,ou=groups,<domain>|<roleId>',
        'data_type' => 'String',
        'default' => '',
      ],
    ];
    return $map[$name] ?? ['title' => $name, 'description' => '', 'data_type' => 'String'];
  }

}
