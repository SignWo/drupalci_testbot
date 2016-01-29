<?php
/**
 * @file
 * Contains \DrupalCI\Plugin\BuildSteps\publish\EmailResults
 *
 * Processes "publish: email:" instructions from within a job definition.
 * Gathers the resulting job artifacts and pushes them to an email address.
 */

namespace DrupalCI\Plugin\BuildSteps\postprocess;
use DrupalCI\Plugin\PluginBase;

/**
 * @PluginID("email_results")
 */
class EmailResults extends PluginBase {

  /**
   * {@inheritdoc}
   */
  public function run() {
    echo 'EmailResults plugin not yet implemented.';
  }

}
