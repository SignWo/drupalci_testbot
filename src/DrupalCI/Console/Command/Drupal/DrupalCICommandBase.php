<?php

namespace DrupalCI\Console\Command\Drupal;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use DrupalCI\Providers\ConsoleIOServiceProvider;

/**
 * Just some helpful debugging stuff for now.
 */
class DrupalCICommandBase extends Command {

  /**
   * The container object.
   *
   * @var \Pimple\Container
   */
  protected $container;


  /**
   * Style object.
   *
   * @var \DrupalCI\Console\DrupalCIStyle
   */
  protected $io;

  /**
   * {@inheritdoc}
   */
  protected function initialize(InputInterface $input, OutputInterface $output) {
    parent::initialize($input, $output);
    // Perform some container set-up before command execution.
    $this->container = $this->getApplication()->getContainer();
    $this->container->register(new ConsoleIOServiceProvider($input, $output));
    $this->io = $this->container['console.io'];
  }

}
