<?php

/**
 * api_get_sales.php
 *
 * API endpoint to fetch all sales records from the database.
 */

// Set content type to JSON for the response
header('Content-Type: application/json');
// Allow requests from any origin (IMPORTANT: Adjust for security in production)
header('Access-Control-Allow-Origin: *');
// Allow GET method
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle OPTIONS request for CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); // No Content
    exit;
}

// --- Database Configuration (XAMPP Defaults) ---
$servername = "localhost"; // Standard XAMPP server address
$username = "root";      // Standard XAMPP username
$password = "";          // Standard XAMPP password (usually empty)
$dbname = "mangsho_db";  // Your database name

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

// --- Main Logic to Handle GET Request ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $conn = connectDB();
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => 'Error: Could not connect to the database.']);
        http_response_code(500); // Internal Server Error
        exit;
    }

    // Prepare SQL statement to fetch all sales records
    // Order by sale_date descending, then by id descending to get newest records first
    // Add `id` to the select if it's your primary key and you want to use it on the client-side
    $stmt = $conn->prepare("SELECT id, product_id, product_name, quantity_sold, total_quantity, total_amount, sale_date FROM sales ORDER BY sale_date DESC, id DESC");

    if (!$stmt) {
        error_log("Statement preparation failed: " . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Error preparing database statement.']);
        http_response_code(500); // Internal Server Error
        $conn->close();
        exit;
    }

    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $sales = [];
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                // Ensure numeric types are correctly cast if necessary, though fetch_assoc usually handles it well.
                $row['quantity_sold'] = (float)$row['quantity_sold'];
                $row['total_quantity'] = (float)$row['total_quantity'];
                $row['total_amount'] = (float)$row['total_amount'];
                $sales[] = $row;
            }
        }
        echo json_encode(['success' => true, 'data' => $sales]);
        http_response_code(200); // OK
    } else {
        error_log("Statement execution failed: " . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'Error fetching sales records. ' . $stmt->error]);
        http_response_code(500); // Internal Server Error
    }

    $stmt->close();
    $conn->close();

} else {
    // Handle cases where the request method is not GET
    echo json_encode(['success' => false, 'message' => 'Error: Invalid request method. Only GET is accepted.']);
    http_response_code(405); // Method Not Allowed
}

?>
