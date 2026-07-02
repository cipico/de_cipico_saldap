<?php
declare(strict_types=1);

namespace Civi\Api4;

use Civi\Api4\Generic\AbstractEntity;
use Civi\Api4\Generic\BasicGetFieldsAction;

/**
 * Saldap - LDAP Server Configuration.
 *
 * Configure all LDAP authentication settings in a single call.
 *
 * @method static \Civi\Api4\Action\Saldap\Get get()
 * @method static \Civi\Api4\Action\Saldap\Set set()
 */
class Saldap extends AbstractEntity {

  public static function actions(): array {
    return [
      'get' => \Civi\Api4\Action\Saldap\Get::class,
      'set' => \Civi\Api4\Action\Saldap\Set::class,
    ];
  }

  public static function permissions(): array {
    return [
      'meta' => ['access CiviCRM'],
      'default' => ['administer CiviCRM'],
    ];
  }

  public static function getFields(): BasicGetFieldsAction {
    return new BasicGetFieldsAction(static::getEntityName(), __FUNCTION__, function () {
      $fields = [];
      foreach (\Civi\Api4\Action\Saldap\Set::settingNames() as $name) {
        $meta = \Civi\Api4\Action\Saldap\Set::settingMeta($name);
        $fields[] = [
          'name' => $name,
          'title' => $meta['title'] ?? $name,
          'description' => $meta['description'] ?? '',
          'data_type' => $meta['data_type'] ?? 'String',
          'required' => FALSE,
          'default' => $meta['default'] ?? NULL,
        ];
      }
      return $fields;
    });
  }

}
