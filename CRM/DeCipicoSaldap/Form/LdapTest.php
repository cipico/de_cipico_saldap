<?php
declare(strict_types = 1);

use CRM_DeCipicoSaldap_ExtensionUtil as E;

class CRM_DeCipicoSaldap_Form_LdapTest extends CRM_Core_Form {

  public function buildQuickForm(): void {
    $this->add('text', 'test_username', E::ts('Test Username'), [
      'class' => 'huge',
      'placeholder' => E::ts('Enter an LDAP username to verify their password'),
    ]);
    $this->add('password', 'test_password', E::ts('Test Password'), [
      'placeholder' => E::ts('Enter the password'),
    ]);

    $this->addButtons([
      [
        'type' => 'submit',
        'name' => E::ts('Test User Login'),
        'isDefault' => TRUE,
      ],
      [
        'type' => 'submit',
        'name' => E::ts('Test Connection Only'),
        'subName' => 'test_conn',
      ],
      [
        'type' => 'cancel',
        'name' => E::ts('Back to Settings'),
      ],
    ]);

    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function setDefaultValues() {
    return [
      'saldap_ldap_host' => Civi::settings()->get('saldap_ldap_host'),
      'saldap_ldap_port' => Civi::settings()->get('saldap_ldap_port'),
      'saldap_ldap_base_dn' => Civi::settings()->get('saldap_ldap_base_dn'),
      'saldap_ldap_bind_dn' => Civi::settings()->get('saldap_ldap_bind_dn'),
      'saldap_ldap_user_filter' => Civi::settings()->get('saldap_ldap_user_filter'),
      'saldap_ldap_group_dn' => Civi::settings()->get('saldap_ldap_group_dn'),
    ];
  }

  public function postProcess(): void {
    $values = $this->exportValues();
    $isConnTest = !empty($_POST['_qf_LdapTest_submit_test_conn']);

    if ($isConnTest || (empty($values['test_username']) || empty($values['test_password']))) {
      $results = $this->testConnection();
    }
    else {
      $results = $this->testUserLogin($values['test_username'], $values['test_password']);
    }

    $this->assign('test_results', $results);

    $errors = array_filter($results, fn($r) => $r['severity'] === 'error');
    if ($errors) {
      CRM_Core_Session::setStatus(E::ts('Some checks failed. See details below.'), E::ts('Test Failed'), 'error');
    }
    else {
      CRM_Core_Session::setStatus(E::ts('All checks passed.'), E::ts('Test Successful'), 'success');
    }

    parent::postProcess();
  }

  public function cancelAction() {
    $settingsUrl = CRM_Utils_System::url('civicrm/admin/setting/de_cipico_saldap', 'reset=1');
    CRM_Utils_System::redirect($settingsUrl);
  }

  private function testUserLogin(string $username, string $password): array {
    $lines = [];
    $settings = $this->getSettings();

    if (empty($settings['host']) || empty($settings['base_dn'])) {
      $lines[] = $this->line('error', E::ts('Configuration'), E::ts('LDAP Server and Base DN must be configured.'));
      return $lines;
    }

    if ($this->usesTls($settings)) {
      putenv('LDAPTLS_REQCERT=never');
    }

    $conn = $this->connect($settings, $lines);
    if (!$conn) {
      return $lines;
    }

    if (!empty($settings['bind_dn'])) {
      $bindOk = @ldap_bind($conn, $settings['bind_dn'], $settings['bind_password']);
    }
    else {
      $bindOk = @ldap_bind($conn);
    }
    if (!$bindOk) {
      $lines[] = $this->line('error', E::ts('Bind'), E::ts('Service account bind failed: %1.', [1 => ldap_error($conn)]));
      @ldap_unbind($conn);
      return $lines;
    }
    $lines[] = $this->line('ok', E::ts('Bind'), E::ts('Service account bind succeeded.'));

    $safeUsername = strtr($username, [
      '\\' => '\\5c', '*' => '\\2a', '(' => '\\28', ')' => '\\29', "\x00" => '\\00',
    ]);
    $filter = sprintf($settings['user_filter'], $safeUsername);

    $sr = @ldap_search($conn, $settings['base_dn'], $filter, ['dn', 'cn', 'mail', 'givenName', 'sn', 'uid', 'memberOf'], 0, 1);
    if ($sr === FALSE) {
      $lines[] = $this->line('error', E::ts('Search'), E::ts('User search failed: %1.', [1 => ldap_error($conn)]));
      @ldap_unbind($conn);
      return $lines;
    }

    $entries = @ldap_get_entries($conn, $sr);
    if ($entries === FALSE || $entries['count'] === 0) {
      $lines[] = $this->line('error', E::ts('User'), E::ts('No user matched filter "%1".', [1 => $filter]));
      @ldap_unbind($conn);
      return $lines;
    }

    $userEntry = $entries[0];
    $userDn = $userEntry['dn'];
    $lines[] = $this->line('ok', E::ts('User'), E::ts('Found: %1', [1 => $userDn]));

    $passwordOk = @ldap_bind($conn, $userDn, $password);
    if (!$passwordOk) {
      $lines[] = $this->line('error', E::ts('Password'), E::ts('Invalid credentials for "%1".', [1 => $username]));
      @ldap_unbind($conn);
      return $lines;
    }
    $lines[] = $this->line('ok', E::ts('Password'), E::ts('Password correct.'));

    if (!empty($settings['group_dn'])) {
      $memberOf = [];
      if (isset($userEntry['memberof'])) {
        for ($i = 0; $i < $userEntry['memberof']['count']; $i++) {
          $memberOf[] = $userEntry['memberof'][$i];
        }
      }

      $isMember = in_array($settings['group_dn'], $memberOf, TRUE);

      if (!$isMember) {
        $sr = @ldap_read($conn, $settings['group_dn'], '(objectClass=*)', ['member'], 0, 1, 0, LDAP_DEREF_NEVER);
        if ($sr !== FALSE) {
          $entries = @ldap_get_entries($conn, $sr);
          if ($entries !== FALSE && $entries['count'] > 0 && isset($entries[0]['member'])) {
            for ($i = 0; $i < $entries[0]['member']['count']; $i++) {
              if ($entries[0]['member'][$i] === $userEntry['dn']) {
                $isMember = TRUE;
                break;
              }
            }
          }
        }
      }

      if ($isMember) {
        $lines[] = $this->line('ok', E::ts('Group'), E::ts('Is member of "%1".', [1 => $settings['group_dn']]));
      }
      else {
        $lines[] = $this->line('error', E::ts('Group'), E::ts('Not a member of required group "%1".', [1 => $settings['group_dn']]));
        @ldap_unbind($conn);
        return $lines;
      }
    }

    $lines[] = $this->line('ok', E::ts('Result'), E::ts('LDAP login would succeed for "%1".', [1 => $username]));
    @ldap_unbind($conn);
    return $lines;
  }

  private function testConnection(): array {
    $lines = [];
    $settings = $this->getSettings();

    if (empty($settings['host']) || empty($settings['base_dn'])) {
      $lines[] = $this->line('error', E::ts('Config'), E::ts('LDAP Server and Base DN must be configured.'));
      return $lines;
    }

    $lines[] = $this->line('info', E::ts('Server'), E::ts('Connecting to %1:%2 …', [1 => $settings['host'], 2 => $settings['port']]));

    if ($this->usesTls($settings)) {
      putenv('LDAPTLS_REQCERT=never');
    }
    $conn = $this->connect($settings, $lines);
    if (!$conn) {
      return $lines;
    }

    if (!empty($settings['bind_dn'])) {
      $bindResult = @ldap_bind($conn, $settings['bind_dn'], $settings['bind_password']);
    }
    else {
      $bindResult = @ldap_bind($conn);
    }

    if ($bindResult) {
      $lines[] = $this->line('ok', E::ts('Bind'), empty($settings['bind_dn'])
        ? E::ts('Anonymous bind succeeded.')
        : E::ts('Bind as %1 succeeded.', [1 => $settings['bind_dn']]));
    }
    else {
      $lines[] = $this->line('error', E::ts('Bind'), E::ts('Bind failed: %1.', [1 => ldap_error($conn)]));
      @ldap_unbind($conn);
      return $lines;
    }

    $safeUsername = strtr('testuser', [
      '\\' => '\\5c', '*' => '\\2a', '(' => '\\28', ')' => '\\29', "\x00" => '\\00',
    ]);
    $filter = sprintf($settings['user_filter'], $safeUsername);
    $sr = @ldap_search($conn, $settings['base_dn'], $filter, ['dn'], 0, 1);

    if ($sr !== FALSE) {
      $entries = @ldap_get_entries($conn, $sr);
      if ($entries !== FALSE && $entries['count'] > 0) {
        $lines[] = $this->line('ok', E::ts('Search'), E::ts('Filter matched: %1', [1 => $entries[0]['dn']]));
      }
      else {
        $lines[] = $this->line('ok', E::ts('Search'), E::ts('Filter works (no match for dummy user "testuser").'));
      }
    }
    else {
      $lines[] = $this->line('error', E::ts('Search'), E::ts('Search failed: %1.', [1 => ldap_error($conn)]));
    }

    if (!empty($settings['group_dn'])) {
      $lines[] = $this->line('info', E::ts('Group'), E::ts('Group "%1" will be checked during login.', [1 => $settings['group_dn']]));
    }

    @ldap_unbind($conn);
    $lines[] = $this->line('ok', E::ts('Result'), E::ts('Connection test completed.'));
    return $lines;
  }

  private function line(string $severity, string $title, string $message): array {
    return ['severity' => $severity, 'title' => $title, 'message' => $message];
  }

  private function connect(array $settings, array &$lines): mixed {
    $host = $this->prefixScheme($settings['host']);
    $conn = @ldap_connect($host, $settings['port']);
    if ($conn === FALSE) {
      $lines[] = $this->line('error', E::ts('Connection'), E::ts('Failed to connect to LDAP server.'));
      return NULL;
    }
    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);

    $useStartTls = $settings['tls'] && !$this->isLdapsUri($settings['host'], $settings['port']);
    if ($useStartTls) {
      if (@ldap_start_tls($conn)) {
        $lines[] = $this->line('ok', E::ts('TLS'), E::ts('TLS started.'));
      }
      else {
        $lines[] = $this->line('error', E::ts('TLS'), E::ts('TLS failed: %1.', [1 => ldap_error($conn)]));
        @ldap_unbind($conn);
        return NULL;
      }
    }
    elseif ($settings['tls'] && $this->isLdapsUri($settings['host'], $settings['port'])) {
      $lines[] = $this->line('ok', E::ts('TLS'), E::ts('Port 636 — using implicit TLS.'));
    }

    return $conn;
  }

  private function prefixScheme(string $host): string {
    if (str_contains($host, '://')) {
      return $host;
    }
    return 'ldap://' . $host;
  }

  private function usesTls(array $settings): bool {
    return $settings['tls'] || $this->isLdapsUri($settings['host'], $settings['port']);
  }

  private function isLdapsUri(string $host, int $port = 636): bool {
    return stripos($host, 'ldaps://') === 0 || $port === 636;
  }

  private function getSettings(): array {
    return [
      'host' => Civi::settings()->get('saldap_ldap_host'),
      'port' => (int) (Civi::settings()->get('saldap_ldap_port') ?: 389),
      'bind_dn' => Civi::settings()->get('saldap_ldap_bind_dn'),
      'bind_password' => Civi::settings()->get('saldap_ldap_bind_password'),
      'base_dn' => Civi::settings()->get('saldap_ldap_base_dn'),
      'user_filter' => Civi::settings()->get('saldap_ldap_user_filter') ?: '(&(objectClass=person)(uid=%s))',
      'group_dn' => Civi::settings()->get('saldap_ldap_group_dn'),
      'tls' => (bool) Civi::settings()->get('saldap_ldap_tls'),
    ];
  }

  public function getRenderableElementNames(): array {
    $elementNames = [];
    foreach ($this->_elements as $element) {
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

}
