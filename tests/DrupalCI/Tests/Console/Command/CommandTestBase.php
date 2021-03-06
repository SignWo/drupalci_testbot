<?php

namespace DrupalCI\Tests\Console\Command;

use DrupalCI\Providers\DrupalCIServiceProvider;
use PHPUnit\Framework\TestCase;
use Pimple\Container;

abstract class CommandTestBase extends TestCase {

  /**
   * The service container.
   *
   * @var \Pimple\Container
   */
  protected $container;

  /**
   * @return \Pimple\Container
   */
  protected function getContainer() {
    if (empty($this->container)) {
      $this->container = new Container();
      $this->container->register(new DrupalCIServiceProvider());
    }
    return $this->container;
  }

  /**
   *
   * @return \DrupalCI\Console\DrupalCIConsoleApp
   */
  protected function getConsoleApp() {
    $container = $this->getContainer();
    return $container['console'];
  }

  protected function getInputStream($input) {
    $stream = fopen('php://memory', 'r+', FALSE);
    fputs($stream, $input);
    rewind($stream);
    return $stream;
  }

}
