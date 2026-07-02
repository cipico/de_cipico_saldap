<?php
declare(strict_types=1);

namespace Civi\Api4\Action\Saldap;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Get all current LDAP configuration settings.
 *
 * Returns all configured LDAP settings as a single result row.
 *
 * Usage:
 *
 *   cv api4 Saldap.get
 *
 *   cv api4 Saldap.get '{"select": ["saldap_ldap_host", "saldap_ldap_port"]}'
 */
class Get extends AbstractAction {

  public function _run(Result $result): void {
    $settings = \Civi::settings();
    $row = [];
    foreach (Set::settingNames() as $name) {
      $row[$name] = $settings->get($name);
    }
    $result[] = $row;
  }

}
