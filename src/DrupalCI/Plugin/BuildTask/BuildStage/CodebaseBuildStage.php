<?php

namespace DrupalCI\Plugin\BuildTask\BuildStage;

use DrupalCI\Plugin\BuildTask\BuildTaskInterface;
use DrupalCI\Plugin\BuildTask\BuildStage\BuildStageInterface;
use DrupalCI\Plugin\BuildTaskBase;
use Pimple\Container;

/**
 * @PluginID("codebase")
 */

class CodebaseBuildStage extends BuildTaskBase  implements BuildStageInterface, BuildTaskInterface  {

  /* @var \DrupalCI\Build\Codebase\CodebaseInterface */
  protected $codebase;

  public function inject(Container $container) {
    parent::inject($container);
    $this->codebase = $container['codebase'];
  }

  /**
   * @inheritDoc
   */
  public function configure() {
    // DCI_TestItem may have --directory modules/<projectname>, if it does,
    // we can assume that it is the module we wish to test, and therefore needs
    // to be built in the codebase tmp directory and pointed to by composer.
    if (isset($_ENV['DCI_TestItem'])) {
      $this->configuration['project_dir'] = $this->getProjectSubDir($_ENV['DCI_TestItem']);
    }
    // @TODO: add an API for this vs. scraping it from DCI_TestItem

  }

  /**
   * @inheritDoc
   */
  public function run() {

    if (!empty($this->configuration['project_subdir'])){
      $this->codebase->setAncillaryProjectSubdir($projectDirectory);
    }
    $this->codebase->setupDirectories();

  }

  /**
   * @inheritDoc
   */
  public function getDefaultConfiguration() {
    return [
      'project_subdir' => '',
    ];

  }

  protected function getProjectSubDir($testitem) {
    if (strpos($testitem,'directory') == 0) {
      $components = explode(':', $testitem);
      return $components[1];
    }
    return FALSE;
  }
}
