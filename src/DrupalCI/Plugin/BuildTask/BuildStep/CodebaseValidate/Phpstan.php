<?php

namespace DrupalCI\Plugin\BuildTask\BuildStep\CodebaseValidate;

use DrupalCI\Plugin\BuildTask\BuildStep\BuildStepInterface;
use DrupalCI\Plugin\BuildTaskBase;
use DrupalCI\Plugin\BuildTask\BuildTaskInterface;
use Pimple\Container;

/**
 * A plugin to run phpstan for deprecation testing.
 *
 * @PluginID("phpstan")
 */
class Phpstan extends BuildTaskBase implements BuildStepInterface, BuildTaskInterface {

  /**
   * The codebase.
   *
   * @var \DrupalCI\Build\Codebase\CodebaseInterface
   */
  protected $codebase;

  /**
   * The path where we expect phpstan to reside.
   *
   * @var string
   */
  protected static $phpstanExecutable = '/vendor/bin/phpstan';

  /**
   * The name of the full report file.
   *
   * @var string
   */
  protected $fullReportFile = 'phpstan_results.xml';

  /**
   * {@inheritdoc}
   */
  public function inject(Container $container) {
    parent::inject($container);
    $this->codebase = $container['codebase'];
  }

  /**
   * @inheritDoc
   */
  public function getDefaultConfiguration() {
    return [
      // If halt-on-fail is FALSE, then NO circumstance should let phpstan
      // terminate the build.
      'halt-on-fail' => FALSE,
    ];
  }

  /**
   * @inheritDoc
   */
  public function configure() {
    if (FALSE !== getenv('DCI_STAN_FailsTest')) {
      $this->configuration['halt-on-fail'] = getenv('DCI_STAN_FailsTest');
    }
  }

  /**
   * Perform the step run.
   */
  public function run() {
    $this->io->writeln('<info>PHPStan checking the project.</info>');

    // Set up state as much as possible in a mockable method.
    //$this->adjustForUseCase();

    if ($this->installPhpstan() != 0) {
      // There was an error installing generic drupal/coder. Bail on sniffing,
      // or terminate the build if the config says so.
      $msg = 'Unable to install PHPStan tools for deprecation testing.';
      if ($this->configuration['halt-on-fail']) {
        $this->terminateBuild('PHPStan error', $msg);
      }
      $this->io->writeln($msg);
      return 0;
    }

    $this->addPhpstanNeon();

    // Run phpstan.
    $source_dir = $this->environment->getExecContainerSourceDir();
    $project_dir = $this->codebase->getProjectConfigDirectory(FALSE);
    $work_dir = $this->environment->getContainerWorkDir();
    $this->io->writeln('<info>Running PHPStan at ' . $source_dir);

    $result = $this->execEnvironmentCommands([
      'cd ' . $project_dir . ' && sudo -u www-data ' . $source_dir . static::$phpstanExecutable . ' analyse --error-format checkstyle -l 0 -c ' . $work_dir . '/' . $this->pluginDir . '/phpstan.neon . > ' . $work_dir . '/' . $this->pluginDir . '/phpstan_results.xml',
    ]);

    // Save phpstan results for later examination.
    $this->saveHostArtifact($work_dir . '/' . $this->pluginDir . '/phpstan_results.xml', 'phpstan_results.xml');
    $this->saveContainerArtifact($work_dir . '/' . $this->pluginDir . '/phpstan_results.xml', 'phpstan_results.xml');

    // @todo implement halt-on-fail, phpcs runs twice to make that happen, that
    //   does not sound very effective.
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function complete($status) {
    // @todo
  }

  /**
   * Install mglaman/phpstan-drupal and phpstan/phpstan-deprecation-rules.
   *
   * @return int
   *   Status code.
   */
  protected function installPhpstan() {
    $this->io->writeln('Attempting to install mglaman/phpstan-drupal');
    $cmd = "sudo -u www-data /usr/local/bin/composer require mglaman/phpstan-drupal --dev";
    $result = $this->execEnvironmentCommands($cmd);
    if ($result->getSignal() !== 0) {
      // If it didn't work, then we bail, but we don't halt build execution.
      $this->io->writeln('Unable to install mglaman/phpstan-drupal.');
      return 2;
    }

    // Install deprecation rules separately to detect any errors separately.
    $this->io->writeln('Attempting to install phpstan/phpstan-deprecation-rules');
    $cmd = "sudo -u www-data /usr/local/bin/composer require phpstan/phpstan-deprecation-rules --dev";
    $result = $this->execEnvironmentCommands($cmd);
    if ($result->getSignal() !== 0) {
      // If it didn't work, then we bail, but we don't halt build execution.
      $this->io->writeln('Unable to install phpstan/phpstan-deprecation-rules.');
      return 2;
    }
    return 0;
  }

  /**
   * Add the phpstan.neon file to the project to configure phpstan parsing.
   */
  protected function addPhpstanNeon() {
    $source_dir = $this->environment->getExecContainerSourceDir();
    $neon_path = $this->build->getAncillaryWorkDirectory() . '/' . $this->pluginDir . '/phpstan.neon';
    $this->io->writeln("<info>Writing $neon_path file</info>");
    $success = file_put_contents($neon_path, "parameters:
	customRulesetUsed: true
	reportUnmatchedIgnoredErrors: false
	# Ignore phpstan-drupal extension's rules.
	ignoreErrors:
		- '#\Drupal calls should be avoided in classes, use dependency injection instead#'
		- '#Plugin definitions cannot be altered.#'
		- '#Missing cache backend declaration for performance.#'
		- '#Plugin manager has cache backend specified but does not declare cache tags.#'
	# Migrate test fixtures kill phpstan, too much PHP.
	excludes_analyse:
		- */tests/fixtures/*.php
includes:
	- $source_dir/vendor/mglaman/phpstan-drupal/extension.neon
	- $source_dir/vendor/phpstan/phpstan-deprecation-rules/rules.neon");

    if ($success === FALSE) {
      $this->io->writeln("Unable to write phpstan.neon");
    }
    else {
      $this->io->writeln($success . ' bytes written');
    }
  }

}
