<?php

namespace DrupalCI\Plugin\BuildTask\BuildStep\CodebaseValidate;

use DrupalCI\Plugin\BuildTask\BuildStep\BuildStepInterface;
use DrupalCI\Plugin\BuildTaskBase;
use DrupalCI\Plugin\BuildTask\BuildTaskInterface;
use Pimple\Container;

/**
 * A plugin to run csslint
 *
 * @PluginID("csslint")
 *
 * The rules:
 * - Lint changed css files only, unless: 1) env variables tell us not to, 2)
 *   .csslintrc has been modified.
 * - If the project does not specify a .csslintrc ruleset, then the .csslintrc
 *   that ships with drupal core will be used.
 */
class Csslint extends BuildTaskBase implements BuildStepInterface, BuildTaskInterface {

  /**
   * The name of the checkstyle report file.
   *
   * @var string
   */
  protected $checkstyleReportFile = 'checkstyle.xml';

  /* @var \DrupalCI\Build\Codebase\CodebaseInterface */
  protected $codebase;

  /**
   * @inheritDoc
   */
  public function getDefaultConfiguration() {
    return [
      // If halt-on-fail is TRUE, then abort the build.
      'halt-on-fail' => FALSE,
    ];
  }

  /**
   * @inheritDoc
   */
  public function configure() {

    if (FALSE !== getenv('DCI_CSS_LintFailsTest')) {
      $this->configuration['halt-on-fail'] = getenv('DCI_CSS_LintFailsTest');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function inject(Container $container) {
    parent::inject($container);
    $this->environment = $container['environment'];
    $this->codebase = $container['codebase'];
  }

  /**
   * Perform the step run.
   */
  public function run() {
    $config = $this->getCssLintConfig();

    $this->io->writeln('<info>csslinting the project.</info>');

    $outputfile = $this->pluginWorkDir . '/' . $this->checkstyleReportFile;

    // Lint either changed files only, or the project directory
    $files_to_lint = $this->getLintableFiles();
    $lintfiles = implode(' ',$files_to_lint);
    if (empty($lintfiles)) {
      $lintfiles = '.';
    }
    elseif ($lintfiles == 'none') {
      return 0;
    }

    $this->io->writeln('Executing csslint.');

    $command = 'cd ' . $this->codebase->getSourceDirectory() . ' && ' . 'csslint --format=checkstyle-xml --config=' . $config . ' ' . $lintfiles . ' > ' . $outputfile;
    //--exclude-list=core/vendor,core/assets/vendor/,core/tests
    $result = $this->execCommands($command);
    // csslint doesnt produce valid xml
    $command = 'cd ' . $this->codebase->getSourceDirectory() . " && perl -CSDA -i -pe 's/[^\x9\xA\xD\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]+//g;' " . $outputfile;
    $result = $this->execCommands($command);

    $this->saveHostArtifact($this->pluginWorkDir . '/' . $this->checkstyleReportFile, $this->checkstyleReportFile);


//    // Save rules used as an artifact.
//    $commands[] = 'cd ' . $this->environment->getExecContainerSourceDir() . $start_dir . ' && ' . $this->environment->getExecContainerSourceDir() . static::$phpcsExecutable . ' -e ' . ' ' . implode(' ', $args) . ' > ' . $this->environment->getContainerWorkDir() . '/' . $this->pluginDir . '/phpcs_sniffs.txt';
//    $this->environment->executeCommands($commands);
//    $this->saveHostArtifact($this->pluginWorkDir . '/phpcs_sniffs.txt', 'phpcs_sniffs.txt');

    // TODO: create a patch.
    //$this->saveHostArtifact($this->pluginWorkDir . '/' . $this->patchFile, $this->patchFile);

    // Allow for failing the test run if CS was bad.
    // TODO: if this is supposed to fail the build, we should put in a
    // $this->terminatebuild.
    if ($this->configuration['halt-on-fail']) {
      return $result->getSignal();
    }
    return 0;
  }

  /**
   * Write out the list of sniffable files.
   *
   * @param $lintable_files
   * @param $file_path
   */
  protected function writeLintableFiles($lintable_files, $file_path) {
    $this->io->writeln("<info>Writing: " . $file_path . "</info>");
    $container_source = $this->environment->getExecContainerSourceDir();
    $lintable_file_list = [];
    foreach ($lintable_files as $file) {
      $lintable_file_list[] = $container_source . "/" . $file;
    }
    file_put_contents($file_path, implode("\n", $lintable_file_list));
    $this->saveHostArtifact($file_path, 'lintable_files.txt');
  }

  /**
   * Returns the relative path of the directory to run csslint in.
   *
   * If a project has a .csslintrc, we want to run csslint from the
   * project directory, otherwise we run from the root directory to use
   * the default drupal rules, unless there arent any.
   */
  protected function getCssLintConfig() {
    $config_file = '';

    // Check for config files in the project directory first
    // We dont use getProjectConfigDirectory because core's csslintrc file is
    // located in the root of the project.
   if (!empty($this->codebase->getProjectSourceDirectory()) && file_exists($this->codebase->getProjectSourceDirectory() . '/.csslintrc'))   {
     if (!empty($this->codebase->getProjectSourceDirectory(FALSE))) {
       $config_file = $this->codebase->getProjectSourceDirectory(FALSE) . '/.csslintrc';
     } else {
       $config_file = '.csslintrc';
     }
   }

    return $config_file;
  }

  /**
   * Check if the .csslintignore file has been modified by git.
   *
   * @returns bool
   *   TRUE if config file if either file is modified, FALSE otherwise.
   */
  protected function configFileIsModified() {
    // Get the list of modified files.
    $modified_files = $this->codebase->getModifiedFiles();
    $config = $this->getCssLintConfig();

    return (
      in_array($config, $modified_files)
    );
  }


  protected function getLintableFiles() {

    // No modified files? Sniff the whole repo.
    if (empty($this->codebase->getModifiedFiles())) {
      $this->io->writeln('<info>No modified files. Sniffing all files.</info>');
      return [$this->codebase->getProjectSourceDirectory(FALSE)];
    }
    elseif ($this->configFileIsModified()) {
      // Sniff all files if .csslintrc has been modified. The file could be
      // 'modified' in that it was removed
      $this->io->writeln('<info>Csslint config file modified, sniffing entire project.</info>');
      return [$this->codebase->getProjectSourceDirectory(FALSE)];
    }
    else {
      $modified_css =  preg_grep("{.*\.css$}",$this->codebase->getModifiedFiles());
      if (empty($modified_css)) {
        $this->io->writeln('<info>No modified files are eligible to be sniffed</info>');
        return ['none'];
      }
      else {
        $this->io->writeln('<info>Running csslint on modified css files.</info>');

        // Make a list of of modified files to this file.
        $lintable_files = $this->build->getAncillaryWorkDirectory() . '/' . $this->pluginDir . '/lintable_files.txt';
        $this->writeLintableFiles($modified_css, $lintable_files);
        return ($modified_css);
      }
    }
  }

}
