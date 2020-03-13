<?php

namespace DrupalCI\Plugin\BuildTask\BuildStep\Testing;

use DrupalCI\Build\BuildInterface;
use Pimple\Container;

/**
 * @PluginID("run_tests_d7")
 */
class RunTestsD7 extends RunTests {

  protected $runscript = '/scripts/run-tests.sh';

  /**
   * {@inheritdoc}
   */
  public function inject(Container $container) {
    // D7 uses the same database for both system and results, so we'll adjust
    // it here, after we setup the parent.
    parent::inject($container);
    $this->results_database = $container['db.system'];
  }

  /**
   * @param \DrupalCI\Build\BuildInterface $build
   *
   * @return int
   * @throws \DrupalCI\Plugin\BuildTask\BuildTaskException
   */
  protected function setupSimpletestDB(BuildInterface $build) {
    $dburl = $this->system_database->getUrl();
    // Fixes sqlite for d7
    if ($this->system_database->getDbType() === 'sqlite' ) {
      $dburl = preg_replace('/localhost\//', '', $dburl);
      $this->system_database->setUrl($dburl);
      $dbfile = $this->codebase->getSourceDirectory() . preg_replace('/sqlite:\//', '', $dburl);
      $this->results_database->setDBFile($dbfile);
      $this->results_database->setDbname('');
    }

    $sourcedir = $this->environment->getExecContainerSourceDir();
    $setup_commands = [
      'cd ' . $sourcedir . ' && sudo -u www-data DRUSH_NO_MIN_PHP=1 /usr/local/bin/drush -r ' . $sourcedir . ' si -y --db-url=' . $dburl . ' --clean-url=0 --account-name=admin --account-pass=drupal --account-mail=admin@example.com',
      'cd ' . $sourcedir . ' && sudo -u www-data DRUSH_NO_MIN_PHP=1 /usr/local/bin/drush -r ' . $sourcedir . ' vset simpletest_clear_results \'0\' 2>&1',
      'cd ' . $sourcedir . ' && sudo -u www-data DRUSH_NO_MIN_PHP=1 /usr/local/bin/drush -r ' . $sourcedir . ' vset simpletest_verbose \'0\' 2>&1',
      'cd ' . $sourcedir . ' && sudo -u www-data DRUSH_NO_MIN_PHP=1 /usr/local/bin/drush -r ' . $sourcedir . ' en -y simpletest 2>&1',
    ];
    $this->execRequiredEnvironmentCommands($setup_commands, "Drush setup of Drupal Failed");

    return 0;
  }

  /**
   * Turn run-test.sh flag values into their command-line equivalents.
   *
   * @param type $config
   *   This plugin's config, from run().
   *
   * @return string
   *   The assembled command line fragment.
   */
  protected function getRunTestsFlagValues($config) {
    $command = [];
    $flags = [
      'color',
      'die-on-fail',
      'verbose',
    ];
    foreach ($config as $key => $value) {
      if (in_array($key, $flags)) {
        if ($value) {
          $command[] = "--$key";
        }
      }
    }
    return implode(' ', $command);
  }

  /**
   * Turn run-test.sh values into their command-line equivalents.
   *
   * @param type $config
   *   This plugin's config, from run().
   *
   * @return string
   *   The assembled command line fragment.
   */
  protected function getRunTestsValues($config) {
    $command = [];
    $args = [
      'concurrency',
      'url',
    ];
    if (empty($config['concurrency'])) {
      $config['concurrency'] = $this->environment->getHostProcessorCount();
    }
    foreach ($config as $key => $value) {
      if (in_array($key, $args)) {
        if ($value) {
          $command[] = "--$key \"$value\"";
        }
      }
    }
    return implode(' ', $command);
  }

  /**
   * @param $test_list
   *
   * @return array
   */
  protected function parseGroups($test_list) {
    // Set an initial default group, in case leading tests are found with no group.
    $group = 'nogroup';
    $test_groups = [];

    foreach ($test_list as $output_line) {
      if (substr($output_line, 0, 3) == ' - ') {
        // This is a class
        $lineparts = explode(' ', $output_line);
        $class = str_replace(['(', ')'], '', end($lineparts));
        $test_groups[$class] = $group;
      }
      else {
        // This is a group
        $group = ucwords($output_line);
      }
    }
    return $test_groups;
  }

}
