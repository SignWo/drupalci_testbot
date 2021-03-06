<?php

namespace DrupalCI\Build\Environment;

class CommandResult implements CommandResultInterface {

  /**
   * @var int
   */
  protected $signal = 0;

  /**
   * @var string
   */
  protected $output = '';

  /**
   * @var string
   */
  protected $error = '';

  /**
   * @return int
   */
  public function getSignal() {
    return $this->signal;
  }

  /**
   * @param int $signal
   */
  public function setSignal($signal) {
    $this->signal = $signal;
  }

  /**
   * @return string
   */
  public function getOutput() {
    return trim($this->output);
  }

  /**
   * @param string $output
   */
  public function appendOutput($output) {
    if (empty($this->output)) {
      $this->output = $output;
    } else {
      $this->output = $this->output . "\n" . $output;
    }
  }

  /**
   * @return string
   */
  public function getError() {
    return $this->error;
  }

  /**
   * @param string $error
   */
  public function appendError($error) {
    if (empty($this->error)) {
      $this->error = $error;
    } else {
      $this->error = $this->error . "\n" . $error;
    }

  }

}
