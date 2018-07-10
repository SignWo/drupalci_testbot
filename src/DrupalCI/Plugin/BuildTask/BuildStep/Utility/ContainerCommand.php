<?php

namespace DrupalCI\Plugin\BuildTask\BuildStep\Utility;

use DrupalCI\Plugin\BuildTask\BuildStep\BuildStepInterface;
use DrupalCI\Plugin\BuildTask\BuildTaskInterface;
use DrupalCI\Plugin\BuildTask\BuildTaskException;
use Pimple\Container;

/**
 * @PluginID("container_command")
 */
class ContainerCommand extends Command implements BuildStepInterface, BuildTaskInterface {

  /**
   * The testing environment.
   *
   * @var \DrupalCI\Build\Environment\EnvironmentInterface
   */
  protected $environment;

  /**
   * {@inheritdoc}
   */
  public function inject(Container $container) {
    $this->environment = $container['environment'];
    parent::inject($container);
  }

  /**
   * {@inheritdoc}
   */
  public function complete($childStatus) {

    foreach ($this->configuration['artifacts'] as $artifact) {
      // Save any defined artifacts at the end
      $artifact['source'] = str_replace('${SOURCE_DIR}', $this->environment->getExecContainerSourceDir(), $artifact['source']);
      $artifact['source'] = str_replace('${PROJECT_DIR}', $this->codebase->getProjectSourceDirectory(), $artifact['source']);
      $this->saveContainerArtifact($artifact['source'], $artifact['destination']);
    }

  }

  /**
   * Execute the commands in the PHP container.
   *
   * @param string[] $commands
   * @param bool $die_on_fail
   *
   * @todo: Explicitly set the container in executeCommands().
   * @return int
   * @throws \DrupalCI\Plugin\BuildTask\BuildTaskException
   */
  protected function execute($commands, $die_on_fail) {
    // Set some environment variables for these executions.
    $this->command_environment[] = "SOURCE_DIR={$this->environment->getExecContainerSourceDir()}";
    $this->command_environment[] = "PROJECT_DIR={$this->codebase->getProjectSourceDirectory()}";

    if ($die_on_fail) {
      $result = $this->execRequiredEnvironmentCommands($commands, 'Custom Commands Failed');
    }
    else {
      $result = $this->execEnvironmentCommands($commands);
    }
    // exedRequiredComannds should terminate The build further down if there's
    // an error. And since we have no idea what to do with a custom command
    // that isnt required, we'll just return 0 at this point.
    // Maybe this should return $result->getSignal() instead and make sure
    // devs know about 0, 1, and 2 ?
    return 0;
  }
}
