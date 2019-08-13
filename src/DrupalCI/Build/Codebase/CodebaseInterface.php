<?php

namespace DrupalCI\Build\Codebase;

interface CodebaseInterface {

  public function addPatch(PatchInterface $patch);

  /**
   * Get a list of files modified by the patch.
   *
   * @return string[]
   */
  public function getModifiedFiles();

  /**
   * Returns an array of modified php files, relative to the source directory.
   */
  public function getModifiedPhpFiles();

  public function addModifiedFile($filename);

  public function addModifiedFiles($files);

  /**
   * This is the codebase that we will test. It should be volume mounted over
   * to wherever the $execContainerSourceDir is set on the Environment object.
   * It proxies through to the build, since the build is the directory master.
   *
   * @return string
   */
  public function getSourceDirectory();

  /**
   * ExtensionProjectSubDir is what gets passed to us via the --directory
   * command. It is *not* where the extensions actually exist.
   *
   * @return string
   */
  public function getExtensionProjectSubdir();

  public function setExtensionProjectSubdir($extensionDir);

  /**
   * The name of the project under test.
   *
   * @return string
   */
  public function getProjectName();

  public function setProjectName($projectName);

  /**
   * The type of the project under test - core, module, theme, distribution,
   * library etc.
   *
   * @return string
   */
  public function getProjectType();

  public function setProjectType($projectName);

  /**
   * For contributed modules, this is where the modules will get checked out
   * Needed so we can know where to run the tests.
   * It is a key value array of extension type to path location
   *
   * @return array
   */
  public function getExtensionPaths();

  public function setExtensionPaths($extensionPaths);

  public function getComposerDevRequirements();

  public function getInstalledComposerPackages();

  /**
   * Path on the host directory that is the 'root' of the project
   * under test. Defaults to absolute. Pass in FALSE for relative paths.
   *
   * For core this is equivalent to DRUPAL_ROOT. For extensions this will be
   * where composer-installers would place the project.
   *
   * @param bool $absolute
   *
   * @return string
   */
  public function getProjectSourceDirectory($absolute);

  /**
   * Absolute path on the host where the config files is located, per project
   *
   * Basically translates into 'core' or the extension source dir.
   *
   * @param bool $absolute
   *
   * @return string
   */
  public function getProjectConfigDirectory($absolute);

}
