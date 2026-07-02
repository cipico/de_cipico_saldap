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
      'civi.api.prepare' => 'onApiPrepare',
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
   * Intercept User::login API to ensure LDAP users exist locally
   * before the login action looks them up.
   */
  public function onApiPrepare(\Civi\API\Event\PrepareEvent $event): void {
    $apiRequest = $event->getApiRequest();
    if (!is_object($apiRequest) || !class_exists('Civi\Api4\Action\User\Login')) {
      return;
    }
    if (!$apiRequest instanceof \Civi\Api4\Action\User\Login) {
      return;
    }

    $username = $apiRequest->getIdentifier();
    $password = $apiRequest->getPassword();

    if (empty($username) || empty($password)) {
      return;
    }

    // Try LDAP auth
    $ldap = new CRM_DeCipicoSaldap_Ldap();
    $ldapAttrs = $ldap->authenticate($username, $password);

    if ($ldapAttrs === NULL) {
      return;
    }

    // Create or update local user with password hash
    $userId = $ldap->findOrCreateUser($username, $ldapAttrs, $password);
    if ($userId !== NULL) {
      $ldap->syncRoles($userId, $ldapAttrs);
    }
  }

  /**
   * Shared LDAP auth logic.
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
