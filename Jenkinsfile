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

        def saldapVersion = '0.0.0'
        def vMatcher = infoXml =~ '<version>([^<]+)<\\/version>'
        if (vMatcher.find()) {
          saldapVersion = vMatcher.group(1)
        }

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

        env.SALDAP_VERSION = params.SALDAP_VERSION ?: saldapVersion
        env.CIVICRM_VERSION = params.CIVICRM_VERSION ?: civicrmVersion
        env.PHP_VERSION = params.PHP_VERSION ?: phpVersion
        echo "Saldap ${env.SALDAP_VERSION}, CiviCRM ${env.CIVICRM_VERSION}, PHP ${env.PHP_VERSION}"
      }

      stage('Lint PHP') {
        docker.image(baseImage).inside(
          "--entrypoint /usr/bin/env -e HOME=/tmp"
        ) {
          sh "find ${WORKSPACE} -name '*.php' -not -path '*/vendor/*' -not -path '*/buildkit/*' -not -path '*/.git/*' -exec php -l {} \\; 2>&1 | grep -E '^(Parse|Fatal) error' && exit 1 || echo 'All PHP files passed syntax check'"
        }
      }

      stage('Integration tests') {
        def cachedImage = "saldap-civibuild:php${env.PHP_VERSION}-civi${env.CIVICRM_VERSION}"
        def extDir = "/buildkit/build/${cachedBuildName}/web/ext/de_cipico_saldap"
        def ampDir = "${WORKSPACE}/.amp"

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

        // Check for cached CiviCRM build image
        def cacheHit = sh(script: "docker image inspect ${cachedImage} >/dev/null 2>&1", returnStatus: true) == 0
        echo "Cached image ${cachedImage}: ${cacheHit ? 'HIT' : 'MISS'}"

        // Start MySQL
        sh "docker rm -f saldap-mysql-${BUILD_NUMBER} saldap-app-${BUILD_NUMBER} 2>/dev/null; true"
        sh "docker run -d --name saldap-mysql-${BUILD_NUMBER} -e MYSQL_ROOT_PASSWORD=buildkit --tmpfs /var/lib/mysql ${mysqlImage}"

        // Start app container (from cached image if available, else base)
        sh "docker volume inspect saldap-git-cache >/dev/null 2>&1 || docker volume create saldap-git-cache"
        sh "docker volume inspect saldap-composer-cache >/dev/null 2>&1 || docker volume create saldap-composer-cache"

        def appImage = cacheHit ? cachedImage : baseImage
        sh "docker run -d --name saldap-app-${BUILD_NUMBER} \\" +
          "--link saldap-mysql-${BUILD_NUMBER}:mysql \\" +
          "--entrypoint /usr/bin/env \\" +
          "-e HOME=/tmp -e AMPHOME=${ampDir} \\" +
          "-v ${WORKSPACE}:${WORKSPACE}:rw \\" +
          "-v saldap-git-cache:/buildkit/app/tmp/git-cache \\" +
          "-v saldap-composer-cache:/buildkit/.composer \\" +
          "${appImage} tail -f /dev/null"

        def dockerPrefix = "docker exec -u 0:0 saldap-app-${BUILD_NUMBER}"
        def buildDir = "/buildkit/build/${cachedBuildName}"

        try {
          sh "${dockerPrefix} git config --global --add safe.directory '*'"

          if (!cacheHit) {
            echo '=== Creating CiviCRM build (cache MISS) ==='
            sh "${dockerPrefix} civibuild create ${cachedBuildName} --type standalone-clean --civi-ver ${env.CIVICRM_VERSION} --url http://localhost --force"
            echo '=== Committing cached image ==='
            sh "docker commit saldap-app-${BUILD_NUMBER} ${cachedImage}"
          }
          else {
            echo '=== Using cached CiviCRM build (cache HIT) ==='
          }

          def ciSettings = "${buildDir}/web/private/civicrm.settings.php"

          echo '=== Symlinking extension ==='
          sh "${dockerPrefix} ln -sf ${WORKSPACE} ${extDir}"

          echo '=== Verifying settings file ==='
          sh "docker exec saldap-app-${BUILD_NUMBER} test -f ${ciSettings} || docker exec saldap-app-${BUILD_NUMBER} sh -c 'find ${buildDir} -name civicrm.settings.php 2>/dev/null'"

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
