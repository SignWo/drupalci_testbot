<?php

namespace DrupalCI\Plugin\BuildTask\BuildStep\CodebaseAssemble;

use DrupalCI\Plugin\BuildTask\BuildStep\BuildStepInterface;
use DrupalCI\Plugin\BuildTaskBase;
use DrupalCI\Plugin\BuildTask\BuildTaskInterface;
use Pimple\Container;

/**
 * @PluginID("composer_contrib")
 */
class ComposerContrib extends BuildTaskBase implements BuildStepInterface, BuildTaskInterface {

  /* @var \DrupalCI\Build\Codebase\CodebaseInterface */
  protected $codebase;

  protected $drupalPackageRepository = 'https://packages.drupal.org/8';

  public function inject(Container $container) {
    parent::inject($container);
    $this->codebase = $container['codebase'];
  }

  /**
   * @inheritDoc
   */
  public function getDefaultConfiguration() {
    return [
      'repositories' => [],
      'project' => '',
      'branch' => '',
    ];

  }

  /**
   * @inheritDoc
   */
  public function configure() {
    if (FALSE !== getenv('DCI_Composer_Project')) {
      $this->configuration['project'] = getenv('DCI_Composer_Project');
    }
    if (FALSE !== getenv('DCI_Composer_Branch')) {
      $this->configuration['branch'] = getenv('DCI_Composer_Branch');
    }
  }

  /**
   * @inheritDoc
   */
  public function run() {
    // The repositories key is deprecated, but we'll keep it here to maintain
    // BC with older build.yml files.
    if (!empty($this->configuration['repositories'])) {
      foreach ($this->configuration['repositories'] as $checkout_repo) {
        $checkout_directory = $checkout_repo['checkout_dir'];
        $branch = $checkout_repo['branch'];
        $project = $this->codebase->getProjectName();
        if ($checkout_directory == $this->codebase->getExtensionProjectSubdir()) {
          $this->addComposerProject($project, $branch);
        }
      }
    }
    elseif (!empty($this->configuration['project'])) {
      $this->addComposerProject($this->configuration['project'], $this->configuration['branch']);
    }
  }

  /**
 * Converts a drupal branch string that is stored in git into a composer
 * based branch string. For d8 contrib
 *
 * @param $branch
 *
 * @return mixed
 */
  protected function getSemverBranch($branch) {
    $converted_version = 'dev-' . preg_replace('/^\d+\.x-/', '', $branch);
    return $converted_version;
  }

  /**
   * @param $branch
   * @param $project
   */
  protected function addComposerProject($project, $branch): void {
    if ('TRUE' === strtoupper(getenv('DCI_Debug'))) {
      $verbose = '-vvv ';
      $progress = '';
    }
    else {
      $verbose = '';
      $progress = ' --no-progress';
    }
    $composer_branch = $this->getSemverBranch($branch);

    $source_dir = $this->environment->getExecContainerSourceDir();
    $cmd = "sudo -u www-data /usr/local/bin/composer ${verbose}config repositories.pdo composer {$this->drupalPackageRepository} --working-dir ${source_dir}";
    $this->io->writeln("Adding packages.drupal.org as composer repository");
    $this->execRequiredEnvironmentCommands($cmd, 'Composer config failure');


    $cmd = "sudo -u www-data /usr/local/bin/composer ${verbose} require drupal/" . $project . " " . $composer_branch . " --prefer-source --prefer-stable${progress} --no-suggest --no-interaction --working-dir " . $source_dir;

    $this->io->writeln("Requiring Project");
    $this->execRequiredEnvironmentCommands($cmd, 'Composer require failure');

    // Composer does not respect require-dev anywhere but the root package
    // Lets probe for require-dev in our newly installed module, and add
    // Those dependencies in as well.
    $packages = $this->codebase->getComposerDevRequirements();
    if (!empty($packages)) {
      $cmd = "sudo -u www-data /usr/local/bin/composer ${verbose} require --no-interaction " . implode(' ', $packages) . " --prefer-stable${progress} --no-suggest --working-dir " . $source_dir;
      $this->io->writeln("Adding require-dev");
      $this->execRequiredEnvironmentCommands($cmd, 'Composer require failure');

    }
  }

}
