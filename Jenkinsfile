node('master') {
  def buildName = "saldap_build_${BUILD_NUMBER}"
  def buildkitDir = "${WORKSPACE}/buildkit"

  try {
    stage('Checkout') {
      checkout scm
    }

    stage('Resolve versions') {
      def infoXml = readFile('info.xml')
      def civicrmVersion = (infoXml =~ /<compatibility[^>]*>.*?<ver>([^<]+)<\/ver>.*?<\/compatibility>/s)
        .findResult { it[1] } ?: '6.13'
      def phpVersions = (infoXml =~ /<php_compatibility[^>]*>.*?<ver>([^<]+)<\/ver>.*?<\/php_compatibility>/s)
        .findResult { it[1] } ?: '8.2'
      def phpVersion = phpVersions.split(/[,\s]+/).last()

      env.CIVICRM_VERSION = params.CIVICRM_VERSION ?: civicrmVersion
      env.PHP_VERSION = params.PHP_VERSION ?: phpVersion

      echo "Using CiviCRM ${env.CIVICRM_VERSION}, PHP ${env.PHP_VERSION}"
    }

    stage('Install buildkit') {
      dir(buildkitDir) {
        sh 'git clone https://github.com/civicrm/civicrm-buildkit.git .'
        sh 'composer install --no-interaction'
      }
    }

    stage('Create CiviCRM build') {
      dir(buildkitDir) {
        sh "civibuild create ${buildName} --type standalone --version ${env.CIVICRM_VERSION} --php ${env.PHP_VERSION} --civi-ver ${env.CIVICRM_VERSION}"
      }
    }

    stage('Install extension') {
      def siteDir = "${buildkitDir}/build/${buildName}/sites/default"
      dir(siteDir) {
        sh 'cv ext:enable de_cipico_saldap'
      }
    }

    stage('Run PHPUnit tests') {
      def extDir = "${buildkitDir}/build/${buildName}/sites/default/ext/de_cipico_saldap"
      dir(extDir) {
        sh 'env CIVICRM_UF=UnitTests phpunit8 tests/phpunit/Api4/SaldapTest.php'
      }
    }
  }
  catch (Exception e) {
    echo "Pipeline failed: ${e.message}"
    currentBuild.result = 'FAILURE'
  }
  finally {
    junit allowEmptyResults: true, testResults: '**/phpunit*.xml'
    dir(buildkitDir) {
      sh "civibuild destroy ${buildName} || true"
    }
  }
}
