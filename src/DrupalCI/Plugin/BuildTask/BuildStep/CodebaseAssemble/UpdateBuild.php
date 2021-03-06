<?php

namespace DrupalCI\Plugin\BuildTask\BuildStep\CodebaseAssemble;

use DrupalCI\Plugin\BuildTaskBase;
use Pimple\Container;

/**
 * Reload the assessment phase if drupalci.yml has changed.
 *
 * @PluginID("update_build")
 */
class UpdateBuild extends BuildTaskBase {

  /**
   * @var \DrupalCI\Build\Codebase\CodebaseInterface
   */
  protected $codebase;

  /**
   * YAML parser service
   *
   * @var \Symfony\Component\Yaml\Yaml
   */
  protected $yaml;

  public function inject(Container $container) {
    parent::inject($container);
    $this->codebase = $container['codebase'];
    $this->yaml = $container['yaml.parser'];
  }

  /**
   * Figure out where the drupalci.yml file should be.
   *
   * @return string
   *   Relative path where we expect to find a drupalci.yml file.
   */
  protected function locateDrupalCiYmlFile() {
    $path = [];
    $path[] = $this->codebase->getProjectConfigDirectory(FALSE);
    $path[] = 'drupalci.yml';
    return implode(DIRECTORY_SEPARATOR, $path);
  }

  /**
   * Figure out whether to replace the assessment stage.
   *
   * @return bool
   *   TRUE if you should replace the assessment stage with drupalci.yml.
   */
  protected function shouldReplaceAssessmentStage() {
    // Is drupalci.yml modified?
    return in_array(
      $this->locateDrupalCiYmlFile(),
      $this->codebase->getModifiedFiles()
    );
  }

  /**
   * {@inheritDoc}
   */
  public function run() {
    // The rules:
    // - This plugin should never halt the build.
    // - Is drupalci.yml modified? No? We're done.
    // - If yes, replace the build's assessment phase with the contents of
    //   drupalci.yml for the current event.

    if (!$this->shouldReplaceAssessmentStage()) {
      $this->io->writeln('This build does not contain a modified drupalci.yml file. Using existing assessment stage.');
      return 0;
    }

    // Sanity-check whether the file exists. If it doesn't, it could mean that
    // the patch removed it. Did the patch author remove it to prevent the
    // assessment stage from happening? If so, they should have set it to
    // contain an empty array, because at this point in the code, we can't read
    // a missing file.
    $drupalci_yml_file = $this->codebase->getSourceDirectory() . DIRECTORY_SEPARATOR . $this->locateDrupalCiYmlFile();
    if (!file_exists($drupalci_yml_file)) {
      $this->io->writeln($drupalci_yml_file . ' does not exist');
      return 0;
    }

    // If we've gotten this far, then we have a patched drupalci.yml. The patch
    // could be trying to remove the assessment stage (by placing an empty array
    // in the YML), so we should always use it.
    $build_target = $this->build->getBuildTarget();
    $this->io->writeln("Replacing ${build_target}:assessment stage with ${drupalci_yml_file}");

    $drupalci_yml = $this->yaml->parse(file_get_contents($drupalci_yml_file));

    $assessment_stage = [];
    if (isset($drupalci_yml[$build_target]['assessment'])) {
      $assessment_stage = $drupalci_yml[$build_target]['assessment'];
    }

    $this->build->setAssessmentBuildDefinition($assessment_stage);

    return 0;
  }

}
