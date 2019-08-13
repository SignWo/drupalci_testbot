<?php

namespace DrupalCI\Tests\Plugin\BuildTask\BuildStep\CodebaseAssemble;

use DrupalCI\Build\Codebase\CodebaseInterface;
use DrupalCI\Build\Environment\EnvironmentInterface;
use DrupalCI\Plugin\BuildTask\BuildStep\CodebaseAssemble\UpdateDependencies;
use DrupalCI\Tests\DrupalCITestCase;

/**
 * @coversDefaultClass \DrupalCI\Plugin\BuildTask\BuildStep\CodebaseAssemble\UpdateDependencies
 *
 * @group Plugin
 */
class UpdateDependenciesTest extends DrupalCITestCase {

  public function providerTestRunDumpautoload() {
    return [
      [FALSE, []],
      [FALSE, ['anything', 'at', 'all', 'not', 'composer-related']],
      [FALSE, ['core/composer.json']],
      [TRUE, ['composer.json']],
      [FALSE, ['composer.lock']],
      [FALSE, ['composer.json', 'composer.lock']],
    ];
  }

  /**
   * @dataProvider providerTestRunDumpautoload
   * @covers ::run
   */
  public function testRunDumpautoload($will_dumpautoload, $modified_files) {

    $codebase = $this->getMockBuilder(CodebaseInterface::class)
      ->setMethods(['getModifiedFiles'])
      ->getMockForAbstractClass();
    $codebase->expects($this->once())
      ->method('getModifiedFiles')
      ->willReturn($modified_files);

    $environment = $this->getMockBuilder(EnvironmentInterface::class)
      ->setMethods(['getExecContainerSourceDir'])
      ->getMockForAbstractClass();
    $environment->expects($this->once())
      ->method('getExecContainerSourceDir')
      ->willReturn('command/will/contain/this/path');

    $update_dependencies = $this->getMockBuilder(UpdateDependencies::class)
      ->setMethods(['execRequiredEnvironmentCommands'])
      ->getMock();
    // Mock execRequiredEnvironmentCommands() so we don't actually perform any
    // reinstalls.
    $cmd = $will_dumpautoload ?
      'sudo -u www-data /usr/local/bin/composer dumpautoload --profile --no-interaction --working-dir command/will/contain/this/path' :
      'sudo -u www-data /usr/local/bin/composer install --prefer-dist --no-suggest --no-interaction --no-progress --working-dir command/will/contain/this/path';
    $update_dependencies->expects($this->any())
      ->method('execRequiredEnvironmentCommands')
      ->with($cmd);

    $update_dependencies->inject($this->getContainer([
      'codebase' => $codebase,
      'environment' => $environment,
    ]));

    $this->assertEquals(0, $update_dependencies->run());
  }

}
