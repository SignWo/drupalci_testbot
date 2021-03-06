<?php

namespace DrupalCI\Tests\Application;

use DrupalCI\Tests\DrupalCIFunctionalTestBase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Basic test that proves that drupalci can execute a simpletest and generate a result
 *
 * NOTE: This test assumes you have followed the setup instructions in TESTING.md
 *
 * @group Application
 * @group docker
 * @group Xml
 *
 * @see TESTING.md
 */
class CoreD8MySqlPassingTest extends DrupalCIFunctionalTestBase {

  /**
   * {@inheritdoc}
   */

  public function testBasicTest() {

    $options = ['interactive' => FALSE];
    $this->app_tester->run([
      'command' => 'run',
      'definition' => 'tests/DrupalCI/Tests/Application/Fixtures/build.CoreD8MySqlPassingTest.yml',
    ], $options);
    /* @var $build \DrupalCI\Build\BuildInterface */
    $build = $this->getCommand('run')->getBuild();
    $display = $this->app_tester->getDisplay();
    $this->assertRegExp('/.*Drupal\\\\KernelTests\\\\Core\\\\Routing\\\\UrlIntegrationTest*/', $this->app_tester->getDisplay());
    // Look for junit xml results file
    $output_file = $build->getArtifactDirectory() . "/run_tests.standard/junitxml/run_tests_results.xml";
    $this->assertFileExists($output_file);
    // create a test fixture that contains the xml output results.
    $this->assertXmlFileEqualsXmlFile(__DIR__ . '/Fixtures/CoreD8PassingTest_testresults.xml', $output_file);
    $this->assertEquals(0, $this->app_tester->getStatusCode());

    $this->assertBuildOutputJson($build, 'buildLabel', 'Build Successful');
    $this->assertBuildOutputJson($build, 'buildDetails', '');

    // Ensure that the PHP version was displayed.
    $this->assertRegExp('/PHP 7.0./', $this->app_tester->getDisplay());
    // Ensure that PHP info was generated.
    $this->assertRegExp('/php -i >/', $this->app_tester->getDisplay());
    $phpinfo_path = $build->getArtifactDirectory() . '/runcontainers/phpinfo.txt';
    $this->assertFileExists($phpinfo_path);
  }

}
