<?php

interface EndpointInterface {

  public function __construct($gateway);

  /**
   * @return array
   * Part of the interface of orchestrator plugin to treat GET method
   */
  public function processEndPointGet () : array;

  /**
   * @return array
   */
  public function processEndPointPost () : array;

  /**
   * @return array
   */
  public function processEndPointPatch () : array;

  /**
   * @return array
   */
  public function processEndPointDelete() : array;
}
