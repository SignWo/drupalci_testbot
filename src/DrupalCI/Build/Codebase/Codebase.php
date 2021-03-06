<?php



namespace DrupalCI\Build\Codebase;

use DrupalCI\Injectable;
use Pimple\Container;

class Codebase implements CodebaseInterface, Injectable {

  /**
   * Style object.
   *
   * @var \DrupalCI\Console\DrupalCIStyle
   */
  protected $io;

  /**
   * @var \DrupalCI\Build\BuildInterface
   */
  protected $build;

  protected $extensionProjectSubDirectory = '';

  /**
   * The name of the project under test.
   *
   * @var string
   */
  protected $projectName = 'drupal';

  /**
   * The type of the project under test - core, module, theme, distribution,
   * library etc.
   *
   * @var string
   */
  protected $projectType = 'core';

  protected $extensionPaths = '';

  /**
   * A storage variable for any modified files
   */
  protected $modified_files = [];

  /**
   * Any patches used to generate this codebase
   *
   * @var \DrupalCI\Build\Codebase\PatchInterface[]
   */
  protected $patches;

  public function inject(Container $container) {
    $this->io = $container['console.io'];
    $this->build = $container['build'];
  }

  public function addPatch(PatchInterface $patch) {
    if (!empty($this->patches) && !in_array($patch, $this->patches)) {
      $this->patches[] = $patch;
    }
  }

  public function getModifiedFiles() {
    return $this->modified_files;
  }

  /**
   * {@inheritdoc}
   */
  public function getModifiedPhpFiles() {
    $host_source_dir = $this->getSourceDirectory();
    $phpfiles = [];
    foreach ($this->modified_files as $file) {
      $file_path = $host_source_dir . "/" . $file;
      // Checking for: if not in a vendor dir, if the file still exists, and if the first 32 (length - 1) bytes of the file contain <?php
      if (file_exists($file_path)) {
        $isphpfile = strpos(fgets(fopen($file_path, 'r'), 33), '<?php') !== FALSE;
        $not_vendor = strpos($file, 'vendor/') === FALSE;
        $not_phar = strpos($file, '.phar') === FALSE;
        if ($not_phar && $not_vendor && $isphpfile) {
          $phpfiles[] = $file;
        }
      }
    }
    return $phpfiles;
  }

  public function addModifiedFile($filename) {
    // Codebase' modified files should be a relative path and not
    // contain the host or container environments' source path.
    if (substr($filename, 0, strlen($this->getSourceDirectory())) == $this->getSourceDirectory()) {
      $filename = substr($filename, strlen($this->getSourceDirectory()) + 1);
    }
    if (!in_array($filename, $this->modified_files)) {
      $this->modified_files[] = $filename;
    }
  }

  public function addModifiedFiles($files) {
    foreach ($files as $file) {
      $this->addModifiedFile($file);
    }
  }

  /**
   * @inheritDoc
   */
  public function getSourceDirectory() {
    return $this->build->getSourceDirectory();
  }

  /**
   * {@inheritdoc}
   */
  public function getProjectName() {
    return $this->projectName;
  }

  /**
   * {@inheritdoc}
   */
  public function setProjectName($projectName) {
    $this->projectName = $projectName;
  }

  /**
   * @inheritDoc
   */
  public function getProjectType() {
    return $this->projectType;
  }

  /**
   * @inheritDoc
   */
  public function setProjectType($projectType) {
    $this->projectType = $projectType;
  }

  /**
   * @inheritDoc
   */
  public function getExtensionProjectSubdir() {
    return $this->extensionProjectSubDirectory;
  }

  /**
   * @param string $extensionProjectDir
   */
  public function setExtensionProjectSubdir($extensionProjectDir) {
    $this->extensionProjectSubDirectory = $extensionProjectDir;
  }

  /**
   * @return string
   */
  public function getExtensionPaths() {
    return $this->extensionPaths;
  }

  /**
   * @param string $extensionPaths
   */
  public function setExtensionPaths($extensionPaths) {
    $this->extensionPaths = $extensionPaths;
  }

  /**
   * Returns a list of require-dev packages for the current project.
   *
   * @return array
   */
  public function getComposerDevRequirements() {
    $packages = [];
    $installed_json = $this->getInstalledComposerPackages();
    foreach ($installed_json as $package) {
      if ($package['name'] == "drupal/" . $this->projectName) {
        if (!empty($package['require-dev'])) {
          $this->io->writeln("<error>Adding testing (require-dev) dependencies.</error>");
          foreach ($package['require-dev'] as $dev_package => $constraint) {
            $packages[] = escapeshellarg($dev_package . ":" . $constraint);
          }
        }
      }
    }
    return $packages;
  }

  public function getInstalledComposerPackages() {
    $installed_json = [];
    $install_json = $this->getSourceDirectory() . '/vendor/composer/installed.json';
    if (file_exists($install_json)) {
      $installed_json = json_decode(file_get_contents($install_json), TRUE);
    }
    return $installed_json;
  }

  /**
   * {@inheritdoc}
   */
  public function getProjectSourceDirectory($absolute = TRUE) {
    $project_type = $this->getProjectType();

    if ($project_type == 'core') {
      $project_dir = '';
    } else {
      $project_dir = "{$this->extensionPaths[$project_type]}/{$this->projectName}";
    }

    if ($absolute) {
      return "{$this->getSourceDirectory()}/{$project_dir}";
    } else {
      return $project_dir;
    }

  }

  /**
   * {@inheritdoc}
   */
  public function getProjectConfigDirectory($absolute = TRUE) {

    if ($this->getProjectType() == 'core') {
      if ($absolute) {
        return "{$this->getProjectSourceDirectory($absolute)}/core";
      } else {
        return 'core';
      }
    }
    // Config dir and project source dir are the same thing for everything
    // but core.
    return $this->getProjectSourceDirectory($absolute);
   }
}
