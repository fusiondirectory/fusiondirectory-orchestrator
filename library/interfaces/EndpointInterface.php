<?php

interface EndpointInterface
{

  public function __construct (TaskGateway $gateway);

  /**
   * @return array
   * Part of the interface of orchestrator plugin to treat GET method
   */
  public function processEndPointGet (): array;

  /**
   * @param array|NULL $data
   * @return array
   * Note : Part of the interface of orchestrator plugin to treat POST method
   */
  public function processEndPointPost (array $data = NULL): array;

  /**
   * @param array|NULL $data
   * @return array
   * Note : Part of the interface of orchestrator plugin to treat PATCH method
   */
  public function processEndPointPatch (array $data = NULL): array;

  /**
   * @param array|NULL $data
   * @return array
   * Note : Part of the interface of orchestrator plugin to treat DELETE method
   */
  public function processEndPointDelete (array $data = NULL): array;
}
