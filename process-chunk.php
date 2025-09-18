<?php
header('Content-Type: application/json');

class ChunkProcessor {
    private $temp_path;
    
    public function __construct() {
        $this->temp_path = $_ENV['TEMP_STORAGE_PATH'] ?? '/tmp';
    }
    
    public function processChunk() {
        $broadcast_id = $_GET['broadcast_id'] ?? null;
        $chunk_index = $_GET['chunk_index'] ?? null;
        
        if (!$broadcast_id || $chunk_index === null) {
            return $this->response(['error' => 'Missing broadcast_id or chunk_index'], 400);
        }
        
        // Load broadcast data
        $broadcast_file = "{$this->temp_path}/broadcast_{$broadcast_id}.json";
        if (!file_exists($broadcast_file)) {
            return $this->response(['error' => 'Broadcast not found'], 404);
        }
        
        $broadcast_data = json_decode(file_get_contents($broadcast_file), true);
        
        // Load chunk data
        $chunk_file = "{$this->temp_path}/chunk_{$broadcast_id}_{$chunk_index}.json";
        if (!file_exists($chunk_file)) {
            return $this->response(['error' => 'Chunk not found'], 404);
        }
        
        $chunk_data = json_decode(file_get_contents($chunk_file), true);
        
        // Skip if already processed
        if ($chunk_data['status'] !== 'pending') {
            return $this->response(['message' => 'Chunk already processed']);
        }
        
        // Mark chunk as processing
        $chunk_data['status'] = 'processing';
        file_put_contents($chunk_file, json_encode($chunk_data));
        
        $bot_token = $broadcast_data['bot_token'];
        $message = $broadcast_data['message'];
        $subscriber_ids = $chunk_data['subscriber_ids'];
        
        $sent_count = 0;
        $failed_count = 0;
        
        // Process each subscriber in this chunk
        foreach ($subscriber_ids as $subscriber_id) {
            try {
                $success = $this->sendTelegramMessage($bot_token, $subscriber_id, $message);
                if ($success) {
                    $sent_count++;
                } else {
                    $failed_count++;
                }
            } catch (Exception $e) {
                $failed_count++;
                error_log("Broadcast error for {$subscriber_id}: " . $e->getMessage());
            }
            
            // Small delay to prevent rate limiting
            usleep(80000); // 0.08 seconds = ~12 messages per second
        }
        
        // Update chunk status
        $chunk_data['status'] = 'completed';
        $chunk_data['sent_count'] = $sent_count;
        $chunk_data['failed_count'] = $failed_count;
        file_put_contents($chunk_file, json_encode($chunk_data));
        
        // Update main broadcast data
        $broadcast_data['completed_chunks']++;
        $broadcast_data['sent_count'] += $sent_count;
        $broadcast_data['failed_count'] += $failed_count;
        
        // Check if all chunks completed
        if ($broadcast_data['completed_chunks'] >= $broadcast_data['total_chunks']) {
            // Broadcast completed - notify admin
            $broadcast_data['status'] = 'completed';
            $broadcast_data['completed_at'] = time();
            
            $this->notifyAdmin($broadcast_data);
            
            // Schedule cleanup after 1 hour
            $this->scheduleCleanup($broadcast_id);
        } else {
            // Trigger next chunk
            $next_chunk = $chunk_index + 1;
            if ($next_chunk < $broadcast_data['total_chunks']) {
                $this->triggerChunkProcessing($broadcast_id, $next_chunk);
            }
        }
        
        // Save updated broadcast data
        file_put_contents($broadcast_file, json_encode($broadcast_data));
        
        return $this->response([
            'success' => true,
            'chunk_completed' => $chunk_index + 1,
            'total_chunks' => $broadcast_data['total_chunks'],
            'sent' => $sent_count,
            'failed' => $failed_count,
            'progress' => round(($broadcast_data['completed_chunks'] / $broadcast_data['total_chunks']) * 100, 2) . '%'
        ]);
    }
    
    private function sendTelegramMessage($bot_token, $chat_id, $message) {
        $success = false;
        $max_retries = 2;
        
        for ($retry = 0; $retry < $max_retries; $retry++) {
            try {
                if (isset($message['photo'])) {
                    // Send photo
                    $result = $this->telegramApiCall($bot_token, 'sendPhoto', [
                        'chat_id' => $chat_id,
                        'photo' => $message['photo'][count($message['photo'])-1]['file_id'] ?? $message['photo'],
                        'caption' => $message['caption'] ?? ''
                    ]);
                } elseif (isset($message['video'])) {
                    // Send video
                    $result = $this->telegramApiCall($bot_token, 'sendVideo', [
                        'chat_id' => $chat_id,
                        'video' => $message['video']['file_id'] ?? $message['video'],
                        'caption' => $message['caption'] ?? ''
                    ]);
                } elseif (isset($message['document'])) {
                    // Send document
                    $result = $this->telegramApiCall($bot_token, 'sendDocument', [
                        'chat_id' => $chat_id,
                        'document' => $message['document']['file_id'] ?? $message['document'],
                        'caption' => $message['caption'] ?? ''
                    ]);
                } elseif (isset($message['audio'])) {
                    // Send audio
                    $result = $this->telegramApiCall($bot_token, 'sendAudio', [
                        'chat_id' => $chat_id,
                        'audio' => $message['audio']['file_id'] ?? $message['audio'],
                        'caption' => $message['caption'] ?? ''
                    ]);
                } else {
                    // Send text message
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
                    $success = true;
                    break;
                } elseif (isset($result['error_code'])) {
                    // Handle specific errors
                    if ($result['error_code'] == 429) {
                        // Rate limited - wait and retry
                        $retry_after = $result['parameters']['retry_after'] ?? 1;
                        sleep(min($retry_after, 5));
                        continue;
                    } elseif (in_array($result['error_code'], [403, 400])) {
                        // User blocked bot or chat not found - don't retry
                        break;
                    }
                }
                
            } catch (Exception $e) {
                error_log("Telegram API error: " . $e->getMessage());
            }
            
            if ($retry < $max_retries - 1) {
                sleep(1); // Wait before retry
            }
        }
        
        return $success;
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
        
        return json_decode($response, true);
    }
    
    private function notifyAdmin($broadcast_data) {
        $total_time = $broadcast_data['completed_at'] - $broadcast_data['created_at'];
        $success_rate = round(($broadcast_data['sent_count'] / $broadcast_data['total_subscribers']) * 100, 1);
        
        $notification_message = "✅ Broadcast completed!\n\n";
        $notification_message .= "📊 Total subscribers: {$broadcast_data['total_subscribers']}\n";
        $notification_message .= "✅ Successfully sent: {$broadcast_data['sent_count']}\n";
        $notification_message .= "❌ Failed: {$broadcast_data['failed_count']}\n";
        $notification_message .= "📈 Success rate: {$success_rate}%\n";
        $notification_message .= "⏱️ Total time: " . gmdate("H:i:s", $total_time) . "\n";
        $notification_message .= "🚀 Powered by Vercel API\n";
        $notification_message .= "🕐 " . date('Y-m-d H:i:s');
        
        // Send notification to admin
        $this->telegramApiCall($broadcast_data['bot_token'], 'sendMessage', [
            'chat_id' => $broadcast_data['owner_id'],
            'text' => $notification_message
        ]);
    }
    
    private function triggerChunkProcessing($broadcast_id, $chunk_index) {
        $webhook_url = "https://" . $_SERVER['HTTP_HOST'] . "/api/process-chunk?broadcast_id={$broadcast_id}&chunk_index={$chunk_index}";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $webhook_url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 1,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
    
    private function scheduleCleanup($broadcast_id) {
        // Mark for cleanup (files will be auto-deleted by Vercel after some time)
        $cleanup_file = "{$this->temp_path}/cleanup_{$broadcast_id}.txt";
        file_put_contents($cleanup_file, time() + 3600); // 1 hour from now
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
        $processor = new ChunkProcessor();
        $processor->processChunk();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>
