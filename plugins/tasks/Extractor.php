<?php

class Extractor implements EndpointInterface
{
  private TaskGateway $gateway;

  public function __construct(TaskGateway $gateway)
  {
    $this->gateway = $gateway;
  }

  /**
   * @return array
   * Part of the interface of orchestrator plugin to treat GET method
   */
  public function processEndPointGet(): array
  {
    // Retrieve tasks of type 'extract'
    return $this->gateway->getObjectTypeTask('extract');
  }

  /**
   * @param array|null $data
   * @return array
   * Note: Part of the interface of orchestrator plugin to treat POST method
   */
  public function processEndPointPost(array $data = NULL): array
  {
    return [];
  }

  /**
   * @param array|null $data
   * @return array
   * Note: Part of the interface of orchestrator plugin to treat DELETE method
   */
  public function processEndPointDelete(array $data = NULL): array
  {
    return [];
  }

  /**
   * @param array|null $data
   * @return array
   * @throws Exception
   * Note: Part of the interface of orchestrator plugin to treat PATCH method
   */
  public function processEndPointPatch(array $data = NULL): array
  {
    $result = [];
    $extractTasks = $this->gateway->getObjectTypeTask('extract');
    
    // Path is now expected in the JSON body ($data)
    $path = $data['path'] ?? '/srv/orchestrator/';

    foreach ($extractTasks as $task) {
      try {
        if (!$this->gateway->statusAndScheduleCheck($task)) {
          // Skip this task if it does not meet the status and schedule criteria
          continue;
        }

        // Get the main task configuration
        $mainTaskConfig = $this->getExtractMainTaskConfig($task['fdtasksgranularmaster'][0]);
        
        // Get user DN from the task
        $userDn = $task['fdtasksgranulardn'][0];
        
        // Get user attributes
        $userAttributes = $this->getUserAttributes($userDn, $mainTaskConfig);
        
        // Format comes from the main task configuration
        $format = isset($mainTaskConfig[0]['fdextractortaskformat']) ? 
                 strtolower($mainTaskConfig[0]['fdextractortaskformat'][0]) : 'csv';
        
        // Create directory if it doesn't exist
        $this->ensureDirectoryExists($path);
        
        // Get main task CN for filename
        $mainTaskCn = $this->getMainTaskCn($task['fdtasksgranularmaster'][0]);
        
        // Determine filename with main task name and date with hours
        $date = date('Y-m-d_H-i-s');
        $filename = isset($data['filename']) ? 
                   $path . $data['filename'] . '_' . $date . '.' . $format : 
                   $path . $mainTaskCn . '_' . $date . '.' . $format;
        
        // Extract and write to file
        $success = $this->extractToFile($userAttributes, $filename, $format);
        
        if ($success) {
          $result[$task['dn']]['result'] = "User attributes successfully extracted to $filename";
          $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2');
        } else {
          throw new Exception("Failed to write data to $filename");
        }
        
      } catch (Exception $e) {
        $result[$task['dn']]['result'] = "Error extracting user attributes: " . $e->getMessage();
        $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $e->getMessage());
      }
    }

    return $result;
  }

  /**
   * @param string $mainTaskDn
   * @return array
   * Note: Retrieve the configuration from the main extract task.
   */
  private function getExtractMainTaskConfig(string $mainTaskDn): array
  {
    return $this->gateway->getLdapTasks(
      '(objectClass=fdExtractorTasks)',
      ['fdExtractorTaskFormat', 'cn'],
      '',
      $mainTaskDn
    );
  }

  /**
   * @param string $userDn
   * @param array $mainTaskConfig
   * @return array
   * Note: Get all user attributes from the user DN.
   */
  private function getUserAttributes(string $userDn, array $mainTaskConfig): array
  {
    // Get all user data from LDAP
    $userData = $this->gateway->getLdapTasks(
      '(objectClass=*)',
      ['*'],
      '',
      $userDn
    );
    
    // Process and return user data
    $this->gateway->unsetCountKeys($userData);
    return $userData;
  }

  /**
   * @param string $path
   * @return bool
   * @throws Exception
   * Note: Create directory if it doesn't exist.
   */
  private function ensureDirectoryExists(string $path): bool
  {
    if (!is_dir($path)) {
      if (!mkdir($path, 0755, true)) {
        throw new Exception("Failed to create directory: $path");
      }
    }
    return true;
  }

  /**
   * @param array $userAttributes
   * @param string $filename
   * @param string $format
   * @return bool
   * @throws Exception
   * Note: Extract user attributes to a file.
   */
  private function extractToFile(array $userAttributes, string $filename, string $format): bool
  {
    switch (strtolower($format)) {
      case 'csv':
        return $this->exportToCsv($userAttributes, $filename);
      case 'json':
        return $this->exportToJson($userAttributes, $filename);
      case 'xml':
        return $this->exportToXml($userAttributes, $filename);
      default:
        return $this->exportToCsv($userAttributes, $filename);
    }
  }

  /**
   * @param array $userAttributes
   * @param string $filename
   * @return bool
   * @throws Exception
   * Note: Export user attributes to CSV.
   */
  private function exportToCsv(array $userAttributes, string $filename): bool
  {
    $fileExists = file_exists($filename);
    $handle = fopen($filename, 'a');
    
    if ($handle === false) {
      throw new Exception("Could not open file: $filename");
    }
    
    try {
      if (empty($userAttributes)) {
        return true; // No attributes to write
      }
      
      $user = $userAttributes[0];
      $columns = [];
      $data = [];
      
      // Prepare columns and data
      foreach ($user as $attribute => $values) {
        if (is_array($values)) {
          foreach ($values as $key => $value) {
            if (is_numeric($key)) {
              $columns[] = $attribute;
              $data[] = $value;
              break; // Only take the first value for simplicity
            }
          }
        }
      }
      
      // Write header if file is new
      if (!$fileExists) {
        fputcsv($handle, $columns);
      }
      
      // Write data
      fputcsv($handle, $data);
      
      return true;
    } finally {
      fclose($handle);
    }
  }

  /**
   * @param array $userAttributes
   * @param string $filename
   * @return bool
   * Note: Export user attributes to JSON.
   */
  private function exportToJson(array $userAttributes, string $filename): bool
  {
    $existingData = [];
    
    // Read existing data if file exists
    if (file_exists($filename)) {
      $existingJson = file_get_contents($filename);
      if (!empty($existingJson)) {
        $existingData = json_decode($existingJson, true) ?? [];
      }
    }
    
    // Add new data
    $existingData[] = $userAttributes[0];
    
    // Write back to file
    return file_put_contents($filename, json_encode($existingData, JSON_PRETTY_PRINT)) !== false;
  }

  /**
   * @param array $userAttributes
   * @param string $filename
   * @return bool
   * Note: Export user attributes to XML.
   */
  private function exportToXml(array $userAttributes, string $filename): bool
  {
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;
    
    // Create root element if file doesn't exist
    if (file_exists($filename)) {
      $dom->load($filename);
      $root = $dom->documentElement;
    } else {
      $root = $dom->createElement('users');
      $dom->appendChild($root);
    }
    
    // Add new user element
    $user = $dom->createElement('user');
    
    foreach ($userAttributes[0] as $attribute => $values) {
      if (is_array($values)) {
        foreach ($values as $key => $value) {
          if (is_numeric($key)) {
            $attr = $dom->createElement($attribute, htmlspecialchars($value));
            $user->appendChild($attr);
          }
        }
      }
    }
    
    $root->appendChild($user);
    
    // Write to file
    return $dom->save($filename) !== false;
  }

  /**
   * Get the CN (Common Name) of the main task
   * 
   * @param string $mainTaskDn
   * @return string
   */
  private function getMainTaskCn(string $mainTaskDn): string
  {
    $mainTask = $this->gateway->getLdapTasks(
      '(objectClass=*)',
      ['cn'],
      '',
      $mainTaskDn
    );
    
    return $mainTask[0]['cn'][0] ?? 'extract';
  }
}