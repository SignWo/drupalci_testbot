<?php

namespace DrupalCI\Plugin\BuildTask\BuildStep\CodebaseValidate;

use DrupalCI\Plugin\BuildTask\BuildStep\CodebaseAssemble\Composer;
use Pimple\Container;

/**
 * Runs Composer inside the container.
 *
 * Subclass the other composer class, so we inherit default config.
 *
 * @PluginID("container_composer")
 */
class ContainerComposer extends Composer {

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
    parent::inject($container);
    $this->environment = $container['environment'];
    $this->codebase = $container['codebase'];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultConfiguration() {
    return array_merge(
      parent::getDefaultConfiguration(),
      [
        'executable_path' => '/usr/local/bin/composer',
      ]
     );
  }

  /**
   * @inheritDoc
   */
  public function run() {
    $this->io->writeln('<info>Running Composer install within the environment.</info>');

    // Build a containerized Composer command.
    $command = [
      $this->configuration['executable_path'],
      $this->configuration['options'],
      '--working-dir ' . $this->environment->getExecContainerSourceDir(),
    ];

    $result = $this->environment->executeCommands(implode(' ', $command));

    if ($result->getSignal() != 0) {
      $this->terminateBuild('Composer error. Unable to continue.');
    }

    return 0;
  }

}
