<?php

namespace Build\Environment;


/**
 * Interface DatabaseInterface
 *
 * @package Build\Environment
 */
interface DatabaseInterface {

  /**
   * Returns a PDO connection to this database
   *
   * @return \PDO
   */
  public function getConnection();

  /**
   * Sets a PDO connection to this database
   *
   * @param $connection
   *
   * @return \PDO
   */
  public function setConnection($connection);

  /**
   * Returns the version number of the database Software
   *
   * @return string
   */
  public function getVersion();

  /**
   * Sets the version number of the database Software
   *
   * @param $version
   *
   * @return string
   */
  public function setVersion($version);

  /**
   * Returns a string representing the type, e.g. mysql, pgsql, sqlite, mariadb
   *
   * @return string
   */
  public function getType();

  /**
   * Sets a string representing the type, e.g. mysql, pgsql, sqlite, mariadb
   *
   * @return string
   */
  public function setType();

  /**
   * Returns the full url used to connect to the db.
   *
   * @return string
   */
  public function getUrl();

  /**
   * Returns the username needed to connect to this database
   *
   * @return string
   */
  public function getUsername();

  /**
   * Sets the username needed to connect to this database
   *
   * @param $username
   *
   * @return string
   */
  public function setUsername($username);

  /**
   * Returns the port that the database is listening on. Maybe someday socket support?
   *
   * @return string
   */
  public function getPort();

  /**
   * Sets the port that the database is listening on. Maybe someday socket support?
   *
   * @param $port
   *
   * @return string
   */
  public function setPort($port);

  /**
   * Returns the password used to connect to the database
   *
   * @return string
   */
  public function getPassword();

  /**
   * Sets the password used to connect to the database
   *
   * @param $password
   *
   * @return string
   */
  public function setPassword($password);

  /**
   * Returns the hostname where this database lives
   *
   * @return string
   */
  public function getHost();

  /**
   * Sets the hostname where this database lives
   *
   * @param $host
   *
   * @return string
   */
  public function setHost($host);

  /**
   * Returns the contents of the config file (my.cnf?)
   *
   * @return string
   */
  public function getConfigurationFile();

  /**
   * Sets the contents of the config file (my.cnf?)
   *
   * @param $configuration_file
   *
   * @return string
   */
  public function setConfigurationFile($configuration_file);
}
