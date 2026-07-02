<?php
declare(strict_types = 1);

use CRM_DeCipicoSaldap_ExtensionUtil as E;
use Civi\Core\Service\AutoService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Civi\Authx\CheckCredentialEvent;
use Civi\Standalone\Event\LoginEvent;

/**
 * @service LdapAuthenticator
 */
class CRM_DeCipicoSaldap_LdapAuthenticator extends AutoService implements EventSubscriberInterface {

  const PRIORITY_LDAP = -150;

  public static function getSubscribedEvents(): array {
    return [
      'civi.authx.checkCredential' => [
        ['ldapAuthenticate', self::PRIORITY_LDAP],
      ],
      'civi.standalone.login' => 'onWebLogin',
    ];
  }

  /**
   * AuthX login (API, HTTP headers, JWT, etc.)
   */
  public function ldapAuthenticate(CheckCredentialEvent $check): void {
    if ($check->credFormat !== 'Basic') {
      return;
    }

    $decoded = base64_decode($check->credValue, TRUE);
    if ($decoded === FALSE || !str_contains($decoded, ':')) {
      return;
    }

    [$username, $password] = explode(':', $decoded, 2);

    if (empty($username) || empty($password)) {
      return;
    }

    $this->tryLdapAndAccept($username, $password, $check);
  }

  /**
   * Web login form (civi.standalone.login event).
   *
   * Tries LDAP auth when the user has no local password hash
   * or when the username doesn't exist locally yet.
   */
  public function onWebLogin(LoginEvent $event): void {
    if ($event->stage !== 'pre_credentials_check') {
      return;
    }

    $password = $_POST['password'] ?? $_REQUEST['password'] ?? '';
    $username = $_POST['username'] ?? $_POST['identifier'] ?? $_REQUEST['username'] ?? '';
    if (empty($username) || empty($password)) {
      return;
    }

    // If user already exists and has a password hash, skip LDAP
    if ($event->userID) {
      $user = \Civi\Api4\User::get(FALSE)
        ->addWhere('id', '=', $event->userID)
        ->addSelect('hashed_password')
        ->execute()
        ->first();
      if ($user && !empty($user['hashed_password'])) {
        return;
      }
    }

    $ldap = new CRM_DeCipicoSaldap_Ldap();
    $ldapAttrs = $ldap->authenticate($username, $password);

    if ($ldapAttrs === NULL) {
      return;
    }

    // This creates or updates the local user with the password hash,
    // so the subsequent Security::checkPassword() call will succeed.
    $ldap->findOrCreateUser($username, $ldapAttrs, $password);
  }

  /**
   * Shared LDAP auth logic: authenticate, create/update user, accept credential.
   */
  private function tryLdapAndAccept(string $username, string $password, ?CheckCredentialEvent $check = NULL): void {
    $ldap = new CRM_DeCipicoSaldap_Ldap();
    $ldapAttrs = $ldap->authenticate($username, $password);

    if ($ldapAttrs === NULL) {
      return;
    }

    $userId = $ldap->findOrCreateUser($username, $ldapAttrs, $password);
    if ($userId !== NULL) {
      $ldap->syncRoles($userId, $ldapAttrs);
      if ($check) {
        $check->accept(['userId' => $userId, 'credType' => 'pass']);
      }
    }
  }

}
