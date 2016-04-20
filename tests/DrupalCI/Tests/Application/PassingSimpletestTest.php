<?php

namespace DrupalCI\Tests\Application;

use DrupalCI\Tests\DrupalCIFunctionalTestBase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Basic test that proves that drupalci can execute a simpletest and generate a result
 *
 * NOTE: This test assumes you have checked out Drupal 8.1.x branch into a
 * directory called /tmp/drupal, using a command like this:
 * git clone --branch 8.1.x https://git.drupal.org/project/drupal.git
 *
 * @group Application
 * @group docker
 *
 * @see TESTING.md
 */
class PassingSimpletestTest extends DrupalCIFunctionalTestBase {

  /**
   * {@inheritdoc}
   */

  protected $dciConfig = [
    'DCI_UseLocalCodebase=/tmp/drupal',
    'DCI_JobType=simpletest',
    'DCI_CoreBranch=8.1.x',
    'DCI_TestGroups=Url',
    'DCI_JunitXml=xml',
    'DCI_ComposerInstall=true',
  ];

  private $dciConfigPhpVer = [
    'DCI_PHPVersion=5.5',
    'DCI_PHPVersion=5.6',
    'DCI_PHPVersion=7',
  ];
  private $dciConfigDb = [
    'DCI_DBVersion=mysql-5.5',
    'DCI_DBVersion=pgsql-9.1',
    'DCI_DBVersion=sqlite-3.8',
  ];

  private $container_id = array();
  public function testBasicTest() {
    foreach ($this->dciConfigDb as $dbKey) {
      array_push($this->dciConfig, $dbKey);
      foreach ($this->dciConfigPhpVer as $phpKey) {
        array_push($this->dciConfig, $phpKey);
        $this->setUp();

        $app = $this->getConsoleApp();
        $options = ['interactive' => FALSE];

        $app_tester = new ApplicationTester($app);
        $app_tester->run([
          'command' => 'run',
        ], $options);

        $display = $app_tester->getDisplay();
        $job = $this->getCommand('run')->getJob();
        
        $this->assertRegExp('/.*Drupal\\\\system\\\\Tests\\\\Routing\\\\UrlIntegrationTest*/', $app_tester->getDisplay());
        // Look for junit xml results file
        $output_file = $job->getJobCodebase()->getWorkingDir() . "/artifacts/" . $job->getBuildVars()["DCI_JunitXml"] . '/testresults.xml';
        $this->assertFileExists($output_file);

        // create a test fixture that contains the xml output results.
        //$this->assertFileEquals();
        $this->assertXmlFileEqualsXmlFile(__DIR__ . '/Fixtures/PassingSimpletestTest_testresults.xml', $output_file);

        array_pop($this->dciConfig);
      }

      array_pop($this->dciConfig);
    }
  }
}