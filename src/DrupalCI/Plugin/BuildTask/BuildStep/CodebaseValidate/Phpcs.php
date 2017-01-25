<?php

namespace DrupalCI\Plugin\BuildTask\BuildStep\CodebaseValidate;

use DrupalCI\Plugin\BuildTask\BuildStep\BuildStepInterface;
use DrupalCI\Plugin\BuildTaskBase;
use DrupalCI\Plugin\BuildTask\BuildTaskInterface;
use Pimple\Container;

/**
 * A plugin to run phpcs and manage coder stuff.
 *
 * @PluginID("phpcs")
 *
 * The rules:
 * - Generally, make a best-faith effort to sniff all projects, using the
 *   project-specified coder version, core-specified coder version, or @stable.
 * - Sniff changed files only, unless: 1) env variables tell us not to, 2)
 *   phpcs.xml(.dist) has been modified.
 * - If the project does not specify a phpcs.xml ruleset, then the 'Drupal'
 *   standard will be used.
 * - If no phpcs executable has been installed, we require drupal/coder
 *   ^8.2@stable which should install phpcs, then we configure phpcs to use
 *   coder.
 * - If contrib doesn't declare a dependency on a version of coder, but does
 *   have a phpcs.xml file, then we use either core's version, or if none is
 *   specified in core, we use @stable.
 */
class Phpcs extends BuildTaskBase implements BuildStepInterface, BuildTaskInterface {

  /**
   * The testing environment.
   *
   * @var \DrupalCI\Build\Environment\EnvironmentInterface
   */
  protected $environment;

  /**
   * The codebase.
   *
   * @var \DrupalCI\Build\Codebase\CodebaseInterface
   */
  protected $codebase;

  /**
   * Manager for BuildTask plugins.
   *
   * @var DrupalCI\Plugin\PluginManagerInterface
   */
  protected $buildTaskPluginManager;

  /**
   * Whether we should use --standard=Drupal.
   *
   * This implies the following:
   * - That there was no phpcs.xml(.dist) file.
   * - That we should ignore errors.
   *
   * @var bool
   */
  protected $shouldUseDrupalStandard = FALSE;

  /**
   * {@inheritdoc}
   */
  public function inject(Container $container) {
    parent::inject($container);
    $this->environment = $container['environment'];
    $this->codebase = $container['codebase'];
    $this->buildTaskPluginManager = $container['plugin.manager.factory']->create('BuildTask');
  }

  /**
   * @inheritDoc
   */
  public function getDefaultConfiguration() {
    return [
      'sniff_only_changed' => TRUE,
      'start_directory' => 'core/',
      'installed_paths' => 'vendor/drupal/coder/coder_sniffer/',
      'warning_fails_sniff' => FALSE,
      // If sniff_fails_test is FALSE, then NO circumstance should let phpcs
      // terminate the build or fail the test.
      'sniff_fails_test' => FALSE,
      // @todo: Add a test which changes this.
      'report_file_path' => 'phpcs/checkstyle.xml'
    ];
  }

  /**
   * @inheritDoc
   */
  public function configure() {
    // The start directory is where the phpcs.xml file resides. Relative to the
    // source directory.
    if (isset($_ENV['DCI_CS_SniffOnlyChanged'])) {
      $this->configuration['sniff_only_changed'] = $_ENV['DCI_CS_SniffOnlyChanged'];
    }
    if (isset($_ENV['DCI_CS_SniffStartDirectory'])) {
      $this->configuration['start_directory'] = $_ENV['DCI_CS_SniffStartDirectory'];
    }
    if (isset($_ENV['DCI_CS_ConfigInstalledPaths'])) {
      $this->configuration['installed_paths'] = $_ENV['DCI_CS_ConfigInstalledPaths'];
    }
    if (isset($_ENV['DCI_CS_SniffFailsTest'])) {
      $this->configuration['sniff_fails_test'] = $_ENV['DCI_CS_SniffFailsTest'];
    }
    if (isset($_ENV['DCI_CS_WarningFailsSniff'])) {
      $this->configuration['warning_fails_sniff'] = $_ENV['DCI_CS_WarningFailsSniff'];
    }
    if (isset($_ENV['DCI_CS_ReportFilePath'])) {
      $this->configuration['report_file_path'] = $_ENV['DCI_CS_ReportFilePath'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function run() {
    $this->io->writeln('<info>PHPCS sniffing the project.</info>');
    $return = $this->doRun();
    $this->adjustCheckstylePaths();
    if ($return !== 0) {
      if ($this->configuration['sniff_fails_test']) {
        return $return;
      }
    }
    return 0;
  }

  /**
   * Perform the step run.
   */
  protected function doRun() {
    $this->io->writeln('<info>Checking for phpcs tool in codebase.</info>');

    // Check if we're testing contrib, adjust start path accordingly.
    $project = $this->codebase->getProjectName();
    // @todo: For now, core has no project name, but contrib does. This could
    // easily change, so we'll need to change the behavior here.
    if (!empty($project)) {
      $this->configuration['start_directory'] = $this->codebase->getTrueExtensionDirectory('modules');
    }

    // Does the code have a phpcs.xml.dist file after patching?
    $this->io->writeln('<info>Checking for phpcs.xml(.dist) file.</info>');
    $has_phpcs_config = $this->projectHasPhpcsConfig();

    // If there is no phpcs.xml(.dist) file, we use the Drupal standard.
    if (!$has_phpcs_config) {
      $this->io->writeln('PHPCS config file not found. Using Drupal standard.');
      $this->shouldUseDrupalStandard = TRUE;
    }

    // Sniff all files if phpcs.xml(.dist) has been modified. The file could be
    // 'modified' in that it was removed, in which case we want to preserve the
    // sniff_only_changed configuration.
    if ($this->phpcsConfigFileIsModified()) {
      if ($has_phpcs_config) {
        $this->io->writeln('<info>PHPCS config file modified, sniffing entire project.</info>');
        $this->configuration['sniff_only_changed'] = FALSE;
      }
    }

    // Make a list of of modified files to this file.
    $sniffable_file = $this->build->getArtifactDirectory() . '/sniffable_files.txt';

    // Check if we should only sniff modified files.
    if ($this->configuration['sniff_only_changed']) {
      $modified_php_files = $this->codebase->getModifiedPhpFiles();

      // No modified files? We're done.
      if (empty($modified_php_files)) {
        $this->io->writeln('<info>No modified files to sniff.</info>');
        return 0;
      }

      $this->io->writeln('<info>Running PHP Code Sniffer review on modified files.</info>');
      $container_source = $this->environment->getExecContainerSourceDir();
      foreach ($modified_php_files as $file) {
        $sniffable_file_list[] = $container_source . "/" . $file;
      }

      $this->io->writeln("<info>Writing: " . $sniffable_file . "</info>");
      file_put_contents($sniffable_file, implode("\n", $sniffable_file_list));
      $this->build->addArtifact($sniffable_file);
    }
    else {
      $this->io->writeln('<info>Sniffing all files starting at ' . $this->configuration['start_directory'] . '</info>');
    }

    // If there's no phpcs executable in the codebase, then we should try to
    // install drupal/coder.
    $phpcs_bin = '';
    try {
      $phpcs_bin = $this->getPhpcsExecutable();
    }
    catch (\RuntimeException $e) {
      if ($this->installGenericCoder() != 0) {
        // There was an error installing generic drupal/coder. Bail on sniffing,
        // or terminate the build if the config says so.
        $msg = 'Unable to install Coder tools for Drupal standards sniff.';
        if ($this->configuration['sniff_fails_test']) {
          $this->terminateBuild('Coder error', $msg);
        }
        $this->io->writeln($msg);
        return 0;
      }
      $phpcs_bin = $this->getPhpcsExecutable();
    }

    // Set up the report file artifact.
    $this->build->setupDirectory($this->build->getArtifactDirectory() . '/' . dirname($this->configuration['report_file_path']));
    $report_file = $this->build->getArtifactDirectory() . '/' . $this->configuration['report_file_path'];
    $this->build->addArtifact($report_file);

    // Get the sniff start directory.
    $start_dir = $this->getStartDirectory();

    // Set minimum error level for fail. phpcs uses 1 for warning and 2 for
    // error.
    $minimum_error = 2;
    if ($this->configuration['warning_fails_sniff']) {
      $minimum_error = 1;
    }

    // Execute phpcs. The project's phpcs.xml(.dist) should configure file types
    // and all other constraints.
    $cmd = [
      'cd ' . $start_dir . ' &&',
      $phpcs_bin,
      '-ps',
      '--warning-severity=' . $minimum_error,
      '--report-checkstyle=' . $this->environment->getContainerArtifactDir() . '/' . $this->configuration['report_file_path'],
    ];

    // For generic sniffs, use the Drupal standard.
    if ($this->shouldUseDrupalStandard) {
      // @see https://www.drupal.org/node/1587138
      $cmd[] = '--standard=Drupal --extensions=php,module,inc,install,test,profile,theme,css,info,txt,md';
    }

    // Should we only sniff modified files? --file-list lets us specify.
    if ($this->configuration['sniff_only_changed']) {
      $cmd[] = '--file-list=' . $this->environment->getContainerArtifactDir() . '/sniffable_files.txt';
    }
    else {
      // We can use start_directory since we're supposed to sniff the codebase.
      if (!empty($this->configuration['start_directory'])) {
        $cmd[] = $this->environment->getExecContainerSourceDir() . '/' . $this->configuration['start_directory'];
      }
      else {
        // If there's no start_directory, use .
        $cmd[] = $this->environment->getExecContainerSourceDir();
      }
    }

    $this->io->writeln('Executing PHPCS.');
    $result = $this->environment->executeCommands(implode(' ', $cmd));

    // Allow for failing the test run if CS was bad.
    if ($this->configuration['sniff_fails_test']) {
      return $result->getSignal();
    }
    return 0;
  }

  /**
   * Get the full path to the phpcs executable.
   *
   * @return string
   *   The full path to the phpcs executable.
   *
   * @throws \RuntimeException
   *   Thrown when the phpcs executable can't be found.
   *
   * @todo Figure out a better way to make this determination.
   */
  protected function getPhpcsExecutable() {
    $source_dir = $this->environment->getExecContainerSourceDir();
    $phpcs_bin = $source_dir . '/vendor/squizlabs/php_codesniffer/scripts/phpcs';
    $result = $this->environment->executeCommands('test -e ' . $phpcs_bin);
    if ($result->getSignal() == 0) {
      return $phpcs_bin;
    }
    throw new \RuntimeException('phpcs executable does not exist: ' . $phpcs_bin);
  }

  /**
   * Get the start directory within the container.
   *
   * @return string
   *   Container path to the configured start directory. If no config was
   *   specified, return the root path to the container source directory.
   */
  protected function getStartDirectory() {
    // Get the project root.
    $source_dir = $this->environment->getExecContainerSourceDir();
    $start_dir = $source_dir;
    // Add the start directory from configuration.
    if (!empty($this->configuration['start_directory'])) {
      $start_dir = $source_dir . '/' . $this->configuration['start_directory'];
    }
    return $start_dir;
  }

  /**
   * Determine whether the project has a phpcs.xml(.dist) file.
   *
   * Uses start_directory as the place to look.
   *
   * @return bool
   *   TRUE if the config file exists, false otherwise.
   */
  protected function projectHasPhpcsConfig() {
    // Check if phpcs.xml(.dist) exists.
    $config_dir = $this->getStartDirectory();
    $config_file = $config_dir . '/phpcs.xml*';
    $this->io->writeln('Checking for PHPCS config file: ' . $config_file);
    $result = $this->environment->executeCommands('test -e ' . $config_file);
    return ($result->getSignal() == 0);
  }

  /**
   * Check if the phpcs.xml or phpcs.xml.dist file has been modified by git.
   *
   * We should return true for a modification to either, because we don't want
   * drupalci to have an opinion about which config is more important.
   *
   * @returns bool
   *   TRUE if config file if either file is modified, FALSE otherwise.
   */
  protected function phpcsConfigFileIsModified() {
    // Get the list of modified files.
    $modified_files = $this->codebase->getModifiedFiles();
    $start_dir = '';
    if (!empty($this->configuration['start_directory'])) {
      $start_dir = $this->configuration['start_directory'];
    }
    return (
      in_array($start_dir . 'phpcs.xml', $modified_files) ||
      in_array($start_dir . 'phpcs.xml.dist', $modified_files)
    );
  }

  /**
   * Adjust paths in the checkstyle report.
   *
   * The checkstyle report will show file paths inside the container, and we
   * want it to show paths in the host environment. We do a preg_replace() to
   * swap out paths.
   */
  protected function adjustCheckstylePaths() {
    $checkstyle_report_filename = $this->build->getArtifactDirectory() . '/' . $this->configuration['report_file_path'];
    $this->io->writeln('Adjusting paths in report file: ' . $checkstyle_report_filename);
    if (file_exists($checkstyle_report_filename)) {
      // The file is probably owned by root and not writable.
      // @todo remove this when container and host uids have parity.
      exec('sudo chmod 666 ' . $checkstyle_report_filename);
      $checkstyle_xml = file_get_contents($checkstyle_report_filename);
      $checkstyle_xml = preg_replace("!<file name=\"". $this->environment->getExecContainerSourceDir() . "!","<file name=\"" . $this->codebase->getSourceDirectory(), $checkstyle_xml);
      file_put_contents($checkstyle_report_filename, $checkstyle_xml);
    }
  }

  /**
   * Install drupal/coder for generic use-case.
   *
   * @return string
   *   Path to phpcs executable.
   */
  protected function installGenericCoder() {
    // Install drupal/coder.
    $coder_version = '^8.2@stable';

    $this->io->writeln('Attempting to install drupal/coder ' . $coder_version);
    $configuration = [
      'options' => 'require --dev drupal/coder ' . $coder_version,
      'fail_should_terminate' => FALSE,
    ];

    // No exception should ever bubble up from here.
    try {
      $container_composer = $this->buildTaskPluginManager
        ->getPlugin('BuildStep', 'container_composer', $configuration);
      $status = $container_composer->run();

      // If it didn't work, then we bail, but we don't halt build execution.
      if ($status != 0) {
        $this->io->writeln('Unable to install generic drupal/coder.');
        return 2;
      }

      $phpcs_bin = $this->getPhpcsExecutable();

      // We have to configure phpcs to use drupal/coder. We need to be able to use
      // the Drupal standard.
      if (!empty($this->configuration['installed_paths'])) {
        $cmd = [
          $phpcs_bin,
          '--config-set installed_paths ' . $this->environment->getExecContainerSourceDir() . '/' . $this->configuration['installed_paths'],
        ];
        $this->environment->executeCommands(implode(' ', $cmd));
        // Let the user figure out if it worked.
        $this->environment->executeCommands("$phpcs_bin -i");
      }
    }
    catch (\Exception $e) {
      return 2;
    }
    return 0;
  }

}
