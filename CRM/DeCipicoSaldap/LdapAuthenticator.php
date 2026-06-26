<?php
declare(strict_types = 1);

use CRM_DeCipicoSaldap_ExtensionUtil as E;
use Civi\Core\Service\AutoService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Civi\Authx\CheckCredentialEvent;

/**
 * @service LdapAuthenticator
 */
class CRM_DeCipicoSaldap_LdapAuthenticator extends AutoService implements EventSubscriberInterface {

  /**
   * Priority higher than CheckCredential::basicUser (-200)
   * so LDAP is tried before local password check.
   */
  const PRIORITY_LDAP = -150;

  public static function getSubscribedEvents(): array {
    return [
      'civi.authx.checkCredential' => [
        ['ldapAuthenticate', self::PRIORITY_LDAP],
      ],
    ];
  }

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

    $ldap = new CRM_DeCipicoSaldap_Ldap();
    $ldapAttrs = $ldap->authenticate($username, $password);

    if ($ldapAttrs === NULL) {
      return;
    }

    $userId = $ldap->findOrCreateUser($username, $ldapAttrs, $password);
    if ($userId !== NULL) {
      $ldap->syncRoles($userId, $ldapAttrs);
      $check->accept(['userId' => $userId, 'credType' => 'pass']);
    }
  }

}
