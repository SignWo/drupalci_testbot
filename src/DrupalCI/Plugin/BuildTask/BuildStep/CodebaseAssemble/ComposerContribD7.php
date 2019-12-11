<?php

namespace DrupalCI\Plugin\BuildTask\BuildStep\CodebaseAssemble;

use Composer\Json\JsonFile;
use DrupalCI\Plugin\BuildTask\BuildStep\BuildStepInterface;
use DrupalCI\Plugin\BuildTask\BuildTaskInterface;

/**
 * @PluginID("composer_contrib_d7")
 */
class ComposerContribD7 extends ComposerContrib implements BuildStepInterface, BuildTaskInterface {

  /* @var \DrupalCI\Build\Codebase\CodebaseInterface */
  protected $codebase;

  protected $drupalPackageRepository = 'https://packages.drupal.org/7';

  /**
   * @inheritDoc
   */
  public function run() {
    // TODO: When we finish up
    // https://www.drupal.org/project/project_issue_file_test/issues/2951863 ,
    // Then we can remove the configuration['repositories']
    if ((!empty($this->configuration['repositories'])) || (!empty($this->configuration['project']))) {
          $this->setupD7Composer();
    }

    parent::run();
  }

  protected function setupD7Composer(): void {
    $source_dir = $this->environment->getExecContainerSourceDir();
    $cmd = "sudo -u www-data /usr/local/bin/composer init --name \"drupal/drupal\" --type \"drupal-core\" -n --working-dir " . $source_dir;
    $this->io->writeln("Initializing composer repository");
    $this->execRequiredEnvironmentCommands($cmd, 'Composer init failure');

    $cmd = "sudo -u www-data /usr/local/bin/composer config discard-changes true --working-dir " . $source_dir;;
    $this->io->writeln("Ignoring Composer Changes");
    $this->execRequiredEnvironmentCommands($cmd, 'Composer config failure');

    $cmd = "sudo -u www-data /usr/local/bin/composer config minimum-stability dev --working-dir " . $source_dir;
    $this->io->writeln("Setting Minimum Stability");
    $this->execRequiredEnvironmentCommands($cmd, 'Composer config failure');

    $cmd = "sudo -u www-data /usr/local/bin/composer config prefer-stable true --working-dir " . $source_dir;
    $this->io->writeln("Setting Preferred Stability");
    $this->execRequiredEnvironmentCommands($cmd, 'Composer config failure');

    $cmd = "sudo -u www-data /usr/local/bin/composer config version 7.59 --working-dir " . $source_dir;
    $this->io->writeln("Setting Arbitrarily High d7 version");
    $this->execRequiredEnvironmentCommands($cmd, 'Composer config failure');

    $cmd = "sudo -u www-data /usr/local/bin/composer require composer/installers --working-dir " . $source_dir;
    $this->io->writeln("Composer Command: $cmd");
    $this->execRequiredEnvironmentCommands($cmd, 'Composer require failure');

    $composer_json = $this->codebase->getSourceDirectory() . '/composer.json';
    if (file_exists($composer_json)) {
      $composerFile = new JsonFile($composer_json);
      $composer_config = $composerFile->read();
      foreach ($this->codebase->getExtensionPaths() as $extension_type => $path) {
        $path = $path . '/{$name}';
        $extension_type = rtrim($extension_type, 's');
        $composer_config['extra']['installer-paths'][$path] = ["type:drupal-$extension_type"];
      }
      $cmd = "sudo -u www-data chmod 0777 {$composer_json}";
      $result = $this->execRequiredCommands($cmd, 'Cannot remove composer.json');
      $composerFile->write($composer_config);
    }
  }


}
