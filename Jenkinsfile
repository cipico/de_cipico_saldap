def baseImage = 'michaelmcandrew/civicrm-buildkit:php8.2'
def mysqlImage = 'michaelmcandrew/civicrm-mysql:8.0'
def cachedBuildName = 'saldap_cache'

properties([
  parameters([
    string(name: 'CIVICRM_VERSION', defaultValue: '', description: 'Override CiviCRM version (default: from info.xml)'),
    string(name: 'PHP_VERSION', defaultValue: '', description: 'Override PHP version (default: from info.xml)'),
  ])
])

timestamps {
  timeout(time: 30, unit: 'MINUTES') {
    node('master') {
      stage('Checkout') {
        checkout scm
      }

      stage('Resolve versions') {
        def infoXml = readFile('info.xml')

        def civicrmVersion = '6.13'
        def matcher = infoXml =~ '<compatibility[^>]*>.*?<ver>([^<]+)<\\/ver>'
        if (matcher.find()) {
          civicrmVersion = matcher.group(1)
        }

        def phpVersion = '8.2'
        def phpMatcher = infoXml =~ '<php_compatibility[^>]*>.*?<ver>([^<]+)<\\/ver>'
        def allPhp = []
        while (phpMatcher.find()) {
          allPhp.push(phpMatcher.group(1))
        }
        if (!allPhp.isEmpty()) {
          phpVersion = allPhp.last()
        }

        env.CIVICRM_VERSION = params.CIVICRM_VERSION ?: civicrmVersion
        env.PHP_VERSION = params.PHP_VERSION ?: phpVersion
        echo "CiviCRM ${env.CIVICRM_VERSION}, PHP ${env.PHP_VERSION}"
      }

      stage('Lint PHP') {
        docker.image(baseImage).inside(
          "--entrypoint /usr/bin/env -e HOME=/tmp"
        ) {
          sh "find ${WORKSPACE} -name '*.php' -not -path '*/vendor/*' -not -path '*/buildkit/*' -not -path '*/.git/*' -exec php -l {} \\; 2>&1 | grep -E '^(Parse|Fatal) error' && exit 1 || echo 'All PHP files passed syntax check'"
        }
      }

      stage('Integration tests') {
        def ampDir = "${WORKSPACE}/.amp"
        def cacheDir = "${WORKSPACE}/.civibuild-cache"
        def cacheKey = "php${env.PHP_VERSION}-civi${env.CIVICRM_VERSION}"
        def cacheFile = "${cacheDir}/${cacheKey}.tar.gz"
        def extDir = "/buildkit/build/${cachedBuildName}/web/ext/de_cipico_saldap"
        def buildDir = "/buildkit/build/${cachedBuildName}"

        writeFile file: "${ampDir}/services.yml", text: """\
parameters:
    version: 2
    db_type: mysql_dsn
    mysql_dsn: "mysql://root:buildkit@mysql:3306"
    perm_type: none
    perm_user: www-data
    hosts_type: file
    httpd_type: apache24
    httpd_visibility: all
    httpd_shared_ports: "7890"
    httpd_restart_command: "true"
services: {  }
"""

        // Check for cached build
        def cacheHit = fileExists(cacheFile)
        echo "Civibuild cache ${cacheKey}: ${cacheHit ? 'HIT' : 'MISS'}"

        sh "docker rm -f saldap-mysql-${BUILD_NUMBER} saldap-app-${BUILD_NUMBER} 2>/dev/null; true"
        sh "docker run -d --name saldap-mysql-${BUILD_NUMBER} -e MYSQL_ROOT_PASSWORD=buildkit --tmpfs /var/lib/mysql ${mysqlImage}"

        sh "docker volume inspect saldap-git-cache >/dev/null 2>&1 || docker volume create saldap-git-cache"
        sh "docker volume inspect saldap-composer-cache >/dev/null 2>&1 || docker volume create saldap-composer-cache"

        sh "docker run -d --name saldap-app-${BUILD_NUMBER} \\" +
          "--link saldap-mysql-${BUILD_NUMBER}:mysql \\" +
          "--entrypoint /usr/bin/env \\" +
          "-e HOME=/tmp -e AMPHOME=${ampDir} \\" +
          "-v ${WORKSPACE}:${WORKSPACE}:rw \\" +
          "-v saldap-git-cache:/buildkit/app/tmp/git-cache \\" +
          "-v saldap-composer-cache:/buildkit/.composer \\" +
          "${baseImage} tail -f /dev/null"

        def dockerPrefix = "docker exec -u 0:0 saldap-app-${BUILD_NUMBER}"

        try {
          // Wait for MySQL TCP to be ready (up to 45s)
          sh "for i in \$(seq 1 45); do docker exec saldap-mysql-${BUILD_NUMBER} mysqladmin ping -h 127.0.0.1 -u root -pbuildkit --silent 2>/dev/null && break; sleep 1; done"

          sh "${dockerPrefix} git config --global --add safe.directory '*'"

          if (!cacheHit) {
            echo '=== Creating CiviCRM build (cache MISS) ==='
            sh "${dockerPrefix} civibuild create ${cachedBuildName} --type standalone-clean --civi-ver ${env.CIVICRM_VERSION} --url http://localhost --force"

            echo '=== Saving build cache ==='
            sh "mkdir -p ${cacheDir}"
            sh "${dockerPrefix} tar czf ${WORKSPACE}/.civibuild-cache/${cacheKey}.tar.gz -C /buildkit/build ${cachedBuildName}"

            echo '=== Caching DB snapshots ==='
            sh "${dockerPrefix} tar czf ${WORKSPACE}/.civibuild-cache/${cacheKey}-snapshots.tar.gz -C /buildkit/app/snapshot ${cachedBuildName}"
          }
          else {
            echo '=== Restoring cached CiviCRM build (cache HIT) ==='
            sh "${dockerPrefix} mkdir -p /buildkit/build /buildkit/app/snapshot"
            sh "${dockerPrefix} tar xzf ${cacheFile} -C /buildkit/build"
            sh "${dockerPrefix} tar xzf ${cacheDir}/${cacheKey}-snapshots.tar.gz -C /buildkit/app/snapshot"

            echo '=== Restoring DB users and data ==='
            // Extract DSNs from settings, create DB users and databases on MySQL
            sh """docker exec saldap-app-${BUILD_NUMBER} php << 'SCRIPT'
<?php
require '${buildDir}/web/private/civicrm.settings.php';
\$pdo = new PDO('mysql:host=mysql', 'root', 'buildkit');
foreach (['CMS_DB_DSN', 'CIVI_DB_DSN', 'TEST_DB_DSN'] as \$k) {
  \$d = parse_url(\$GLOBALS['_CV'][\$k]);
  \$db = ltrim(\$d['path'], '/');
  \$u = \$d['user'];
  \$p = \$d['pass'];
  \$pdo->exec("CREATE USER IF NOT EXISTS \$u@'%' IDENTIFIED BY '\$p'");
  \$pdo->exec('CREATE DATABASE IF NOT EXISTS ' . \$db);
  \$pdo->exec("GRANT ALL ON " . \$db . ".* TO \$u@'%'");
  echo "OK \$u@\$db\n";
}
\$pdo->exec('FLUSH PRIVILEGES');
SCRIPT"""
            sh "docker exec saldap-app-${BUILD_NUMBER} zcat /buildkit/app/snapshot/${cachedBuildName}/civi.sql.gz | mysql -h mysql -u root -pbuildkit"
            echo 'Restore complete'

          def ciSettings = "${buildDir}/web/private/civicrm.settings.php"

          echo '=== Symlinking extension ==='
          sh "${dockerPrefix} ln -sf ${WORKSPACE} ${extDir}"

          echo '=== Enabling extension ==='
          sh "docker exec -u 0:0 -e CIVICRM_SETTINGS=${ciSettings} -w ${buildDir}/web saldap-app-${BUILD_NUMBER} cv ext:enable de_cipico_saldap"

          echo '=== Running PHPUnit tests ==='
          sh "docker exec -u 0:0 -e CIVICRM_SETTINGS=${ciSettings} -e CIVICRM_UF=UnitTests -w ${extDir} saldap-app-${BUILD_NUMBER} php /buildkit/extern/phpunit8/phpunit8.phar --configuration ${extDir}/phpunit.xml.dist ${extDir}/tests/phpunit/Api4/SaldapTest.php --log-junit ${WORKSPACE}/saldap-test-report.xml"

          echo '=== Tests completed successfully ==='
        }
        catch (Exception e) {
          echo "Test stage failed: ${e.message}"
          currentBuild.result = 'FAILURE'
        }
        finally {
          echo '=== Cleaning up build ==='
          sh "docker rm -f saldap-app-${BUILD_NUMBER} saldap-mysql-${BUILD_NUMBER} || true"
        }
      }

      stage('Archive results') {
        junit allowEmptyResults: true, testResults: '**/saldap-test-report.xml'
      }
    }
  }
}
