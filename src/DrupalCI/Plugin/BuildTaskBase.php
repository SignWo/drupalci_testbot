<?php

namespace DrupalCI\Plugin;

use DrupalCI\Build\Artifact\BuildArtifact;
use DrupalCI\Build\Artifact\ContainerBuildArtifact;
use DrupalCI\Build\Artifact\StringBuildArtifact;
use DrupalCI\Injectable;
use DrupalCI\Plugin\BuildTask\BuildTaskException;
use DrupalCI\Plugin\BuildTask\BuildTaskInterface;
use DrupalCI\Plugin\BuildTask\HaltingFailException;
use DrupalCI\Build\Environment\CommandResult;
use Pimple\Container;
use Symfony\Component\Process\Process;

/**
 * Base class for plugins.
 */
abstract class BuildTaskBase implements Injectable, BuildTaskInterface {

  /**
   * The plugin_id.
   *
   * @var string
   */
  protected $pluginId;

  /**
   * The plugin_label
   *
   * @var string
   */
  protected $pluginLabel;

  /**
   * Any variables that can affect the behavior of this plugin, that are
   * specific to this plugin, reside in a configuration array within the plugin.
   *
   * @var array
   *
   */
  protected $configuration = [];

  /**
   * Configuration overrides passed into the plugin.
   *
   * @var array
   */
  protected $configuration_overrides;

  /**
   * The plugin implementation definition.
   *
   * @var array
   */
  protected $pluginDefinition;

  /**
   * Style object.
   *
   * @var \DrupalCI\Console\DrupalCIStyle
   */
  protected $io;

  /**
   * The current build.
   *
   * @var \DrupalCI\Build\BuildInterface
   */
  protected $build;

  /**
   * The current container environment
   *
   * @var  \DrupalCI\Build\Environment\EnvironmentInterface
   */
  protected $environment;

  /**
   * The container.
   *
   * We need this to inject into other objects.
   *
   * @var \Pimple\Container
   */
  protected $container;

  /**
   * The working directory under the ancillary directory where temporary
   * artifacts are created.
   */
  protected $pluginWorkDir;

  /**
   * Convenience variable to get the PluginID.PluginLabel combo that will be
   * found in the container.
   */
  protected $pluginDir;

  /**
   * Any host commands and their output that are run by the build tasks should
   * get accumulated here and turned into an artifact.
   *
   * @var array
   */
  protected $buildTaskCommandOutput;

  /**
   * @var float
   */
  protected $startTime;

  /**
   * @var float
   *   Total time taken for this build task, including child tasks
   */
  protected $elapsedTime;
  /*
   * @var string[]
   *
   * Associatiave array of environment variables to pass into commands this
   * plugin executes.
   */
  protected $command_environment = [];
  /*
   * @var BuildArtifactInterface[]
   *
   * Container for all the artifacts this plugin produces.
   */
  protected $pluginArtifacts = [];

  /**
   * Constructs a Drupal\Component\Plugin\BuildTaskBase object.
   *
   * @param array $configuration_overrides
   *   A configuration array containing overrides from the build.yml file.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   */
  public function __construct(array $configuration_overrides = [], $plugin_id = '', $plugin_definition = []) {
    $this->configuration = $this->getDefaultConfiguration();
    // Set the plugin label as a special case.
    if (isset($configuration_overrides['plugin_label'])) {
      $this->pluginLabel = $configuration_overrides['plugin_label'];
      unset($configuration_overrides['plugin_label']);
    }
    $this->configuration_overrides = $configuration_overrides;
    $this->pluginId = $plugin_id;
    $this->pluginDefinition = $plugin_definition;
    // Compute the plugin's configuration.
    $this->configure();
    $this->override_config();
  }

  /**
   * Decorator for run functions to allow all of them to be timed.
   *
   */
  public function start() {
    $this->startTime = microtime(TRUE);
    $this->io->writeLn("<info>----------------   Starting <options=bold>" . $this->getPluginIdLabel() . "</>   ----------------</info>");

    $this->setup();
    $statuscode = $this->run();
    if (!isset($statuscode)) {
      return 0;
    }
    else {
      if ($statuscode != 0) {
        $this->io->writeLn("<error>---------------- <options=bold>" . $this->getPluginIdLabel() . "</> Returned " . $statuscode . " ----------------</error>");
      }

      return $statuscode;
    }
  }

  /**
   * Decorator for complete functions to stop their timer.
   *
   * @param $childStatus
   */
  public function finish($childStatus) {
    $this->complete($childStatus);
    $this->teardown();
    $elapsed_time = microtime(TRUE) - $this->startTime;
    $this->elapsedTime = $elapsed_time;
    $datetime = new \DateTime();
    $this->io->writeLn("<info>---------------- Finished <options=bold>" . $this->getPluginIdLabel() . "</> in " . number_format($elapsed_time, 3) . " seconds ---------------- </info>");
  }

  private function setup(){
    // Sets up the artifact and ancillary directories for the plugins.
    $this->pluginDir = $this->getPluginIdLabel();
    $this->pluginWorkDir = $this->build->getAncillaryWorkDirectory() . '/' . $this->pluginDir;
    $this->build->setupDirectory($this->pluginWorkDir);

  }

  /**
   * Get the plugin id + label, as in composer.update.
   */
  protected function getPluginIdLabel() {
    $id = $this->pluginId;
    if (!empty($this->pluginLabel)) {
      $id = $this->pluginId . '.' . $this->pluginLabel;
    }
    return $id;
  }

  private function teardown() {
    if (!empty($this->buildTaskCommandOutput)){
      $output = implode("\n", $this->buildTaskCommandOutput);
      $this->saveStringArtifact($output, 'command_output');
    }
    foreach ($this->pluginArtifacts as $artifact) {
      $artifact->preserve();
    }
  }

  /**
   * @inheritDoc
   */
  public function getElapsedTime($inclusive = TRUE) {
    return $this->elapsedTime;
  }

  /**
   * Execute a shell command.
   * Will save the output as a command_output artifact on the build,
   * however, sometimes you want to explicitly name an artifact that is the
   * output of a command. You can skip saving the command output in that case
   * if the plugin already has other plans for the output.
   *
   * @param string[] $commands
   * @param string[] &$output
   * @param int &$return_var
   * @param bool $save_output
   *
   * @return \DrupalCI\Build\Environment\CommandResultInterface
   */
  protected function execCommands($commands, $save_output = TRUE) {
    $source_dir = $this->build->getSourceDirectory();
    /** @var \DrupalCI\Build\Environment\CommandResult $executionResult */
    $executionResult = $this->container['command.result'];
    $maxExitCode = 0;
    $commands = is_array($commands) ? $commands : [$commands];
    foreach ($commands as $cmd) {

      $this->io->writeLn("<fg=magenta>$cmd</fg=magenta>");

      // TODO: we probably need to make a Process Factory on the container for
      // this.
      /** @var Process $process */

      $process = $this->container['process'];
      $process->setWorkingDirectory($source_dir);
      $process->setCommandLine($cmd);
      if (!empty($this->command_environment)){
        $process->setEnv($this->command_environment);
      }
      $process->setTimeout(300);

      $process->run();
      $cmdOutput = $process->getOutput();
      $errorOutput = $process->getErrorOutput();
      $exitCode = $process->getExitCode();

      $maxExitCode = max($exitCode, $maxExitCode);

      $executionResult->appendOutput($cmdOutput);
      $executionResult->appendError($errorOutput);
      $executionResult->setSignal($maxExitCode);
      if ($save_output) {
        // TODO: save as machine readable json?
        if (!empty($errorOutput)) {
          $cmdOutput = "{$cmdOutput}\n{$errorOutput}";
        }
        $this->buildTaskCommandOutput[] = "Host command: {$cmd}";
        $this->buildTaskCommandOutput[] = "Return code: {$exitCode}";
        if (!empty($cmdOutput)){
          $this->buildTaskCommandOutput[] = "Output: {$cmdOutput}";
        }
      }
    }
    return $executionResult;
  }

  /**
   * Execute a shell command, terminate build on failure, emit message.
   *
   * @param string $commands
   * @param string $failure_message
   * @param bool $save_output
   *
   * @return \DrupalCI\Build\Environment\CommandResult
   * @throws \DrupalCI\Plugin\BuildTask\BuildTaskException
   *
   * @todo Allow fail status from commands, for host_ and container_command.
   */
  protected function execRequiredCommands($commands, $failure_message, $save_output = TRUE) {

    /** @var \DrupalCI\Build\Environment\CommandResult $executionResult */
    $executionResult = $this->execCommands($commands, $save_output);
    if ($executionResult->getSignal() !== 0) {
      $command_strings = is_array($commands) ? $commands : [$commands];
      $command_strings = implode("\n",$command_strings);
      $output = $command_strings . "\nReturn Code:" . $executionResult->getSignal() . "\n" . $executionResult->getOutput();
      $this->terminateBuild($failure_message, $output);
    }
    return $executionResult;
  }

  /**
   * @param $commands
   * @param null $container_id
   * @param bool $save_output
   *
   * @return \DrupalCI\Build\Environment\CommandResultInterface
   */
  protected function execEnvironmentCommands($commands, $container_id = NULL, $save_output = TRUE) {

    $result = $this->environment->executeCommands($commands, $container_id, $this->command_environment);
    if ($save_output) {
      $command_strings = is_array($commands) ? $commands : [$commands];
      $command_strings = implode("\n",$command_strings);
      $return_signal = $result->getSignal();
      $commands_output = $result->getOutput();
      $commands_error = $result->getError();
      // TODO: save as machine readable json?
      $this->buildTaskCommandOutput[] = "Host commands: ${command_strings}";
      $this->buildTaskCommandOutput[] = "Return code: ${return_signal}";
      $this->buildTaskCommandOutput[] = "Output: ${commands_output}";
      $this->buildTaskCommandOutput[] = "StdErr: ${commands_error}";
    }
    return $result;
  }

  /**
   * @param $commands
   * @param $failure_message
   * @param null $container_id
   * @param bool $save_output
   *
   * @return \DrupalCI\Build\Environment\CommandResultInterface
   * @throws \DrupalCI\Plugin\BuildTask\BuildTaskException
   */
  protected function execRequiredEnvironmentCommands($commands, $failure_message, $container_id = NULL, $save_output = TRUE) {
    $result = $this->execEnvironmentCommands($commands, $container_id, $save_output);
    $return_signal = $result->getSignal();

    if ($return_signal !== 0) {
      $command_strings = is_array($commands) ? $commands : [$commands];
      $command_strings = implode("\n",$command_strings);
      $output = "--- Commands Executed ---\n${command_strings}\nReturn Code: ${return_signal}\n--- Output ---\n{$result->getOutput()}\n--- Errors ---\n{$result->getError()}";
      $this->terminateBuild($failure_message, $output);
    }
    return $result;

  }
  /**
   * @param $filepath
   *   The full filepath of the artifact within the container environment.
   * @param $savename
   *   The name of the file or directory that we wish the preserved artifact to
   *   have.
   * @param bool $cleanse
   *   If true the original artifact will be purged from the filesystem.
   */
  protected function saveHostArtifact($filepath, $savename, $cleanse = FALSE) {

    if (file_exists($filepath)) {
      $artifact = new BuildArtifact($filepath, "{$this->pluginDir}/${savename}", $cleanse);
      $artifact->inject($this->container);
      $this->pluginArtifacts[] = $artifact;
    }
  }

  /**
   * @param $contents
   *   The string contents that we wish to save as an artifact
   * @param $savename
   *   The name of the file or directory that we wish the preserved artifact to
   *  have.
   */
  protected function saveStringArtifact($contents, $savename) {

    $artifact = new StringBuildArtifact($contents, "{$this->pluginDir}/${savename}");
    $artifact->inject($this->container);
    $this->pluginArtifacts[] = $artifact;
  }

  /**
   * @param $filepath
   *   The full filepath of the artifact within the container environment.
   * @param $savename
   *   The name of the file or directory that we wish the preserved artifact to
   *  have.
   * @param bool $cleanse
   *   If true the original artifact will be purged from the filesystem.
   */
  protected function saveContainerArtifact($filepath, $savename, $cleanse = FALSE) {

    $artifact = new ContainerBuildArtifact($filepath, "{$this->pluginDir}/${savename}", $cleanse);
    $artifact->inject($this->container);
    $this->pluginArtifacts[] = $artifact;
  }

  public function inject(Container $container) {
    $this->build = $container['build'];
    $this->environment = $container['environment'];
    $this->io = $container['console.io'];
    $this->container = $container;
  }

  /**
   * @inheritDoc
   */
  public function getComputedConfiguration() {
    return $this->configuration;
  }

  protected function override_config() {

    if (!empty($this->configuration_overrides)) {
      if ($invalid_overrides = array_diff_key($this->configuration_overrides, $this->configuration)) {
        // @TODO: somebody is trying to override a non-existant configuration value. Throw an exception? print a warning?
      }
      $this->configuration = array_merge($this->configuration, array_intersect_key($this->configuration_overrides, $this->configuration));
    }
  }

  /**
   * @inheritDoc
   */
  public function complete($childStatus) {
    // TODO: Implement complete() method.
  }

  /**
   * @inheritDoc
   */
  public function configure() {
    // Interface placeholder for plugins lacking config.
  }

  /**
   * @inheritDoc
   */
  public function run() {
    // Interface placeholder for plugins that have no run phase

  }

  /**
   * @inheritDoc
   */
  public function getChildTasks() {
    // TODO: Implement getChildTasks() method.
  }

  /**
   * @inheritDoc
   */
  public function setChildTasks($buildTasks) {
    // TODO: Implement setChildTasks() method.
  }

  /**
   * @inheritDoc
   */
  public function getDefaultConfiguration() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function terminateBuild($errorLabel, $errorDetails = '') {
    $this->io->drupalCIError($errorLabel, $errorDetails);
    throw new BuildTaskException($errorLabel, $errorDetails);
  }

  /**
   * {@inheritdoc}
   */
  public function terminateBuildWithFail($errorLabel, $errorDetails = '') {
    $this->io->drupalCIError($errorLabel, $errorDetails);
    throw new HaltingFailException($errorLabel, $errorDetails);
  }

}
