def imageName = 'michaelmcandrew/civicrm-buildkit:php8.2'

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
    def mysqlArgs = "-e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=civicrm" +
      " -e MYSQL_USER=civicrm -e MYSQL_PASSWORD=civicrm --tmpfs /var/lib/mysql"
    docker.image('mysql:8.0').withRun(mysqlArgs) { mysql ->
      docker.image(imageName).inside("--link ${mysql.id}:mysql --entrypoint='' -u 0") {
        def buildName = "saldap_build_${BUILD_NUMBER}"
        def extDir = "/opt/buildkit/build/${buildName}/sites/default/ext/de_cipico_saldap"

        sh "civibuild create ${buildName} --type standalone" +
          " --version ${env.CIVICRM_VERSION} --php ${env.PHP_VERSION}" +
          " --civi-ver ${env.CIVICRM_VERSION} --url http://localhost" +
          " --db mysql://civicrm:civicrm@mysql/civicrm"

        sh "ln -sf ${WORKSPACE} ${extDir}"

        dir("/opt/buildkit/build/${buildName}/sites/default") {
          sh "cv ext:enable de_cipico_saldap"
        }

        dir(extDir) {
          sh 'env CIVICRM_UF=UnitTests phpunit8 tests/phpunit/Api4/SaldapTest.php'
        }

        sh "civibuild destroy ${buildName} || true"
      }
    }
  }
}
