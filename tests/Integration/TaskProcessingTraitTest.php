<?php

require_once __DIR__ . '/../../library/tasks/TaskGateway.php';
require_once __DIR__ . '/../../plugins/core/TaskProcessingTrait.php';

use PHPUnit\Framework\TestCase;

class TaskProcessingTraitTest extends TestCase
{
  private function createTestInstance(TaskGateway $gateway): object
  {
    return new class($gateway) {
      use TaskProcessingTrait;
      private TaskGateway $gateway;
      public function __construct(TaskGateway $gateway) { $this->gateway = $gateway; }
      protected function getMainTaskConfig(string $mainTaskDn): array { return []; }
      public function doProcessTask(array $task, callable $processor): array {
        return $this->processTask($task, $processor);
      }
    };
  }

  public function testProcessTaskReturnsEmptyWhenStatusCheckFails(): void
  {
    $gateway = $this->createMock(TaskGateway::class);
    $gateway->method('statusAndScheduleCheck')->willReturn(FALSE);

    $instance = $this->createTestInstance($gateway);
    $task = ['fdtasksgranularmaster' => ['cn=main,dc=example']];
    $result = $instance->doProcessTask($task, function ($task, $mainTaskDn, $repeatableSchedule) {
      return ['result' => 'should not reach'];
    });

    $this->assertEmpty($result);
  }

  public function testProcessTaskCallsProcessorWhenStatusCheckPasses(): void
  {
    $gateway = $this->createMock(TaskGateway::class);
    $gateway->method('statusAndScheduleCheck')->willReturn(TRUE);
    $gateway->method('extractRepeatableSchedule')->willReturn('0 8 * * 1');

    $instance = $this->createTestInstance($gateway);
    $task = ['fdtasksgranularmaster' => ['cn=main,dc=example']];
    $result = $instance->doProcessTask($task, function ($task, $mainTaskDn, $repeatableSchedule) {
      return ['result' => "processed $mainTaskDn with $repeatableSchedule"];
    });

    $this->assertEquals('processed cn=main,dc=example with 0 8 * * 1', $result['result']);
  }

  public function testProcessTaskPassesNullScheduleWhenNoRepeatable(): void
  {
    $gateway = $this->createMock(TaskGateway::class);
    $gateway->method('statusAndScheduleCheck')->willReturn(TRUE);
    $gateway->method('extractRepeatableSchedule')->willReturn(NULL);

    $instance = $this->createTestInstance($gateway);
    $task = ['fdtasksgranularmaster' => ['cn=main,dc=example']];
    $result = $instance->doProcessTask($task, function ($task, $mainTaskDn, $repeatableSchedule) {
      return ['schedule' => $repeatableSchedule];
    });

    $this->assertNull($result['schedule']);
  }
}
