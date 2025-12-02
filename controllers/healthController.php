<?php
function healthCheck()
{
  try {
    // Test database connection
    $db = getDB();
    $db->query('SELECT 1');

    Response::success([
      'status' => 'healthy',
      'database' => 'connected',
      'timestamp' => date('c')
    ]);
  } catch (Exception $e) {
    Response::error('Health check failed: ' . $e->getMessage(), 500);
  }
}
