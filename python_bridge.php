<?php
// Alternative approach: Use your existing Python script via PHP
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

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
    
    // Create a temporary input file for Python script
    $inputData = [
        'message' => $message,
        'config' => $config
    ];
    
    $tempInputFile = tempnam(sys_get_temp_dir(), 'gemini_input_');
    file_put_contents($tempInputFile, json_encode($inputData));
    
    // Create a simple Python wrapper script that uses your existing chatbot
    $pythonScript = '
import sys
import json
import os

# Add the directory containing your chatbot script to Python path
sys.path.append("' . __DIR__ . '")

try:
    # Import your existing chatbot (assuming it\'s in the same directory)
    from your_chatbot_script import GeminiChatbot  # Adjust the import name
    
    # Read input
    with open("' . $tempInputFile . '", "r") as f:
        data = json.load(f)
    
    message = data["message"]
    config = data.get("config", {})
    
    # Initialize chatbot with config
    system_instruction = config.get("system_instruction", "")
    model_name = config.get("model", "gemini-1.5-flash")
    
    chatbot = GeminiChatbot(
        model_name=model_name,
        initial_system_instruction=system_instruction if system_instruction else None
    )
    
    # Update configuration if provided
    if config:
        chatbot.update_config(
            temperature=config.get("temperature", 0.7),
            top_p=config.get("top_p", 0.95),
            max_output_tokens=config.get("max_tokens", 2048)
        )
    
    # Send message and get response
    response = chatbot.send_message(message)
    
    # Output result
    result = {
        "success": True,
        "response": response if response else "Sorry, I couldn\'t generate a response."
    }
    print(json.dumps(result))
    
except Exception as e:
    result = {
        "success": False,
        "error": str(e)
    }
    print(json.dumps(result))
';
    
    // Write Python script to temporary file
    $tempPythonFile = tempnam(sys_get_temp_dir(), 'gemini_script_') . '.py';
    file_put_contents($tempPythonFile, $pythonScript);
    
    // Execute Python script
    $pythonCommand = "python \"$tempPythonFile\"";
    $output = shell_exec($pythonCommand . ' 2>&1');
    
    // Clean up temporary files
    unlink($tempInputFile);
    unlink($tempPythonFile);
    
    // Parse output
    $result = json_decode($output, true);
    
    if ($result === null) {
        throw new Exception('Failed to parse Python script output: ' . $output);
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>