def baseImage = 'michaelmcandrew/civicrm-buildkit:php8.2'
def mysqlImage = 'michaelmcandrew/civicrm-mysql:8.0'
def buildName = "saldap_build_${BUILD_NUMBER}"

properties([
  parameters([
    string(name: 'CIVICRM_VERSION', defaultValue: '', description: 'Override CiviCRM version (default: from info.xml)'),
    string(name: 'PHP_VERSION', defaultValue: '', description: 'Override PHP version (default: from info.xml)'),
  ])
])

timestamps {
    timeout(time: 30, unit: 'MINUTES') {
      // PHP linting and integration tests use Docker throughout. Run the
      // complete pipeline on the isolated Docker/DinD agent.
      node('container-build') {
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
        def extDir = "/buildkit/build/${buildName}/web/ext/de_cipico_saldap"

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
          sh "for i in \$(seq 1 45); do docker exec saldap-mysql-${BUILD_NUMBER} mysqladmin ping -h 127.0.0.1 -u root -pbuildkit --silent 2>/dev/null && break; sleep 1; done"

          sh "${dockerPrefix} git config --global --add safe.directory '*'"

          echo '=== Creating CiviCRM build ==='
          sh "${dockerPrefix} civibuild create ${buildName} --type standalone-clean --civi-ver ${env.CIVICRM_VERSION} --url http://localhost --force"

          def ciSettings = "/buildkit/build/${buildName}/web/private/civicrm.settings.php"

          echo '=== Symlinking extension ==='
          sh "${dockerPrefix} ln -sf ${WORKSPACE} ${extDir}"

          echo '=== Enabling extension ==='
          sh "docker exec -u 0:0 -e CIVICRM_SETTINGS=${ciSettings} -w /buildkit/build/${buildName}/web saldap-app-${BUILD_NUMBER} cv ext:enable de_cipico_saldap"

          echo '=== Running PHPUnit tests ==='
          sh "docker exec -u 0:0 -e CIVICRM_SETTINGS=${ciSettings} -e CIVICRM_UF=UnitTests -w ${extDir} saldap-app-${BUILD_NUMBER} php /buildkit/extern/phpunit8/phpunit8.phar --verbose --configuration ${extDir}/phpunit.xml.dist ${extDir}/tests/phpunit/Api4/SaldapTest.php --log-junit ${WORKSPACE}/saldap-test-report.xml 2>&1"

          echo '=== Tests completed ==='
        }
        catch (Exception e) {
          echo "Test stage failed: ${e.message}"
          currentBuild.result = 'FAILURE'
        }
        finally {
          echo '=== Cleaning up build ==='
          sh "docker exec -u 0:0 saldap-app-${BUILD_NUMBER} civibuild destroy ${buildName} --force || true"
          sh "docker rm -f saldap-app-${BUILD_NUMBER} saldap-mysql-${BUILD_NUMBER} || true"
        }
      }

      stage('Archive results') {
        junit allowEmptyResults: true, testResults: '**/saldap-test-report.xml'
      }
    }
  }
}
