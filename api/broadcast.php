<?php
ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_flush();
    exit(0);
}

class VercelBroadcastAPI {
    private $max_parallel = 10; // Process 10 requests simultaneously
    
    public function startBroadcast() {
        // Handle both GET and POST requests
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (!$data) {
                return $this->response(['error' => 'Invalid JSON payload'], 400);
            }
            
            $bot_token = $data['bot_token'] ?? null;
            $user_ids = $data['user_ids'] ?? null;
            $owner_id = $data['owner_id'] ?? null;
            $message = $data['message'] ?? null;
            $chunk_info = $data['chunk_info'] ?? null;
            
        } else {
            // GET method for small tests
            $bot_token = $_GET['bot'] ?? null;
            $user_ids = $_GET['userids'] ?? null;
            $owner_id = $_GET['owner'] ?? null;
            $message_json = $_GET['message'] ?? null;
            $message = $message_json ? json_decode(urldecode($message_json), true) : null;
            $chunk_info = null;
        }
        
        // Validate required parameters
        if (!$bot_token || !$user_ids || !$owner_id || !$message) {
            return $this->response(['error' => 'Missing required parameters'], 400);
        }
        
        // Parse user IDs
        if (is_string($user_ids)) {
            $subscriber_ids = explode(',', $user_ids);
        } else {
            $subscriber_ids = $user_ids;
        }
        $subscriber_ids = array_filter(array_map('trim', $subscriber_ids));
        
        if (empty($subscriber_ids)) {
            return $this->response(['error' => 'No valid user IDs provided'], 400);
        }
        
        $start_time = time();
        
        // Process subscribers using parallel cURL
        $results = $this->sendBroadcastParallel($bot_token, $subscriber_ids, $message);
        
        $total_time = time() - $start_time;
        $sent_count = $results['sent_count'];
        $failed_count = $results['failed_count'];
        $blocked_count = $results['blocked_count'];
        
        // Send completion notification only for last chunk or single chunk
        if (!$chunk_info || ($chunk_info['chunk_number'] == $chunk_info['total_chunks'])) {
            $this->notifyAdmin($bot_token, $owner_id, count($subscriber_ids), $sent_count, $failed_count, $total_time, $chunk_info);
        }
        
        return $this->response([
            'success' => true,
            'total_subscribers' => count($subscriber_ids),
            'sent_count' => $sent_count,
            'failed_count' => $failed_count,
            'blocked_count' => $blocked_count,
            'total_time_seconds' => $total_time,
            'chunk_info' => $chunk_info,
            'message' => 'Broadcast completed successfully'
        ]);
    }
    
    private function sendBroadcastParallel($bot_token, $subscriber_ids, $message) {
        $sent_count = 0;
        $failed_count = 0;
        $blocked_count = 0;
        
        // Split subscribers into smaller parallel batches
        $parallel_batches = array_chunk($subscriber_ids, $this->max_parallel);
        
        foreach ($parallel_batches as $batch) {
            $results = $this->processBatchParallel($bot_token, $batch, $message);
            $sent_count += $results['sent'];
            $failed_count += $results['failed'];
            $blocked_count += $results['blocked'];
            
            // Small delay between batches to prevent overwhelming Telegram
            usleep(200000); // 0.2 seconds
        }
        
        return [
            'sent_count' => $sent_count,
            'failed_count' => $failed_count,
            'blocked_count' => $blocked_count
        ];
    }
    
    private function processBatchParallel($bot_token, $batch, $message) {
        $multi_handle = curl_multi_init();
        $curl_handles = [];
        $sent = 0;
        $failed = 0;
        $blocked = 0;
        
        // Create cURL handles for each user in the batch
        foreach ($batch as $index => $user_id) {
            $curl_handles[$index] = $this->createTelegramCurlHandle($bot_token, $user_id, $message);
            curl_multi_add_handle($multi_handle, $curl_handles[$index]);
        }
        
        // Execute all requests in parallel
        $running = null;
        do {
            curl_multi_exec($multi_handle, $running);
            curl_multi_select($multi_handle);
        } while ($running > 0);
        
        // Process results
        foreach ($curl_handles as $index => $handle) {
            $response = curl_multi_getcontent($handle);
            $http_code = curl_getinfo($handle, CURLINFO_HTTP_CODE);
            
            if ($http_code === 200 && $response) {
                $result = json_decode($response, true);
                if ($result && isset($result['ok']) && $result['ok']) {
                    $sent++;
                } else {
                    // Check for blocked users
                    if (isset($result['error_code']) && in_array($result['error_code'], [403, 400])) {
                        $blocked++;
                    } else {
                        $failed++;
                    }
                }
            } else {
                $failed++;
            }
            
            curl_multi_remove_handle($multi_handle, $handle);
            curl_close($handle);
        }
        
        curl_multi_close($multi_handle);
        
        return [
            'sent' => $sent,
            'failed' => $failed,
            'blocked' => $blocked
        ];
    }
    
    private function createTelegramCurlHandle($bot_token, $chat_id, $message) {
        $ch = curl_init();
        
        // Determine the Telegram API method and data
        if (isset($message['photo'])) {
            $url = "https://api.telegram.org/bot{$bot_token}/sendPhoto";
            $data = [
                'chat_id' => $chat_id,
                'photo' => $message['photo'][count($message['photo'])-1]['file_id'] ?? $message['photo'],
                'caption' => $message['caption'] ?? ''
            ];
        } elseif (isset($message['video'])) {
            $url = "https://api.telegram.org/bot{$bot_token}/sendVideo";
            $data = [
                'chat_id' => $chat_id,
                'video' => $message['video']['file_id'] ?? $message['video'],
                'caption' => $message['caption'] ?? ''
            ];
        } elseif (isset($message['document'])) {
            $url = "https://api.telegram.org/bot{$bot_token}/sendDocument";
            $data = [
                'chat_id' => $chat_id,
                'document' => $message['document']['file_id'] ?? $message['document'],
                'caption' => $message['caption'] ?? ''
            ];
        } elseif (isset($message['audio'])) {
            $url = "https://api.telegram.org/bot{$bot_token}/sendAudio";
            $data = [
                'chat_id' => $chat_id,
                'audio' => $message['audio']['file_id'] ?? $message['audio'],
                'caption' => $message['caption'] ?? ''
            ];
        } else {
            $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
            $data = [
                'chat_id' => $chat_id,
                'text' => $message['text'] ?? 'Broadcast message'
            ];
            
            // Add inline keyboard if present
            if (isset($message['reply_markup'])) {
                $data['reply_markup'] = json_encode($message['reply_markup']);
            }
        }
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 3
        ]);
        
        return $ch;
    }
    
    private function notifyAdmin($bot_token, $owner_id, $total, $sent, $failed, $total_time, $chunk_info = null) {
        $success_rate = $total > 0 ? round(($sent / $total) * 100, 1) : 0;
        
        if ($chunk_info) {
            $notification = "✅ Chunk {$chunk_info['chunk_number']}/{$chunk_info['total_chunks']} completed!\n\n";
        } else {
            $notification = "✅ Broadcast completed successfully!\n\n";
        }
        
        $notification .= "📊 Processed: {$total}\n";
        $notification .= "✅ Successfully sent: {$sent}\n";
        $notification .= "❌ Failed: {$failed}\n";
        $notification .= "📈 Success rate: {$success_rate}%\n";
        $notification .= "⏱️ Processing time: {$total_time}s\n";
        $notification .= "🚀 Parallel processing enabled\n";
        $notification .= "🕐 " . date('Y-m-d H:i:s');
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api.telegram.org/bot{$bot_token}/sendMessage",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'chat_id' => $owner_id,
                'text' => $notification
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 5
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
    
    private function response($data, $code = 200) {
        ob_clean();
        http_response_code($code);
        echo json_encode($data);
        ob_end_flush();
        return $data;
    }
}

// Handle request
if (in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
    try {
        $api = new VercelBroadcastAPI();
        $api->startBroadcast();
    } catch (Exception $e) {
        ob_clean();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        ob_end_flush();
    }
} else {
    ob_clean();
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    ob_end_flush();
}
?>
