<?php

namespace DrupalCI\Tests\Application;

use DrupalCI\Tests\DrupalCIFunctionalTestBase;

/**
 * Tests that a remote build definition can be used to run a test build.
 *
 * NOTE: This test assumes you have followed the setup instructions in TESTING.md
 *
 * @group Application
 *
 * @see TESTING.md
 *
 */
class ContribD8RemoteBuildTest extends DrupalCIFunctionalTestBase {

  public function testContribD8RemoteBuild() {

    $options = ['interactive' => FALSE];
    $this->app_tester->run([
      'command' => 'run',
      'definition' => 'https://www.drupal.org/files/issues/2018-05-01/build.jenkins-drupal_contrib-190687.yml',
    ], $options);
    $display = $this->app_tester->getDisplay();
    $this->assertRegExp('!Build downloaded to /var/lib/drupalci/workspace/build.jenkins-drupal_contrib-190687.yml!', $this->app_tester->getDisplay());
    $this->assertRegExp('!Drupal\\\\Tests\\\\block_field\\\\Functional\\\\BlockFieldTest!', $this->app_tester->getDisplay());
    $this->assertEquals(0, $this->app_tester->getStatusCode());

    /* @var $build \DrupalCI\Build\BuildInterface */
    $build = $this->getContainer()['build'];
    $this->assertBuildOutputJson($build, 'buildLabel', 'Build Successful');
    $this->assertBuildOutputJson($build, 'buildDetails', '');
  }

}
