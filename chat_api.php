<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load environment variables from .env file if it exists
function loadEnv($path) {
    if (!file_exists($path)) {
        return false;
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            
            // Remove quotes if present
            if (preg_match('/^(["\'])(.*)\\1$/', $value, $matches)) {
                $value = $matches[2];
            }
            
            putenv("$name=$value");
            $_ENV[$name] = $value;
        }
    }
    return true;
}

// Load environment variables
loadEnv('.env');

// Get API key from environment or credentials file
function getApiKey() {
    // Try environment variable first
    $apiKey = getenv('GOOGLE_GEMINI_API_KEY');
    if ($apiKey) {
        return $apiKey;
    }
    
    // Try credentials file
    if (file_exists('gemini_credentials.json')) {
        $credentials = json_decode(file_get_contents('gemini_credentials.json'), true);
        if (isset($credentials['api_key'])) {
            return $credentials['api_key'];
        }
    }
    
    return null;
}

// Function to make API call to Gemini
function callGeminiAPI($message, $config = []) {
    $apiKey = getApiKey();
    if (!$apiKey) {
        throw new Exception('API key not found. Please set GOOGLE_GEMINI_API_KEY environment variable or create gemini_credentials.json file.');
    }
    
    // Default configuration
    $defaultConfig = [
        'model' => 'gemini-1.5-flash',
        'temperature' => 0.7,
        'top_p' => 0.95,
        'max_tokens' => 2048,
        'system_instruction' => ''
    ];
    
    $config = array_merge($defaultConfig, $config);
    
    // Prepare the request URL
    $model = $config['model'];
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
    
    // Prepare the request body
    $requestBody = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $message]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => (float)$config['temperature'],
            'topP' => (float)$config['top_p'],
            'maxOutputTokens' => (int)$config['max_tokens']
        ]
    ];
    
    // Add system instruction if provided
    if (!empty($config['system_instruction'])) {
        $requestBody['systemInstruction'] = [
            'parts' => [
                ['text' => $config['system_instruction']]
            ]
        ];
    }
    
    // Make the HTTP request
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($requestBody),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("cURL Error: " . $error);
    }
    
    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMessage = isset($errorData['error']['message']) ? 
            $errorData['error']['message'] : 
            "HTTP Error $httpCode";
        throw new Exception($errorMessage);
    }
    
    $responseData = json_decode($response, true);
    
    if (!isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        // Check if content was blocked
        if (isset($responseData['candidates'][0]['finishReason']) && 
            $responseData['candidates'][0]['finishReason'] === 'SAFETY') {
            throw new Exception("Content was blocked by safety filters.");
        }
        throw new Exception("Invalid response format from Gemini API");
    }
    
    return $responseData['candidates'][0]['content']['parts'][0]['text'];
}

// Handle the request
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method is allowed');
    }
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['message']) || empty(trim($input['message']))) {
        throw new Exception('Message is required');
    }
    
    $message = trim($input['message']);
    $config = isset($input['config']) ? $input['config'] : [];
    $history = isset($input['history']) ? $input['history'] : [];
    
    // Add conversation context if history exists
    if (!empty($history)) {
        $contextMessage = "Previous conversation context:\n";
        
        // Only include last 5 exchanges to avoid token limits
        $recentHistory = array_slice($history, -5);
        
        foreach ($recentHistory as $exchange) {
            $contextMessage .= "User: " . $exchange['user'] . "\n";
            $contextMessage .= "Assistant: " . $exchange['bot'] . "\n\n";
        }
        
        $contextMessage .= "Current message:\n" . $message;
        $message = $contextMessage;
    }
    
    // Call Gemini API
    $response = callGeminiAPI($message, $config);
    
    // Return success response
    echo json_encode([
        'success' => true,
        'response' => $response
    ]);
    
} catch (Exception $e) {
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>