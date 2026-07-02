<?php
declare(strict_types = 1);

use Civi\Standalone\Security;
use Civi\Api4\User as UserApi;
use Civi\Api4\Contact as ContactApi;

class CRM_DeCipicoSaldap_Ldap {

  private const ATTRS = ['dn', 'cn', 'mail', 'givenName', 'sn', 'memberOf', 'uid'];

  private const LEVEL_DEBUG = 'debug';

  private const LEVEL_INFO = 'info';

  private const LEVEL_WARNING = 'warning';

  private const LEVEL_ERROR = 'error';

  public function authenticate(string $username, string $password): ?array {
    $settings = $this->getSettings();
    if (!$this->isConfigured($settings)) {
      return NULL;
    }

    $conn = $this->connect($settings);
    if ($conn === NULL) {
      return NULL;
    }

    try {
      $userEntry = $this->searchUser($conn, $username, $settings);
      if ($userEntry === NULL) {
        $this->log(self::LEVEL_INFO, 'User not found in LDAP: {username}', ['username' => $username]);
        return NULL;
      }

      if (!$this->verifyPassword($conn, $userEntry['dn'], $password)) {
        $this->log(self::LEVEL_DEBUG, 'LDAP password verification failed for: {username}', ['username' => $username]);
        return NULL;
      }

      if (!$this->checkGroupMembership($conn, $userEntry, $settings)) {
        $this->log(self::LEVEL_WARNING, 'LDAP group membership check failed for: {username}', ['username' => $username]);
        return NULL;
      }

      $groups = $this->fetchUserGroups($conn, $userEntry['dn'], $settings);

      return [
        'dn' => $userEntry['dn'],
        'username' => $username,
        'uid' => $userEntry['uid'][0] ?? $username,
        'mail' => $userEntry['mail'][0] ?? '',
        'first_name' => $userEntry['givenname'][0] ?? '',
        'last_name' => $userEntry['sn'][0] ?? '',
        'groups' => $groups,
      ];
    }
    finally {
      @ldap_unbind($conn);
    }
  }

  public function findOrCreateUser(string $username, array $ldapAttrs, string $password = ''): ?int {
    $existing = UserApi::get(FALSE)
      ->addWhere('username', '=', $username)
      ->addSelect('id', 'contact_id')
      ->execute()
      ->first();

    if ($existing) {
      if ($password !== '') {
        try {
          UserApi::update(FALSE)
            ->addWhere('id', '=', $existing['id'])
            ->addValue('password', $password)
            ->execute();
        }
        catch (\Exception $e) {
          $this->log(self::LEVEL_WARNING, 'Failed to update password for existing user {username}: {error}', [
            'username' => $username,
            'error' => $e->getMessage(),
          ]);
        }
      }

      if (!empty($existing['contact_id']) && Civi::settings()->get('saldap_ldap_sync_contact')) {
        try {
          ContactApi::update(FALSE)
            ->addWhere('id', '=', $existing['contact_id'])
            ->addValue('first_name', $ldapAttrs['first_name'] ?: $username)
            ->addValue('last_name', $ldapAttrs['last_name'] ?: $username)
            ->execute();
          if (!empty($ldapAttrs['mail'])) {
            \Civi\Api4\Email::create(FALSE)
              ->addValue('contact_id', $existing['contact_id'])
              ->addValue('email', $ldapAttrs['mail'])
              ->addValue('location_type_id', 1)
              ->execute();
          }
        }
        catch (\Exception $e) {
          $this->log(self::LEVEL_WARNING, 'Failed to sync contact for existing user {username}: {error}', [
            'username' => $username,
            'error' => $e->getMessage(),
          ]);
        }
      }

      return (int) $existing['id'];
    }

    if (!Civi::settings()->get('saldap_ldap_auto_create_user')) {
      return NULL;
    }

    try {
      $contactId = NULL;
      // Try to find existing contact by email first
      if (!empty($ldapAttrs['mail'])) {
        $existingEmail = \Civi\Api4\Email::get(FALSE)
          ->addWhere('email', '=', $ldapAttrs['mail'])
          ->addSelect('contact_id')
          ->execute()
          ->first();
        if ($existingEmail) {
          $contactId = (int) $existingEmail['contact_id'];
        }
      }
      // Fall back to looking up by name
      if ($contactId === NULL && !empty($ldapAttrs['first_name'])) {
        $existingContact = ContactApi::get(FALSE)
          ->addWhere('first_name', '=', $ldapAttrs['first_name'])
          ->addWhere('last_name', '=', $ldapAttrs['last_name'])
          ->addSelect('id')
          ->execute()
          ->first();
        if ($existingContact) {
          $contactId = (int) $existingContact['id'];
        }
      }

      if ($contactId === NULL) {
        try {
          $createdContact = ContactApi::create(FALSE)
            ->addValue('contact_type', 'Individual')
            ->addValue('first_name', $ldapAttrs['first_name'] ?: $username)
            ->addValue('last_name', $ldapAttrs['last_name'] ?: $username)
            ->execute()
            ->single();
          $contactId = (int) $createdContact['id'];
        }
        catch (\Exception $e) {
          $this->log(self::LEVEL_ERROR, 'Failed to create contact for {username}: {error}', [
            'username' => $username,
            'error' => $e->getMessage(),
          ]);
          return NULL;
        }
        if (!empty($ldapAttrs['mail'])) {
          try {
            \Civi\Api4\Email::create(FALSE)
              ->addValue('contact_id', $contactId)
              ->addValue('email', $ldapAttrs['mail'])
              ->addValue('location_type_id', 1)
              ->execute();
          }
          catch (\Exception $e) {
            $this->log(self::LEVEL_WARNING, 'Failed to set email for contact {cid}: {error}', [
              'cid' => $contactId,
              'error' => $e->getMessage(),
            ]);
          }
        }
      }

      if ($contactId === NULL) {
        $this->log(self::LEVEL_ERROR, 'Failed to resolve contact for user {username}', ['username' => $username]);
        return NULL;
      }
      $password = $password ?: bin2hex(random_bytes(16));
      $userId = UserApi::create(FALSE)
        ->addValue('username', $username)
        ->addValue('uf_name', $ldapAttrs['mail'] ?: $username)
        ->addValue('contact_id', $contactId)
        ->addValue('password', $password)
        ->execute()
        ->single()['id'];

      $ufName = $ldapAttrs['mail'] ?: $username;
      // Use direct SQL for UFMatch since the DAO doesn't support the username field
      \Civi\Api4\UFMatch::delete(FALSE)
        ->addWhere('uf_name', '=', $ufName)
        ->execute();
      $domainId = CRM_Core_Config::domainID();
      \CRM_Core_DAO::executeQuery(
        'INSERT INTO civicrm_uf_match (domain_id, uf_id, uf_name, contact_id, username) VALUES (%1, %2, %3, %4, %5)',
        [
          1 => [$domainId, 'Integer'],
          2 => [$userId, 'Integer'],
          3 => [$ufName, 'String'],
          4 => [$contactId, 'Integer'],
          5 => [$username, 'String'],
        ]
      );

      $this->log(self::LEVEL_INFO, 'Auto-created CiviCRM user {username} (uid={userId}, cid={contactId})', [
        'username' => $username,
        'userId' => $userId,
        'contactId' => $contactId,
      ]);

      return (int) $userId;
    }
    catch (\Exception $e) {
      $this->log(self::LEVEL_ERROR, 'Failed to auto-create user {username}: {error}', [
        'username' => $username,
        'error' => $e->getMessage(),
      ]);
      return NULL;
    }
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
      'group_filter' => Civi::settings()->get('saldap_ldap_group_filter'),
      'tls' => (bool) Civi::settings()->get('saldap_ldap_tls'),
    ];
  }

  private function isConfigured(array $settings): bool {
    return !empty($settings['host']) && !empty($settings['base_dn']);
  }

  private function connect(array $settings): mixed {
    if ($this->usesTls($settings)) {
      putenv('LDAPTLS_REQCERT=never');
    }
    $host = $this->prefixScheme($settings['host']);
    $conn = @ldap_connect($host, $settings['port']);
    if ($conn === FALSE) {
      $this->log(self::LEVEL_ERROR, 'Failed to connect to LDAP server: {host}:{port}', [
        'host' => $settings['host'],
        'port' => $settings['port'],
      ]);
      return NULL;
    }

    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);

    $useStartTls = $settings['tls'] && !$this->isLdapsUri($settings['host'], $settings['port']);
    if ($useStartTls) {
      if (!@ldap_start_tls($conn)) {
        $this->log(self::LEVEL_WARNING, 'Failed to start TLS for LDAP connection');
        @ldap_unbind($conn);
        return NULL;
      }
    }

    if (!empty($settings['bind_dn'])) {
      if (!@ldap_bind($conn, $settings['bind_dn'], $settings['bind_password'])) {
        $this->log(self::LEVEL_ERROR, 'LDAP bind failed as {bind_dn}: {error}', [
          'bind_dn' => $settings['bind_dn'],
          'error' => ldap_error($conn),
        ]);
        @ldap_unbind($conn);
        return NULL;
      }
    }
    else {
      @ldap_bind($conn);
    }

    return $conn;
  }

  private function searchUser(mixed $conn, string $username, array $settings): ?array {
    $safeUsername = $this->escapeLdapFilter($username);
    $filter = sprintf($settings['user_filter'], $safeUsername);

    $sr = @ldap_search($conn, $settings['base_dn'], $filter, self::ATTRS);
    if ($sr === FALSE) {
      $this->log(self::LEVEL_WARNING, 'LDAP search failed: {error}', ['error' => ldap_error($conn)]);
      return NULL;
    }

    $entries = @ldap_get_entries($conn, $sr);
    if ($entries === FALSE || $entries['count'] === 0) {
      return NULL;
    }

    return $entries[0];
  }

  private function verifyPassword(mixed $conn, string $userDn, string $password): bool {
    return (bool) @ldap_bind($conn, $userDn, $password);
  }

  private function fetchUserGroups(mixed $conn, string $userDn, array $settings): array {
    $groups = [];

    $domainBase = $this->extractDomainBase($userDn);
    if ($domainBase === NULL) {
      return $groups;
    }

    $sr = @ldap_search($conn, $domainBase, '(member=' . $this->escapeLdapFilter($userDn) . ')', ['dn'], 0, 0);
    if ($sr !== FALSE) {
      $entries = @ldap_get_entries($conn, $sr);
      for ($i = 0; $i < ($entries['count'] ?? 0); $i++) {
        $groups[] = $entries[$i]['dn'];
      }
    }

    return $groups;
  }

  private function extractDomainBase(string $dn): ?string {
    $parts = ldap_explode_dn($dn, 0);
    if ($parts === FALSE) {
      return NULL;
    }
    $domain = [];
    foreach ($parts as $part) {
      if (is_string($part) && stripos($part, 'dc=') === 0) {
        $domain[] = $part;
      }
    }
    return empty($domain) ? NULL : implode(',', $domain);
  }

  public function syncRoles(int $userId, array $ldapAttrs): void {
    $raw = (string) (Civi::settings()->get('saldap_ldap_role_mappings') ?? '');
    if ($raw === '') {
      return;
    }

    $ldapGroups = $ldapAttrs['groups'] ?? [];
    $roleIds = [];

    foreach (explode("\n", $raw) as $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      $parts = explode('|', $line);
      if (count($parts) === 2) {
        $ldapGroup = trim($parts[0]);
        $roleId = (int) trim($parts[1]);
        if ($roleId && in_array($ldapGroup, $ldapGroups, TRUE)) {
          $roleIds[] = $roleId;
        }
      }
    }

    if (!empty($roleIds)) {
      try {
        UserApi::update(FALSE)
          ->addWhere('id', '=', $userId)
          ->addValue('roles', $roleIds)
          ->execute();
      }
      catch (\Exception $e) {
        $this->log(self::LEVEL_ERROR, 'Failed to sync roles for user {userId}: {error}', [
          'userId' => $userId,
          'error' => $e->getMessage(),
        ]);
      }
    }
  }

  private function checkGroupMembership(mixed $conn, array $userEntry, array $settings): bool {
    $groupDn = $settings['group_dn'];

    if (empty($groupDn)) {
      return TRUE;
    }

    $memberOf = [];
    if (isset($userEntry['memberof'])) {
      for ($i = 0; $i < $userEntry['memberof']['count']; $i++) {
        $memberOf[] = $userEntry['memberof'][$i];
      }
    }

    if (in_array($groupDn, $memberOf, TRUE)) {
      return TRUE;
    }

    $sr = @ldap_read($conn, $groupDn, '(objectClass=*)', ['member'], 0, 1, 0, LDAP_DEREF_NEVER);
    if ($sr !== FALSE) {
      $entries = @ldap_get_entries($conn, $sr);
      if ($entries !== FALSE && $entries['count'] > 0 && isset($entries[0]['member'])) {
        for ($i = 0; $i < $entries[0]['member']['count']; $i++) {
          if ($entries[0]['member'][$i] === $userEntry['dn']) {
            return TRUE;
          }
        }
      }
    }

    $this->log(self::LEVEL_DEBUG, 'User is not member of required group: {group_dn}', [
      'group_dn' => $groupDn,
    ]);
    return FALSE;
  }

  private function usesTls(array $settings): bool {
    return $settings['tls'] || $this->isLdapsUri($settings['host'], $settings['port']);
  }

  private function prefixScheme(string $host): string {
    if (str_contains($host, '://')) {
      return $host;
    }
    return 'ldap://' . $host;
  }

  private function isLdapsUri(string $host, int $port = 636): bool {
    return stripos($host, 'ldaps://') === 0 || $port === 636;
  }

  private function escapeLdapFilter(string $value): string {
    $replace = [
      '\\' => '\\5c',
      '*' => '\\2a',
      '(' => '\\28',
      ')' => '\\29',
      "\x00" => '\\00',
    ];
    return strtr($value, $replace);
  }

  private function log(string $level, string $message, array $context = []): void {
    $formatted = strtr($message, array_map(function ($v) {
      return (string) $v;
    }, $context));

    switch ($level) {
      case self::LEVEL_DEBUG:
        \Civi::log()->debug('[saldap] ' . $formatted);
        break;
      case self::LEVEL_INFO:
        \Civi::log()->info('[saldap] ' . $formatted);
        break;
      case self::LEVEL_WARNING:
        \Civi::log()->warning('[saldap] ' . $formatted);
        break;
      case self::LEVEL_ERROR:
        \Civi::log()->error('[saldap] ' . $formatted);
        break;
    }
  }

}
