<?php

namespace DrupalCI\Plugin\BuildTask\BuildStep\CodebaseAssemble;

use DrupalCI\Injectable;
use DrupalCI\Plugin\BuildTask\BuildStep\BuildStepInterface;
use DrupalCI\Plugin\BuildTask\BuildTaskInterface;

/**
 * @PluginID("checkout_core_d7")
 */
class DrupalCoreCheckoutD7 extends DrupalCoreCheckout implements BuildStepInterface, BuildTaskInterface, Injectable {

  protected function discoverExentionPaths() {
    $exension_paths = '';

    $extension_paths = [
      'module' => 'sites/all/modules',
      'theme' => 'sites/all/themes',
      'profile' => 'sites/all/profiles',
      ];
    return $extension_paths;
  }

  /**
   * @param string $source_dir
   *
   * @return void
   */
  protected function updateCoreLockfile(string $source_dir): void {
    // Do not do anything for d7.
  }

}
