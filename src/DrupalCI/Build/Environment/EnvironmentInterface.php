<?php
namespace DrupalCI\Build\Environment;

use Pimple\Container;

interface EnvironmentInterface {

  public function inject(Container $container);

  /**
   * @param string[] $commands
   *
   * @param null $container_id
   *
   * @param array $env_vars
   *
   * @return \DrupalCI\Build\Environment\CommandResultInterface
   *
   * Takes in an array of commands to execute on a container and returns a
   * CommandResult object with the signal, stdout, and stderr. Optional
   * container_id allows for a specific container to be selected.
   */
  public function executeCommands($commands, $container_id = NULL, $env_vars = []);

  public function startExecContainer($container);

  public function startServiceContainerDaemons($container);

  public function startChromeContainer($container);

  public function terminateContainers();

  public function createContainerNetwork();

  public function destroyContainerNetwork();

  public function getDatabaseContainer();

  public function getExecContainer();

  public function getContainerNetwork();
  /**
   * @return string
   *   The source directory mounted within the container.
   */
  public function getExecContainerSourceDir();

  /**
   * @return string
   *   The artifact directory on all containers
   */
  public function getContainerArtifactDir();

  /**
   * @return string
   *   The ancillary work directory on all containers
   */
  public function getContainerWorkDir();


  /**
   * @return string
   *   The composer cache directory inside the containers.
   */
  public function getContainerComposerCacheDir();

  /**
   * @return int
   *   The number of processors available in the host environment
   */
  public function getHostProcessorCount();

  /**
   * @return string
   *   The hostname of the chrome container.
   */
  public function getChromeContainerHostname();
}
