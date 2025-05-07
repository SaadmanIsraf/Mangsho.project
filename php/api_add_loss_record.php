<?php

/**
 * api_add_loss_record.php
 *
 * API endpoint to receive and save new meat loss records to the database.
 * Expects data via POST request in JSON format.
 */

// Set content type to JSON for the response
header('Content-Type: application/json');
// Allow requests from any origin (IMPORTANT: Adjust for security in production)
header('Access-Control-Allow-Origin: *');
// Allow POST method and common headers
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle OPTIONS request for CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); // No Content
    exit;
}

// --- Database Configuration (XAMPP Defaults) ---
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "mangsho_db"; // Ensure this database exists

// --- Helper Function: Establish Database Connection ---
function connectDB() {
    global $servername, $username, $password, $dbname;
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
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

    // Extract data from the decoded JSON
    // Using null coalescing operator (??) for defaults
    $recordDate = $data['record_date'] ?? null;
    $meatType = $data['meat_type'] ?? null;
    $stage = $data['stage'] ?? null;
    $wastageAmount = $data['wastage_amount'] ?? null;
    $notes = $data['notes'] ?? null; // Notes are optional

    // --- Basic Server-Side Validation ---
    $errors = [];
    if (empty($recordDate)) {
        $errors[] = 'Record Date is required.';
    } else {
        $dateObj = DateTime::createFromFormat('Y-m-d', $recordDate);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $recordDate) {
            $errors[] = 'Invalid Record Date format. Please use YYYY-MM-DD.';
        }
        // Optionally, check if the record date is not in the future
        // if ($dateObj && $dateObj > new DateTime()) {
        //     $errors[] = 'Record Date cannot be in the future.';
        // }
    }

    if (empty($meatType)) {
        $errors[] = 'Meat Type is required.';
    }
    if (empty($stage)) {
        $errors[] = 'Stage is required.';
    }
    if ($wastageAmount === null || !is_numeric($wastageAmount) || $wastageAmount <= 0) {
        $errors[] = 'Wastage Amount must be a positive number.';
    }
    // Notes are optional, so no validation needed unless you have specific length constraints.
    // if ($notes !== null && strlen($notes) > 1000) {
    //    $errors[] = 'Notes cannot exceed 1000 characters.';
    // }


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
    $stmt = $conn->prepare(
        "INSERT INTO loss_records (record_date, meat_type, stage, wastage_amount, notes) 
         VALUES (?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        error_log("Statement preparation failed: " . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Error preparing database statement. Please check server logs.']);
        http_response_code(500); // Internal Server Error
        $conn->close();
        exit;
    }

    // Bind parameters: 's' for string, 'd' for double (for wastage_amount)
    // The type for notes is 's' (string), even if it's NULL.
    $stmt->bind_param("sssds",
        $recordDate,
        $meatType,
        $stage,
        $wastageAmount,
        $notes
    );

    if ($stmt->execute()) {
        $insertedId = $stmt->insert_id; // Get the ID of the newly inserted row
        echo json_encode([
            'success' => true,
            'message' => 'Loss record added successfully.',
            'record_id' => $insertedId // Optionally return the new record's ID
        ]);
        http_response_code(201); // Created
    } else {
        error_log("Statement execution failed: " . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'Error adding loss record to database. ' . $stmt->error]);
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
