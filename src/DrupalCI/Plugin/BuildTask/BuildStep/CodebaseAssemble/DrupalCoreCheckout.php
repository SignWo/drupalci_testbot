<?php

namespace DrupalCI\Plugin\BuildTask\BuildStep\CodebaseAssemble;

use DrupalCI\Injectable;
use DrupalCI\Plugin\BuildTask\BuildStep\BuildStepInterface;
use DrupalCI\Plugin\BuildTask\BuildTaskInterface;

/**
 * @PluginID("checkout_core")
 */
class DrupalCoreCheckout extends Checkout implements BuildStepInterface, BuildTaskInterface, Injectable {

  /**
   * @inheritDoc
   */
  public function configure() {

    if (FALSE !== getenv(('DCI_CoreRepository'))) {
      $repo['repo'] = getenv(('DCI_CoreRepository'));

      if (FALSE !== getenv(('DCI_CoreBranch'))) {
        $repo['branch'] = getenv(('DCI_CoreBranch'));
      }
      if (FALSE !== getenv(('DCI_GitCommitHash'))) {
        $repo['commit-hash'] = getenv(('DCI_GitCommitHash'));
      }
      $this->configuration['repositories'][0] = $repo;
    }
  }

  /**
   * @inheritDoc
   */
  public function getDefaultConfiguration() {

    return [
      'repositories' => [
        [
          'repo' => '',
          'branch' => '',
          'commit-hash' => '',
          'checkout-dir' => '',
        ]
      ],
    ];
  }

  public function run() {

    if (!empty($this->configuration['repositories'][0]['repo'])) {
      $core_dir = $this->configuration['repositories'][0]['checkout-dir'] = $this->codebase->getSourceDirectory();
      parent::run();
    }
    // Make sure that the codebase is owned by www-data internally after we
    // check it out.
    $commands[] = "chown -fR www-data:www-data {$this->environment->getExecContainerSourceDir()}";
    $result = $this->execEnvironmentCommands($commands);

    $this->updateCoreLockfile($this->environment->getExecContainerSourceDir());

    $this->codebase->setExtensionPaths($this->discoverExentionPaths());
  }

  protected function discoverExentionPaths() {
    $extension_paths = [];
    $core_dir = $this->codebase->getSourceDirectory();

    $composer_json = $core_dir . '/composer.json';
    if (file_exists($composer_json)) {
      $composer_config = json_decode(file_get_contents($composer_json), TRUE);
      if (isset($composer_config['extra']['installer-paths'])) {
        $paths = $composer_config['extra']['installer-paths'];
        foreach ($paths as $path => $config) {
          // Special case for core.
          if ($path == 'core') {
            continue;
          }
          $pathcomponents = explode('/', $path);
          // @todo need to make more robust
          // for now we'll just skip custom modules
          if ($pathcomponents[1] == 'custom'){
            continue;
          }
          array_pop($pathcomponents);
          $extensiontype = rtrim($pathcomponents[0], 's');
          $extension_paths[$extensiontype] = implode('/',$pathcomponents);
        }
      }
      else {
        // Older version of core (pre dec 6, 2016) that used the installer paths
        // from the composer/installers plugin.
        $extension_paths = [
        'module' => 'modules',
                            'theme' => 'themes',
                            'profile' => 'profiles',
                            ];
      }
    }
    return $extension_paths;

  }

  /**
   * @param string $source_dir
   *
   * @return void
   * @throws \DrupalCI\Plugin\BuildTask\BuildTaskException
   */
  protected function updateCoreLockfile(string $source_dir): void {
    if (!preg_match('/.*\.x$/', $this->configuration['repositories'][0]['branch'])) {
      $cmd = "sudo COMPOSER_ROOT_VERSION={$this->configuration['repositories'][0]['branch']} -u www-data /usr/local/bin/composer -vvv update drupal/core* --working-dir ${source_dir}";
      $this->io->writeln("Ensuring Core lockfile is updated");
      $this->execRequiredEnvironmentCommands($cmd, 'Composer config failure');
    }
  }

}
