<?php
declare(strict_types = 1);

use Civi\Api4\Saldap;
use Civi\Test\TransactionalInterface;

/**
 * @covers \Civi\Api4\Saldap
 * @covers \Civi\Api4\Action\Saldap\Get
 * @covers \Civi\Api4\Action\Saldap\Set
 */
class Api4_SaldapTest extends \PHPUnit\Framework\TestCase implements TransactionalInterface {

  private array $defaults = [
    'saldap_ldap_host' => '',
    'saldap_ldap_port' => 389,
    'saldap_ldap_bind_dn' => '',
    'saldap_ldap_bind_password' => '',
    'saldap_ldap_base_dn' => '',
    'saldap_ldap_user_filter' => '(&(objectClass=person)(uid=%s))',
    'saldap_ldap_group_dn' => '',
    'saldap_ldap_tls' => FALSE,
    'saldap_ldap_auto_create_user' => TRUE,
    'saldap_ldap_sync_contact' => TRUE,
    'saldap_ldap_attr_mail' => 'mail',
    'saldap_ldap_attr_first_name' => 'givenName',
    'saldap_ldap_attr_last_name' => 'sn',
    'saldap_ldap_role_mappings' => '',
  ];

  protected function setUp(): void {
    parent::setUp();
    foreach ($this->defaults as $name => $value) {
      \Civi::settings()->set($name, $value);
    }
  }

  private function callSet(array $params): array {
    return civicrm_api4('Saldap', 'set', $params + ['checkPermissions' => FALSE])->first();
  }

  private function callGet(): array {
    return Saldap::get()
      ->setCheckPermissions(FALSE)
      ->execute()
      ->first();
  }

  public function testGetReturnsAllSettingsWithDefaults(): void {
    $result = $this->callGet();

    $this->assertNotNull($result);
    foreach ($this->defaults as $name => $default) {
      // CiviCRM returns null for unset string settings,
      // so accept both '' and null for empty-string defaults.
      if ($default === '') {
        $this->assertTrue($result[$name] === '' || $result[$name] === NULL, "Mismatch for $name");
      }
      else {
        $this->assertSame($default, $result[$name], "Mismatch for $name");
      }
    }
  }

  public function testSetSingleValue(): void {
    $result = $this->callSet(['saldap_ldap_host' => 'ldap.example.com']);

    $this->assertTrue($result['success']);
    $this->assertArrayHasKey('updated', $result);
    $this->assertSame('ldap.example.com', $result['updated']['saldap_ldap_host']);

    $getResult = $this->callGet();
    $this->assertSame('ldap.example.com', $getResult['saldap_ldap_host']);
    $this->assertSame(389, $getResult['saldap_ldap_port']);
  }

  public function testSetMultipleValues(): void {
    $data = [
      'saldap_ldap_host' => 'ldap.example.com',
      'saldap_ldap_port' => 636,
      'saldap_ldap_base_dn' => 'dc=example,dc=com',
      'saldap_ldap_bind_dn' => 'cn=readonly,ou=system,dc=example,dc=com',
      'saldap_ldap_bind_password' => 's3cret',
      'saldap_ldap_tls' => TRUE,
      'saldap_ldap_auto_create_user' => TRUE,
      'saldap_ldap_sync_contact' => FALSE,
      'saldap_ldap_user_filter' => '(&(objectClass=person)(uid=%s))',
      'saldap_ldap_group_dn' => 'cn=admins,ou=groups,dc=example,dc=com',
      'saldap_ldap_attr_mail' => 'mail',
      'saldap_ldap_attr_first_name' => 'givenName',
      'saldap_ldap_attr_last_name' => 'sn',
    ];

    $result = $this->callSet($data);

    $this->assertTrue($result['success']);
    foreach ($data as $key => $value) {
      $this->assertArrayHasKey($key, $result['updated']);
      $this->assertSame($value, $result['updated'][$key]);
    }

    $getResult = $this->callGet();
    foreach ($data as $key => $value) {
      $this->assertSame($value, $getResult[$key], "Mismatch for $key");
    }
  }

  public function testSetOnlyUpdatesProvidedParams(): void {
    $this->callSet([
      'saldap_ldap_host' => 'ldap.example.com',
      'saldap_ldap_port' => 636,
    ]);

    $this->callSet(['saldap_ldap_port' => 389]);

    $result = $this->callGet();
    $this->assertSame('ldap.example.com', $result['saldap_ldap_host']);
    $this->assertSame(389, $result['saldap_ldap_port']);
  }

  public function testSetRoleMappings(): void {
    $mappings = "cn=employees,ou=groups,dc=example,dc=com|3\ncn=admins,ou=groups,dc=example,dc=com|2";

    $this->callSet(['saldap_ldap_role_mappings' => $mappings]);

    $result = $this->callGet();
    $this->assertSame($mappings, $result['saldap_ldap_role_mappings']);

    $stored = \Civi::settings()->get('saldap_ldap_role_mappings');
    $this->assertSame($mappings, $stored);
  }

  public function testSetRoleMappingsSingleMapping(): void {
    $mapping = "cn=employees,ou=groups,dc=example,dc=com|3";

    $this->callSet(['saldap_ldap_role_mappings' => $mapping]);

    $result = $this->callGet();
    $this->assertSame($mapping, $result['saldap_ldap_role_mappings']);
  }

  public function testSetPreservesExistingValues(): void {
    $this->callSet([
      'saldap_ldap_host' => 'ldap.one.com',
      'saldap_ldap_port' => 636,
    ]);

    $this->callSet(['saldap_ldap_host' => 'ldap.two.com']);

    $result = $this->callGet();
    $this->assertSame('ldap.two.com', $result['saldap_ldap_host']);
    $this->assertSame(636, $result['saldap_ldap_port']);
  }

  public function testGetFields(): void {
    $fields = Saldap::getFields()
      ->setCheckPermissions(FALSE)
      ->execute();
    $fieldNames = [];
    foreach ($fields as $field) {
      $fieldNames[] = $field['name'];
    }

    foreach (\Civi\Api4\Action\Saldap\Set::settingNames() as $name) {
      $this->assertContains($name, $fieldNames, "Field $name not found in getFields");
    }
  }

}
