<?php
function healthCheck()
{
  try {
    // Test database connection
    $db = getDB();
    $db->query('SELECT 1');

    Response::success([
      'status' => 'ok',
      'database' => 'connected',
      'timestamp' => date('c')
    ]);
  } catch (Exception $e) {
    Response::success([
      'status' => 'ok',
      'database' => 'disconnected',
      'error' => $e->getMessage(),
      'timestamp' => date('c')
    ]);
  }
}
