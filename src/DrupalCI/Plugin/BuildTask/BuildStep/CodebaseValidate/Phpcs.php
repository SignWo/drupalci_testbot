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
 *   ^8.2.12@stable which should install phpcs, then we configure phpcs to use
 *   coder.
 * - If contrib doesn't declare a dependency on a version of coder, but does
 *   have a phpcs.xml file, then we use either core's version, or if none is
 *   specified in core, we use @stable.
 */
class Phpcs extends BuildTaskBase implements BuildStepInterface, BuildTaskInterface {

  /**
   * The codebase.
   *
   * @var \DrupalCI\Build\Codebase\CodebaseInterface
   */
  protected $codebase;

  /**
   * Whether we should use --standard=Drupal.
   *
   * This implies that there was no phpcs.xml(.dist) file.
   *
   * @var bool
   */
  protected $shouldUseDrupalStandard = FALSE;

  /**
   * Should we install drupal/coder @stable, or rely on the project's spec?
   *
   * @var bool
   */
  protected $shouldInstallGenericCoder = FALSE;

  /**
   * The path where we expect phpcs to reside.
   *
   * @var string
   */
  protected static $phpcsExecutable = '/vendor/bin/phpcs';

  /**
   * The path where we expect phpcbf to reside.
   *
   * @var string
   */
  protected static $phpcbfExecutable = '/vendor/bin/phpcbf';

  /**
   * The name of the checkstyle report file.
   *
   * @var string
   */
  protected $checkstyleReportFile = 'checkstyle.xml';

  /**
   * The name of the full report file.
   *
   * @var string
   */
  protected $fullReportFile = 'codesniffer_results.txt';

  /**
   * The name of the codesniffer patch file.
   *
   * @var string
   */
  protected $patchFile = 'codesniffer_fixes.patch';

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
      'sniff-all-files' => FALSE,
      // If halt-on-fail is FALSE, then NO circumstance should let phpcs
      // terminate the build.
      'halt-on-fail' => FALSE,
      'coder-version' => '^8.2@stable',
    ];
  }

  /**
   * @inheritDoc
   */
  public function configure() {
    // The start directory is where the phpcs.xml file resides. Relative to the
    // source directory.
    if (FALSE !== getenv('DCI_CS_SniffAllFiles')) {
      $this->configuration['sniff-all-files'] = getenv('DCI_CS_SniffAllFiles');
    }
    if (FALSE !== getenv('DCI_CS_SniffFailsTest')) {
      $this->configuration['halt-on-fail'] = getenv('DCI_CS_SniffFailsTest');
    }
    if (FALSE !== getenv('DCI_CS_CoderVersion')) {
      $this->configuration['coder-version'] = getenv('DCI_CS_CoderVersion');
    }
  }

  /**
   * Perform the step run.
   */
  public function run() {
    $this->io->writeln('<info>PHPCS sniffing the project.</info>');

    // Set up state as much as possible in a mockable method.
    $this->adjustForUseCase();

    if ($this->shouldInstallGenericCoder) {
      if ($this->installGenericCoder() != 0) {
        // There was an error installing generic drupal/coder. Bail on sniffing,
        // or terminate the build if the config says so.
        $msg = 'Unable to install Coder tools for Drupal standards sniff.';
        if ($this->configuration['halt-on-fail']) {
          $this->terminateBuild('Coder error', $msg);
        }
        $this->io->writeln($msg);
        return 0;
      }
    }

    // Core no longer configures phpcs to use Coder for us, so we do that here:
    try {
      $phpcs_bin = $this->getPhpcsExecutable();

      // We have to configure phpcs to use drupal/coder. We need to be able to use
      // the Drupal standard.
      $cmd = [
        "sudo -u www-data",
        $phpcs_bin,
        "--config-set installed_paths {$this->environment->getExecContainerSourceDir()}/vendor/drupal/coder/coder_sniffer/",
      ];
      $result = $this->execEnvironmentCommands(implode(' ', $cmd));
      // Let the user figure out if it worked.
      $result = $this->execEnvironmentCommands("$phpcs_bin -i");

    }
    catch (\Exception $e) {
      // No exception should ever bubble up from here.
      return 2;
    }

    // Get the sniff start directory.
    $start_dir = $this->codebase->getProjectConfigDirectory(FALSE);

    // Execute phpcs. The project's phpcs.xml(.dist) should configure file types
    // and all other constraints.
    // Gather phpcs arguments separately so we can re-use them for phpcbf.
    $phpcs_args = [
      '--report-full=' . $this->environment->getContainerWorkDir() . '/' . $this->pluginDir . '/' . $this->fullReportFile,
      '--report-checkstyle=' . $this->environment->getContainerWorkDir() . '/' . $this->pluginDir . '/' . $this->checkstyleReportFile,
      '--report-diff=' . $this->environment->getContainerWorkDir() . '/' . $this->pluginDir . '/' . $this->patchFile,
    ];

    // For generic sniffs, use the Drupal standard.
    if ($this->shouldUseDrupalStandard) {
      // @see https://www.drupal.org/node/1587138
      $phpcs_args[] = '--standard=Drupal --extensions=php,module,inc,install,test,profile,theme,css,info,txt,md';
    }

    // Should we only sniff modified files? --file-list lets us specify.
    $files_to_sniff = $this->getSniffableFiles();

    if ($files_to_sniff == 'all') {
      // Start at the project directory
      $phpcs_args[] = $this->environment->getExecContainerSourceDir() . '/' . $this->codebase->getProjectConfigDirectory(FALSE);
    }
    elseif ($files_to_sniff == 'none') {
      return 0;
    }
    else {
      $phpcs_args[] = '--file-list=' . $this->environment->getContainerWorkDir() . '/' . $this->pluginDir . '/sniffable_files.txt';
    }

    $this->io->writeln('Executing PHPCS.');

    $sniffresult = $this->execEnvironmentCommands([
      'cd ' . $start_dir . ' && sudo -u www-data ' . $this->environment->getExecContainerSourceDir() . static::$phpcsExecutable . ' ' . implode(' ', $phpcs_args),
    ]);


    // Save phpcs sniffs as an artifact.
    $commands[] = 'cd ' . $start_dir . ' && sudo -u www-data ' . $this->environment->getExecContainerSourceDir() . static::$phpcsExecutable . ' -e ' . ' ' . implode(' ', $phpcs_args) . ' > ' . $this->environment->getContainerWorkDir() . '/' . $this->pluginDir . '/phpcs_sniffs.txt';
    $result = $this->execEnvironmentCommands($commands);
    $this->saveHostArtifact($this->pluginWorkDir . '/phpcs_sniffs.txt', 'phpcs_sniffs.txt');

    $this->saveHostArtifact($this->pluginWorkDir . '/' . $this->checkstyleReportFile, $this->checkstyleReportFile);
    $this->saveHostArtifact($this->pluginWorkDir . '/' . $this->fullReportFile, $this->fullReportFile);
    $this->saveHostArtifact($this->pluginWorkDir . '/' . $this->patchFile, $this->patchFile);

    // Allow for failing the test run if CS was bad.
    // TODO: distinguish between "coding standard issue is a build failure"
    // and "stop processing if it fails"
    // $this->terminatebuild.
    if ($this->configuration['halt-on-fail']) {
      return $sniffresult->getSignal();
    }
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function complete($status) {
    $this->adjustCheckstylePaths();
  }

  /**
   * A ton of logic about which use-case we're supporting.
   *
   * This method should adjust state as much as possible for different
   * use-cases. That way we can unit test against it for those states.
   */
  protected function adjustForUseCase() {
    $this->shouldUseDrupalStandard = FALSE;
    $this->shouldInstallGenericCoder = FALSE;

    // Check if we're testing contrib, adjust start path accordingly.
    $project = $this->codebase->getProjectName();


    // Does the code have a phpcs.xml.dist file after patching?
    $this->io->writeln('<info>Checking for phpcs.xml(.dist) file.</info>');
    $has_phpcs_config = $this->projectHasPhpcsConfig();

    // If there is no phpcs.xml(.dist) file, we use the Drupal standard.
    if (!$has_phpcs_config) {
      $this->io->writeln('PHPCS config file not found. Using Drupal standard.');
      $this->shouldUseDrupalStandard = TRUE;
    }
    else {
      $this->io->writeln('Using existing PHPCS config file.');
      $this->shouldUseDrupalStandard = FALSE;
    }

    // If there's no phpcs executable in the codebase already, then we should
    // try to install drupal/coder.
    try {
      $this->getPhpcsExecutable();
      $this->shouldInstallGenericCoder = FALSE;
    }
    catch (\RuntimeException $e) {
      $this->shouldInstallGenericCoder = TRUE;
    }
  }

  /**
   * Write out the list of sniffable files.
   *
   * @param $sniffable_files
   * @param $file_path
   */
  protected function writeSniffableFiles($sniffable_files, $file_path) {
    $this->io->writeln("<info>Writing: " . $file_path . "</info>");
    $container_source = $this->environment->getExecContainerSourceDir();
    $sniffable_file_list = [];
    foreach ($sniffable_files as $file) {
      $sniffable_file_list[] = $container_source . "/" . $file;
    }
    file_put_contents($file_path, implode("\n", $sniffable_file_list));
    $this->saveHostArtifact($file_path, 'sniffable_files.txt');
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
    $this->io->writeln('Checking for phpcs tool in codebase.');
    $source_dir = $this->environment->getExecContainerSourceDir();
    $phpcs_bin = $source_dir . static::$phpcsExecutable;
    $result = $this->execEnvironmentCommands('test -e ' . $phpcs_bin);
    if ($result->getSignal() == 0) {
      return $phpcs_bin;
    }
    throw new \RuntimeException('phpcs executable does not exist: ' . $phpcs_bin);
  }

  /**
   * Determine whether the project has a phpcs.xml(.dist) file.
   *
   * Uses project config directory as the place to look.
   *
   * @return bool
   *   TRUE if the config file exists, false otherwise.
   */
  protected function projectHasPhpcsConfig() {
    // Check if phpcs.xml(.dist) exists.
    $config_dir = $this->codebase->getProjectConfigDirectory();
    $config_file = $config_dir . '/phpcs.xml*';
    $this->io->writeln('Checking for PHPCS config file: ' . $config_file);
    $result = $this->execCommands('test -e ' . $config_file);
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
    $start_dir = $this->codebase->getProjectConfigDirectory(FALSE);
    return (
      in_array($start_dir . '/phpcs.xml', $modified_files) ||
      in_array($start_dir . '/phpcs.xml.dist', $modified_files)
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
    $checkstyle_report_filename = $this->pluginWorkDir . '/' . $this->checkstyleReportFile;
    $this->io->writeln('Adjusting paths in report file: ' . $checkstyle_report_filename);
    if (file_exists($checkstyle_report_filename)) {
      // The file is probably owned by root and not writable.
      // @todo remove this when container and host uids have parity.
      $result = $this->execCommands('sudo chmod 666 ' . $checkstyle_report_filename);
      $checkstyle_xml = file_get_contents($checkstyle_report_filename);
      $checkstyle_xml = preg_replace("!<file name=\"" . $this->environment->getExecContainerSourceDir() . "!", "<file name=\"" . $this->codebase->getSourceDirectory(), $checkstyle_xml);
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
    $coder_version = $this->configuration['coder-version'];
    $this->io->writeln('Attempting to install drupal/coder ' . $coder_version);
    $cmd = "sudo -u www-data /usr/local/bin/composer require --dev drupal/coder " . $coder_version;
    $result = $this->execEnvironmentCommands($cmd);
    if ($result->getSignal() !== 0) {
      // If it didn't work, then we bail, but we don't halt build execution.
      $this->io->writeln('Unable to install generic drupal/coder.');
      return 2;
    }
    return 0;
  }

  protected function getSniffableFiles() {
    // Check if we should only sniff modified files.
    if ($this->configuration['sniff-all-files']) {
      return 'all';
    }

    // No modified files? Sniff the whole repo.
    if (empty($this->codebase->getModifiedFiles())) {
      $this->io->writeln('<info>No modified files. Sniffing all files.</info>');
      return 'all';
    }
    else if ($this->phpcsConfigFileIsModified()) {
      // Sniff all files if phpcs.xml(.dist) has been modified. The file could be
      // 'modified' in that it was removed, in which case we want to preserve the
      // sniff_only_changed configuration.
      $this->io->writeln('<info>PHPCS config file modified, sniffing entire project.</info>');
      return 'all';
    }
    else {
      $modified_php_files = $this->codebase->getModifiedPhpFiles();
      if (empty($modified_php_files)) {
        $this->io->writeln('<info>No modified files are eligible to be sniffed</info>');
        return 'none';
      }
      else {
        $this->io->writeln('<info>Running PHP Code Sniffer review on modified php files.</info>');

        // Make a list of of modified files to this file.
        $sniffable_file = $this->build->getAncillaryWorkDirectory() . '/' . $this->pluginDir . '/sniffable_files.txt';
        $this->writeSniffableFiles($modified_php_files, $sniffable_file);
        return ($modified_php_files);
      }
    }
  }

}
