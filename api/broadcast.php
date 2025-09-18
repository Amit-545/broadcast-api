<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

class VercelBroadcastAPI {
    private $temp_path;
    
    public function __construct() {
        $this->temp_path = $_ENV['TEMP_STORAGE_PATH'] ?? '/tmp';
        
        // Create temp directory if not exists
        if (!file_exists($this->temp_path)) {
            mkdir($this->temp_path, 0777, true);
        }
    }
    
    public function startBroadcast() {
        // Get parameters from URL
        $bot_token = $_GET['bot'] ?? null;
        $user_ids = $_GET['userids'] ?? null;
        $owner_id = $_GET['owner'] ?? null;
        $message_json = $_GET['message'] ?? null;
        
        // Validate required parameters
        if (!$bot_token || !$user_ids || !$owner_id || !$message_json) {
            return $this->response(['error' => 'Missing required parameters: bot, userids, owner, message'], 400);
        }
        
        // Parse message JSON
        $message = json_decode(urldecode($message_json), true);
        if (!$message) {
            return $this->response(['error' => 'Invalid message JSON format'], 400);
        }
        
        // Parse user IDs (comma-separated or single ID)
        $subscriber_ids = is_array($user_ids) ? $user_ids : explode(',', $user_ids);
        $subscriber_ids = array_filter(array_map('trim', $subscriber_ids));
        
        if (empty($subscriber_ids)) {
            return $this->response(['error' => 'No valid user IDs provided'], 400);
        }
        
        // Create unique broadcast ID
        $broadcast_id = uniqid('broadcast_', true);
        
        // Split subscribers into chunks (30 per chunk for 60s timeout safety)
        $chunks = array_chunk($subscriber_ids, 30);
        $total_chunks = count($chunks);
        
        // Store broadcast data
        $broadcast_data = [
            'broadcast_id' => $broadcast_id,
            'bot_token' => $bot_token,
            'owner_id' => $owner_id,
            'message' => $message,
            'total_subscribers' => count($subscriber_ids),
            'total_chunks' => $total_chunks,
            'completed_chunks' => 0,
            'sent_count' => 0,
            'failed_count' => 0,
            'status' => 'processing',
            'created_at' => time()
        ];
        
        // Save main broadcast data
        file_put_contents("{$this->temp_path}/broadcast_{$broadcast_id}.json", json_encode($broadcast_data));
        
        // Save chunks data
        foreach ($chunks as $chunk_index => $chunk) {
            $chunk_data = [
                'broadcast_id' => $broadcast_id,
                'chunk_index' => $chunk_index,
                'subscriber_ids' => $chunk,
                'status' => 'pending'
            ];
            file_put_contents("{$this->temp_path}/chunk_{$broadcast_id}_{$chunk_index}.json", json_encode($chunk_data));
        }
        
        // Start processing first chunk
        $this->triggerChunkProcessing($broadcast_id, 0);
        
        return $this->response([
            'success' => true,
            'broadcast_id' => $broadcast_id,
            'total_subscribers' => count($subscriber_ids),
            'total_chunks' => $total_chunks,
            'message' => 'Broadcast queued successfully',
            'estimated_time' => ceil(count($subscriber_ids) / 10) . ' seconds'
        ]);
    }
    
    private function triggerChunkProcessing($broadcast_id, $chunk_index) {
        $webhook_url = "https://" . $_SERVER['HTTP_HOST'] . "/api/process-chunk?broadcast_id={$broadcast_id}&chunk_index={$chunk_index}";
        
        // Make async request to process chunk
        $this->makeAsyncRequest($webhook_url);
    }
    
    private function makeAsyncRequest($url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 1,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
    
    private function response($data, $code = 200) {
        http_response_code($code);
        echo json_encode($data);
        return $data;
    }
}

// Handle request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $api = new VercelBroadcastAPI();
        $api->startBroadcast();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>
