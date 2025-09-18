<?php
// Fix headers already sent error
ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_flush();
    exit(0);
}

class VercelBroadcastAPI {
    public function startBroadcast() {
        // Get parameters
        $bot_token = $_GET['bot'] ?? null;
        $user_ids = $_GET['userids'] ?? null;
        $owner_id = $_GET['owner'] ?? null;
        $message_json = $_GET['message'] ?? null;
        
        // Validate parameters
        if (!$bot_token || !$user_ids || !$owner_id || !$message_json) {
            return $this->response(['error' => 'Missing required parameters'], 400);
        }
        
        // Parse message
        $message = json_decode(urldecode($message_json), true);
        if (!$message) {
            return $this->response(['error' => 'Invalid message JSON'], 400);
        }
        
        // Parse user IDs
        $subscriber_ids = explode(',', $user_ids);
        $subscriber_ids = array_filter(array_map('trim', $subscriber_ids));
        
        if (empty($subscriber_ids)) {
            return $this->response(['error' => 'No valid user IDs'], 400);
        }
        
        // Process all subscribers immediately (works great for up to 3000+ users)
        $sent_count = 0;
        $failed_count = 0;
        $blocked_users = [];
        
        $start_time = time();
        
        foreach ($subscriber_ids as $subscriber_id) {
            $success = $this->sendTelegramMessage($bot_token, $subscriber_id, $message);
            
            if ($success) {
                $sent_count++;
            } else {
                $failed_count++;
                // Could be blocked user - add to list for cleanup
                $blocked_users[] = $subscriber_id;
            }
            
            // Small delay to prevent rate limiting
            usleep(50000); // 0.05 seconds = 20 messages/second (safe rate)
        }
        
        $total_time = time() - $start_time;
        
        // Send completion notification to admin
        $this->notifyAdmin($bot_token, $owner_id, count($subscriber_ids), $sent_count, $failed_count, $total_time);
        
        return $this->response([
            'success' => true,
            'total_subscribers' => count($subscriber_ids),
            'sent_count' => $sent_count,
            'failed_count' => $failed_count,
            'total_time_seconds' => $total_time,
            'message' => 'Broadcast completed successfully'
        ]);
    }
    
    private function sendTelegramMessage($bot_token, $chat_id, $message) {
        $max_retries = 2;
        
        for ($retry = 0; $retry < $max_retries; $retry++) {
            try {
                $result = null;
                
                if (isset($message['photo'])) {
                    $result = $this->telegramApiCall($bot_token, 'sendPhoto', [
                        'chat_id' => $chat_id,
                        'photo' => $message['photo'][count($message['photo'])-1]['file_id'] ?? $message['photo'],
                        'caption' => $message['caption'] ?? ''
                    ]);
                } elseif (isset($message['video'])) {
                    $result = $this->telegramApiCall($bot_token, 'sendVideo', [
                        'chat_id' => $chat_id,
                        'video' => $message['video']['file_id'] ?? $message['video'],
                        'caption' => $message['caption'] ?? ''
                    ]);
                } elseif (isset($message['document'])) {
                    $result = $this->telegramApiCall($bot_token, 'sendDocument', [
                        'chat_id' => $chat_id,
                        'document' => $message['document']['file_id'] ?? $message['document'],
                        'caption' => $message['caption'] ?? ''
                    ]);
                } elseif (isset($message['audio'])) {
                    $result = $this->telegramApiCall($bot_token, 'sendAudio', [
                        'chat_id' => $chat_id,
                        'audio' => $message['audio']['file_id'] ?? $message['audio'],
                        'caption' => $message['caption'] ?? ''
                    ]);
                } else {
                    $data = [
                        'chat_id' => $chat_id,
                        'text' => $message['text'] ?? 'Broadcast message'
                    ];
                    
                    // Add inline keyboard if present
                    if (isset($message['reply_markup'])) {
                        $data['reply_markup'] = json_encode($message['reply_markup']);
                    }
                    
                    $result = $this->telegramApiCall($bot_token, 'sendMessage', $data);
                }
                
                if ($result && isset($result['ok']) && $result['ok']) {
                    return true;
                }
                
                // Handle rate limiting
                if (isset($result['error_code']) && $result['error_code'] == 429) {
                    $retry_after = min($result['parameters']['retry_after'] ?? 2, 5);
                    sleep($retry_after);
                    continue;
                }
                
                // Don't retry for blocked users or invalid chats
                if (isset($result['error_code']) && in_array($result['error_code'], [403, 400])) {
                    break;
                }
                
            } catch (Exception $e) {
                error_log("Telegram API error: " . $e->getMessage());
            }
            
            if ($retry < $max_retries - 1) {
                sleep(1);
            }
        }
        
        return false;
    }
    
    private function telegramApiCall($bot_token, $method, $data) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api.telegram.org/bot{$bot_token}/{$method}",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            return ['ok' => false, 'error' => 'HTTP ' . $http_code];
        }
        
        return json_decode($response, true);
    }
    
    private function notifyAdmin($bot_token, $owner_id, $total, $sent, $failed, $total_time) {
        $success_rate = $total > 0 ? round(($sent / $total) * 100, 1) : 0;
        
        $notification = "✅ Broadcast completed successfully!\n\n";
        $notification .= "📊 Total subscribers: {$total}\n";
        $notification .= "✅ Successfully sent: {$sent}\n";
        $notification .= "❌ Failed: {$failed}\n";
        $notification .= "📈 Success rate: {$success_rate}%\n";
        $notification .= "⏱️ Total time: " . gmdate("H:i:s", $total_time) . "\n";
        $notification .= "🚀 Powered by Vercel (Single Function)\n";
        $notification .= "🕐 Completed: " . date('Y-m-d H:i:s');
        
        $this->telegramApiCall($bot_token, 'sendMessage', [
            'chat_id' => $owner_id,
            'text' => $notification
        ]);
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
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
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
