<?php

namespace DrupalCI\Plugin\BuildTask\BuildStep\CodebaseAssemble;


use DrupalCI\Build\BuildInterface;
use DrupalCI\Build\Codebase\PatchFactoryInterface;
use DrupalCI\Injectable;
use DrupalCI\Plugin\BuildTask\BuildTaskException;
use DrupalCI\Plugin\BuildTask\BuildStep\BuildStepInterface;
use DrupalCI\Plugin\BuildTask\FileHandlerTrait;
use DrupalCI\Plugin\BuildTaskBase;
use DrupalCI\Plugin\BuildTask\BuildTaskInterface;
use DrupalCI\Build\Codebase\PatchInterface;
use DrupalCI\Build\Codebase\Patch as PatchFile;
use Pimple\Container;

/**
 * @PluginID("patch")
 */
class Patch extends BuildTaskBase implements BuildStepInterface, BuildTaskInterface, Injectable  {

  use FileHandlerTrait;

  /**
   * @var \DrupalCI\Build\Codebase\CodebaseInterface
   */
  protected $codebase;

  /**
   * @var \DrupalCI\Build\Codebase\PatchFactoryInterface
   */
  protected $patchFactory;

  public function inject(Container $container) {
    parent::inject($container);
    $this->codebase = $container['codebase'];
    $this->patchFactory = $container['patch_factory'];
  }

  /**
   * @inheritDoc
   */
  public function configure() {
    // @TODO make into a test
    // $_ENV['DCI_Patch']='https://www.drupal.org/files/issues/2796581-region-136.patch,.;https://www.drupal.org/files/issues/another.patch,.';
    if (isset($_ENV['DCI_Patch'])) {
      $this->configuration['patches'] = $this->process($_ENV['DCI_Patch']);
    }
  }

  /**
   * @inheritDoc
   */
  public function run() {

    $files = $this->configuration['patches'];

    if (empty($files)) {
      $this->io->writeln('No patches to apply.');
    }
    foreach ($files as $key => $details) {
      // Validate from.
      if (empty($details['from'])) {
        $this->io->drupalCIError("Patch error", "No valid patch file provided for the patch command.");
        throw new BuildTaskException('No valid patch file provided for the patch command.');
      }
      // Adjust 'to' so the patch applies to the correct place.
      if ($details['to'] == $this->codebase->getExtensionProjectSubdir()) {
        // This patch should be applied to wherever composer checks out to.
        $details['to'] = $this->codebase->getSourceDirectory() . '/' . $this->codebase->getTrueExtensionDirectory('modules');
      } else {
        $details['to'] = $this->codebase->getSourceDirectory();
      }
      // Create a new patch object
      $patch = $this->patchFactory->getPatch(
        $details,
        $this->codebase->getAncillarySourceDirectory()
      );
      $this->codebase->addPatch($patch);

      try {
        // Validate our patch's source file and target directory
        if (!$patch->validate()) {
          throw new BuildTaskException('Failed to validate the patch source and/or target directory.');
        }

        // Apply the patch
        if ($patch->apply() !== 0) {
          throw new BuildTaskException('Unable to apply the patch.');
        }
      }
      catch (BuildTaskException $e) {

        // Hack to save an xml file to the Jenkins artifact directory.
        // TODO: Remove once proper build failure processing is in place

        // Not all BuildTaskExceptions represent a failed command line
        // operation, so we have to handle that case.
        $output = '';

        $results = $patch->getPatchApplyResults();
        if (!empty($results)) {
          $output = preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '�', implode("\n", $results));
        }

        // Build the XML.
        $xml_error = '<?xml version="1.0"?>

                      <testsuite errors="1" failures="0" name="Error: Patch failed to apply" tests="1">
                        <testcase classname="Apply Patch" name="' . $patch->getFilename() . '">
                          <error message="Patch Failed to apply" type="PatchFailure">Patch failed to apply</error>
                        </testcase>
                        <system-out><![CDATA[' . $output . ']]></system-out>
                      </testsuite>';
        $output_directory = $this->build->getXmlDirectory();
        file_put_contents($output_directory . "/patchfailure.xml", $xml_error);

        throw $e;
      };
      // Update our list of modified files
      $this->codebase->addModifiedFiles($patch->getModifiedFiles());
    }
    return 0;
  }


  /**
   * @inheritDoc
   */
  public function getDefaultConfiguration() {
    return [
      'patches' => [],
    ];
  }

}
