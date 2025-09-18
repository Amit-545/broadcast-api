<?php
header('Content-Type: application/json');

class StatusChecker {
    private $temp_path;
    
    public function __construct() {
        $this->temp_path = $_ENV['TEMP_STORAGE_PATH'] ?? '/tmp';
    }
    
    public function getStatus() {
        $broadcast_id = $_GET['broadcast_id'] ?? null;
        
        if (!$broadcast_id) {
            return $this->response(['error' => 'Missing broadcast_id'], 400);
        }
        
        $broadcast_file = "{$this->temp_path}/broadcast_{$broadcast_id}.json";
        
        if (!file_exists($broadcast_file)) {
            return $this->response(['error' => 'Broadcast not found'], 404);
        }
        
        $broadcast_data = json_decode(file_get_contents($broadcast_file), true);
        
        $progress_percentage = round(($broadcast_data['completed_chunks'] / $broadcast_data['total_chunks']) * 100, 2);
        
        return $this->response([
            'broadcast_id' => $broadcast_id,
            'status' => $broadcast_data['status'],
            'progress' => $progress_percentage . '%',
            'total_subscribers' => $broadcast_data['total_subscribers'],
            'sent_count' => $broadcast_data['sent_count'],
            'failed_count' => $broadcast_data['failed_count'],
            'completed_chunks' => $broadcast_data['completed_chunks'],
            'total_chunks' => $broadcast_data['total_chunks'],
            'created_at' => date('Y-m-d H:i:s', $broadcast_data['created_at'])
        ]);
    }
    
    private function response($data, $code = 200) {
        http_response_code($code);
        echo json_encode($data);
        return $data;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $checker = new StatusChecker();
        $checker->getStatus();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>
