<?php

namespace DrupalCI\Build;

interface BuildInterface {

  /**
   * @return string
   */
  public function getBuildType();

  /**
   * @return string
   *   The current build target key. Currently always returns 'build'.
   *
   * @see https://www.drupal.org/project/drupalci_testbot/issues/2951375
   */
  public function getBuildTarget();

  /**
   * @return string
   */
  public function getBuildId();

  /**
   * @param string
   */
  public function setBuildId($id);

  /**
   * @return string
   *
   * The filename that was originally used to define this build.
   */
  public function getBuildFile();

  /**
   * @param string $build
   *
   * Either the full path to a build.yml file, or the name of one of
   * the predefined build_definitions like development or legacydevelopment, or if
   * null, defaults to development.  Once it loads the yaml definition, it
   * recursively iterates over the definition creating and configuring the
   * build plugins for this build.
   */
  public function generateBuild($build);

  /**
   * Executes a configured build.
   *
   * @return mixed
   */
  public function executeBuild();

  /**
   * This is the directory where we place everything specific to this build
   * The primary exception of something that is needed that does not live
   * under the build directory is the Database.
   *
   * @return mixed
   */
  public function getBuildDirectory();

  /**
   * This is the directory where we place all of our artifacts.
   *
   * @return mixed
   */
  public function getArtifactDirectory();

  /**
   * Temporary workspace directory where we can checkout repositories and
   * manipulate them prior to adding them to the main source directory.
   * Primarily used to check out a project, apply patches to composer.json,
   * and require that project as a local composer repo in order to see the
   * changed dependencies.
   *
   * @return string
   */
  public function getAncillaryWorkDirectory();

  /**
   * This is the directory where core dumps should end up on the host os
   *
   * @return mixed
   */
  public function getHostCoredumpDirectory();

  /**
   * This is the directory on the host where composer cache lives.
   *
   * @return mixed
   */
  public function getHostComposerCacheDirectory();

  /**
   * This is the directory where we place artifacts that can be parsed
   * by jenkins xml parsing. It is usually located *under* the artifacts
   * directory
   *
   * @return mixed
   */
  public function getXmlDirectory();

  /**
   * This is where we put the database It should be volume mounted over
   * to wherever the data directory specifies from the Database Environment
   *
   * @return mixed
   */
  public function getDBDirectory();

  /**
   * This is where we put the source code that will be volume mounted over
   * to the php containers
   *
   * @return mixed
   */
  public function getSourceDirectory();

  public function generateBuildId();

  public function addArtifact($path, $artifactpath);

  public function addContainerArtifact($containerpath, $artifactpath);

  /**
   * @param $filename
   * @param $string
   *
   * Takes in a string, and saves it as an artifact in the artifact directory.
   */
  public function addStringArtifact($filename, $string);

  public function getBuildArtifacts();

  public function setupDirectory($directory);

  /**
   * Set and parse the assessment build stage.
   *
   * @param string[] $assessment_stage
   *   Set the assessment stage build definition array, probably parsed from
   *   drupalci.yml. This is the section inside the 'assessment' key. Adding an
   *   empty array results in no assessment stage for the build.
   */
  public function setAssessmentBuildDefinition($assessment_stage);

  /**
   * Save the build definition after it has been modified in the codebase stage.
   */
  public function saveModifiedBuildDefiniton();

}
