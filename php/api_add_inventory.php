<?php

/**
 * api_add_inventory.php
 *
 * API endpoint to receive and save new inventory items to the database.
 * Expects data via POST request.
 * This script assumes you have a database table named 'inventory'.
 */

// Set content type to JSON for the response
header('Content-Type: application/json');
// Allow requests from any origin (IMPORTANT: Adjust for security in production)
header('Access-Control-Allow-Origin: *');
// Allow POST method and common headers
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle OPTIONS request for CORS preflight (sent by browsers before POST)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); // No Content
    exit;
}

// --- Database Configuration (XAMPP Defaults) ---
$servername = "localhost"; // Standard XAMPP server address
$username = "root";      // Standard XAMPP username
$password = "";          // Standard XAMPP password (usually empty)
$dbname = "mangsho_db";  // Your database name (ensure this database exists)

// --- Helper Function: Establish Database Connection ---
function connectDB() {
    global $servername, $username, $password, $dbname;
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        // Log detailed error to server logs for debugging
        error_log("Database connection failed: " . $conn->connect_error);
        // Return a generic error to the client
        return null;
    }
    $conn->set_charset("utf8mb4");
    return $conn;
}

// --- Main Logic to Handle POST Request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the raw POST data (expected to be JSON)
    $jsonPayload = file_get_contents('php://input');
    // Decode the JSON payload into an associative array
    $data = json_decode($jsonPayload, true);

    // Validate that data was received and is in expected format
    if (!$data) {
        echo json_encode(['success' => false, 'message' => 'Error: No data received or invalid JSON format.']);
        http_response_code(400); // Bad Request
        exit;
    }

    // Extract data (ensure keys match what inventory.html will send)
    // Using null coalescing operator (??) to provide default values if keys are missing
    $meatType = $data['meat_type'] ?? null;
    $quantity = $data['quantity'] ?? null;
    $processingDate = $data['processing_date'] ?? null;
    $storageLocation = $data['storage_location'] ?? null;
    $batchNumber = $data['batch_number'] ?? null;
    $expirationDate = $data['expiration_date'] ?? null;

    // --- Basic Server-Side Validation ---
    $errors = [];
    if (empty($meatType)) $errors[] = 'Meat Type is required.';
    if ($quantity === null || !is_numeric($quantity) || $quantity < 0) {
        $errors[] = 'Quantity must be a non-negative number.';
    }
    if (empty($processingDate)) {
        $errors[] = 'Processing Date is required.';
    } else {
        $procDateObj = DateTime::createFromFormat('Y-m-d', $processingDate);
        if (!$procDateObj || $procDateObj->format('Y-m-d') !== $processingDate) {
            $errors[] = 'Invalid Processing Date format. Please use YYYY-MM-DD.';
        }
    }
    if (empty($storageLocation)) $errors[] = 'Storage Location is required.';
    if (empty($batchNumber)) $errors[] = 'Batch Number is required.';
    if (empty($expirationDate)) {
        $errors[] = 'Expiration Date is required.';
    } else {
        $expDateObj = DateTime::createFromFormat('Y-m-d', $expirationDate);
        if (!$expDateObj || $expDateObj->format('Y-m-d') !== $expirationDate) {
            $errors[] = 'Invalid Expiration Date format. Please use YYYY-MM-DD.';
        }
    }

    // Additional validation: Expiration date should not be before processing date
    if (isset($procDateObj) && isset($expDateObj) && $expDateObj < $procDateObj) {
        $errors[] = 'Expiration Date cannot be earlier than Processing Date.';
    }


    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
        http_response_code(400); // Bad Request
        exit;
    }

    // --- Database Insertion ---
    $conn = connectDB();
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => 'Error: Could not connect to the database. Please check server logs.']);
        http_response_code(500); // Internal Server Error
        exit;
    }

    // Prepare SQL statement to prevent SQL injection
    // Assumed table name 'inventory' and column names matching the variables
    $stmt = $conn->prepare(
        "INSERT INTO inventory (meat_type, quantity, processing_date, storage_location, batch_number, expiration_date) 
         VALUES (?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        error_log("Statement preparation failed: " . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Error preparing database statement. Please check server logs.']);
        http_response_code(500); // Internal Server Error
        $conn->close();
        exit;
    }

    // Bind parameters: 's' for string, 'd' for double (float/decimal for quantity)
    $stmt->bind_param("sdssss", 
        $meatType, 
        $quantity, 
        $processingDate, 
        $storageLocation, 
        $batchNumber, 
        $expirationDate
    );

    if ($stmt->execute()) {
        $insertedId = $stmt->insert_id; // Get the ID of the newly inserted row
        echo json_encode([
            'success' => true, 
            'message' => 'Inventory item added successfully.',
            'item_id' => $insertedId // Optionally return the new item's ID
        ]);
        http_response_code(201); // Created
    } else {
        error_log("Statement execution failed: " . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'Error adding inventory item to database. ' . $stmt->error]);
        http_response_code(500); // Internal Server Error
    }

    $stmt->close();
    $conn->close();

} else {
    // Handle cases where the request method is not POST
    echo json_encode(['success' => false, 'message' => 'Error: Invalid request method. Only POST is accepted.']);
    http_response_code(405); // Method Not Allowed
}

?>
