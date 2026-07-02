def imageName = 'michaelmcandrew/civicrm-buildkit:php8.2'
def mysqlImage = 'michaelmcandrew/civicrm-mysql:8.0'

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
        echo "Using CiviCRM ${env.CIVICRM_VERSION}, PHP ${env.PHP_VERSION}"
      }

      stage('Integration tests') {
        def buildName = "saldap_build_${BUILD_NUMBER}"
        def extDir = "/buildkit/build/${buildName}/web/ext/de_cipico_saldap"
        def ampDir = "${WORKSPACE}/.amp"

        // Write amp config to workspace (writable by Jenkins user)
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

        docker.image(mysqlImage).withRun('-e MYSQL_ROOT_PASSWORD=buildkit --tmpfs /var/lib/mysql') { mysql ->
          docker.image(imageName).inside(
            "--link ${mysql.id}:mysql --entrypoint /usr/bin/env -e AMPHOME=${ampDir}"
          ) {
            try {
              sh 'git config --global --add safe.directory "*"'

              echo '=== Creating CiviCRM build ==='
              sh "civibuild create ${buildName} --type standalone-clean" +
                " --civi-ver ${env.CIVICRM_VERSION} --url http://localhost --force"

              echo '=== Symlinking extension ==='
              sh "ln -sf ${WORKSPACE} ${extDir}"

              echo '=== Enabling extension ==='
              sh "cv ext:enable de_cipico_saldap"

              echo '=== Running PHPUnit tests ==='
              dir("${extDir}") {
                sh 'env CIVICRM_UF=UnitTests phpunit8 tests/phpunit/Api4/SaldapTest.php --log-junit phpunit-report.xml'
              }

              echo '=== Tests completed successfully ==='
            }
            catch (Exception e) {
              echo "Test stage failed: ${e.message}"
              currentBuild.result = 'FAILURE'
            }
            finally {
              echo '=== Cleaning up build ==='
              sh "civibuild destroy ${buildName} || true"
            }
          }
        }
      }

      stage('Archive results') {
        junit allowEmptyResults: true, testResults: '**/phpunit*.xml'
      }
    }
  }
}
