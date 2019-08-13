<?php

namespace DrupalCI\Plugin\BuildTask\BuildStep\CodebaseAssemble;

use DrupalCI\Injectable;
use DrupalCI\Plugin\BuildTask\BuildStep\BuildStepInterface;
use DrupalCI\Plugin\BuildTaskBase;
use DrupalCI\Plugin\BuildTask\BuildTaskInterface;
use Pimple\Container;

/**
 * @PluginID("update_dependencies")
 */
class UpdateDependencies extends BuildTaskBase implements BuildStepInterface, BuildTaskInterface, Injectable {

  protected $drupalPackageRepository = 'https://packages.drupal.org/8';

  public function inject(Container $container) {
    parent::inject($container);
    $this->codebase = $container['codebase'];

  }

  /**
   * Codebase.
   *
   * @var \DrupalCI\Build\Codebase\CodebaseInterface
   */
  protected $codebase;

  /**
   * @inheritDoc
   */
  public function run() {
    // TODO: throw an exception/warning if info.yml changed and not
    // composer.json and vice versa.

    // TODO: composer validate --strict

    // Get the modified files from the codebase.
    // Anything that has a change to the composer.json or .info/.info.yml should
    // be detected.
    // For core:
    // - If root composer.lock is changed, reinstall via Composer.
    // - If root composer.json is changed, but composer.lock is not, then it
    //   could be that the patch changed autoload/autoload-dev. Changes there
    //   are not reflected in the lock file. So we should have Composer dump
    //   autoload.
    // For contrib:
    // - If composer.json is modified, remove the project, validate the composer
    //   json, move it to ancillary source directory, make a branch, commit it,
    //   add it to the core composer.json as an additional repo, and composer
    //   require the new version again.
    if ('TRUE' === strtoupper(getenv('DCI_Debug'))) {
      $verbose = ' -vvv';
      $progress = '';
    } else {
      $verbose = '';
      $progress = ' --no-progress';
    }
    $modified_files = $this->codebase->getModifiedFiles();
    $source_dir = $this->environment->getExecContainerSourceDir();
    $project_name = $this->codebase->getProjectName();

    $ancillary_dir = $this->environment->getContainerWorkDir() . '/' . $project_name;
    $contrib_dir = $this->codebase->getProjectSourceDirectory(FALSE);

    if (in_array($contrib_dir . '/composer.json', $modified_files)) {
      $this->io->writeln("composer.json changed by patch: recalculating depenendices");

      // 1. Get the currently checked out composer branch name <CBRANCH>
      $cmd = "sudo -u www-data /usr/local/bin/composer${verbose} show --working-dir " . $source_dir . " |grep 'drupal/$project_name ' |awk '{print $2}'";
      $this->io->writeln("Determining composer branch: $cmd");
      $result = $this->execRequiredEnvironmentCommands($cmd, 'Unable to determine composer branch');

      $composer_branchname = $result->getOutput();
      $composer_branchname = $this->flipDevBranch($composer_branchname);
      // Copy directory to ancillary
      $project_dir = $this->environment->getExecContainerSourceDir() . '/' . $this->codebase->getProjectSourceDirectory(FALSE);
      $cmd = "sudo -u www-data cp -r $project_dir $ancillary_dir";
      $result = $this->execRequiredEnvironmentCommands($cmd, 'Ancillary Copy Failure');

      // Remove any of its dev dependnecies
      // $this->io->writeln("Removing dev dependencies: $cmd");
      // $this->execRequiredCommand($cmd, 'Dev dependency Removal Failure');
      $dev_dependencies = $this->codebase->getComposerDevRequirements();
      foreach($dev_dependencies as $package){
        $dev_dep = str_replace("'", "", strstr($package, ':', TRUE));
        $cmd = "sudo -u www-data /usr/local/bin/composer${verbose} remove " . $dev_dep . " --no-interaction --working-dir " . $source_dir;
        $this->io->writeln("Removing dev dependencies: $cmd");
        $this->execRequiredEnvironmentCommands($cmd, 'Dev dependency Removal Failure');

      }
      // Remove the project via composer
      $cmd = "sudo -u www-data /usr/local/bin/composer${verbose} remove drupal/" . $project_name . " --no-interaction --working-dir " . $source_dir;
      $this->io->writeln("Removing project: $cmd");
      $result = $this->execRequiredEnvironmentCommands($cmd, 'Removal of modified project failed');

      // make a fake branch in ancillary <TBRANCH>
      $cmd = "cd " . $ancillary_dir . " && sudo -u www-data git checkout -b ancillary-branch";
      $this->io->writeln("Creating ancillary branch: $cmd");
      $result = $this->execRequiredEnvironmentCommands($cmd, 'Ancillary branch creation failure');

      // commit to ancillary
      $cmd = "cd " . $ancillary_dir . " && sudo -u www-data git add . && sudo -u www-data git config --global user.email \"drupalci@drupalci.org\" &&
sudo -u www-data git config --global user.name \"The Testbot\" && sudo -u www-data git commit -am 'intermediate commit'";
      $this->io->writeln("Git Command: $cmd");
      $result = $this->execRequiredEnvironmentCommands($cmd, 'Ancillary commit failure');

      // unset pdo
      $cmd = "sudo -u www-data /usr/local/bin/composer${verbose} config repositories.pdo --unset --working-dir " . $source_dir;
      $this->io->writeln("Unset pdo repo: $cmd");
      $result = $this->execRequiredEnvironmentCommands($cmd, 'Ancillary repo config failure');

      // add ancillary as a composer repo
      $cmd = "sudo -u www-data /usr/local/bin/composer${verbose} config repositories.ancillary '{\"type\": \"path\", \"url\": \"" . $ancillary_dir . "\", \"options\": {\"symlink\": false}}' --working-dir " . $source_dir;

      $this->io->writeln("Composer Command: $cmd");
      $result = $this->execRequiredEnvironmentCommands($cmd, 'Ancillary repo config failure');

      // reset pdo
      $cmd = "sudo -u www-data /usr/local/bin/composer${verbose} config repositories.pdo composer $this->drupalPackageRepository --working-dir " . $source_dir;

      $this->io->writeln("Composer Command: $cmd");
      $result = $this->execRequiredEnvironmentCommands($cmd, 'Ancillary repo config failure');

      // composer require drupal/project "<TBRANCH> AS <CBRANCH>"
      $cmd = "sudo -u www-data /usr/local/bin/composer${verbose} require drupal/" . $project_name . " 'dev-ancillary-branch as $composer_branchname' --working-dir " . $source_dir;

      $this->io->writeln("Composer Command: $cmd");
      $result = $this->execRequiredEnvironmentCommands($cmd, 'Ancillary require failure');

      // Look for changes to require dev as well. These require-dev packages will
      // Only exist if there exists dev-depenencies in composer.json
      // Otherwise we will not see anything in the root dir
      $packages = $this->codebase->getComposerDevRequirements();

      if (!empty($packages)) {
        $cmd = "sudo -u www-data /usr/local/bin/composer${verbose} require " . implode(" ", $packages) . " --prefer-stable${progress} --no-suggest --working-dir " . $source_dir;
        $this->io->writeln("Composer Command: $cmd");
        $this->execRequiredEnvironmentCommands($cmd, 'Composer require failure');

      }
    }
    if (in_array('composer.lock', $modified_files)) {
      $this->io->writeln("core dependencies changed by patch: recalculating depenendices");

      $cmd = "sudo -u www-data /usr/local/bin/composer${verbose} install --prefer-dist --no-suggest --no-interaction${progress} --working-dir " . $source_dir;

      $this->io->writeln("Composer Command: $cmd");
      $result = $this->execRequiredEnvironmentCommands($cmd, 'Core composer reinstall failure');
    }
    // Root composer.lock file is not modified, but let's still check the root
    // composer.json.
    elseif (in_array('composer.json', $modified_files)) {
      $this->io->writeln("Root composer.json modified but lock file unchanged. Dumping autoload.");

      $cmd = "sudo -u www-data /usr/local/bin/composer${verbose} dumpautoload --profile --no-interaction --working-dir " . $source_dir;

      $this->io->writeln("Composer Command: $cmd");
      $result = $this->execRequiredEnvironmentCommands($cmd, 'Core composer dumpautoload failure');
    }

  }

  /**
   * @param $branch
   *
   * @return string
   */
  protected function flipDevBranch($branch) {
    $converted_version = 'dev-' . preg_replace('/-dev$/', '', $branch);
    return $converted_version;
  }
}
