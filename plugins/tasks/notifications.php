<?php

class notifications implements EndpointInterface
{

  private TaskGateway $gateway;

  public function __construct (TaskGateway $gateway)
  {
    $this->gateway = $gateway;
  }

  /**
   * @return array
   * Part of the interface of orchestrator plugin to treat GET method
   */
  public function processEndPointGet (): array
  {
    return [];
  }

  /**
   * @return array
   */
  public function processEndPointPost (): array
  {
    return [];
  }

  /**
   * @return array
   */
  public function processEndPointPatch (): array
  {
    return [];
  }

  /**
   * @return array
   */
  public function processEndPointDelete (): array
  {
    return [];
  }
}