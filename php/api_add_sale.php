<?php

/**
 * api_add_sale.php
 *
 * API endpoint to receive and save new sales records to the database.
 * Expects data via POST request in JSON format.
 *
 * Changes:
 * - Accepts JSON payload instead of form-data.
 * - Implements robust server-side validation.
 * - Uses prepared statements for database insertion to prevent SQL injection.
 * - Returns detailed JSON responses with appropriate HTTP status codes.
 * - Includes CORS handling.
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

    // Extract data (ensure keys match what sales.html will send, assuming JSON input)
    // Using null coalescing operator (??) to provide default values if keys are missing
    $productId = $data['product_id'] ?? null; // Can be optional if auto-generated
    $productName = $data['product_name'] ?? null;
    $quantitySold = $data['quantity_sold'] ?? null;
    $totalQuantity = $data['total_quantity'] ?? null; // Assuming this is the total available quantity before this sale
    $totalAmount = $data['total_amount'] ?? null;
    $saleDate = $data['sale_date'] ?? null;
    // Add any other fields you expect from sales.html, e.g., 'customer_name'
    // $customerName = $data['customer_name'] ?? null;

    // --- Basic Server-Side Validation ---
    $errors = [];
    if (empty($productName)) {
        $errors[] = 'Product Name is required.';
    }
    // Product ID might be optional if your system can auto-generate it or if it's not strictly required for a sale record.
    // If it's required from the client, uncomment:
    // if (empty($productId)) $errors[] = 'Product ID is required.';


    if ($quantitySold === null || !is_numeric($quantitySold) || $quantitySold <= 0) {
        $errors[] = 'Quantity Sold must be a positive number.';
    }
    // total_quantity might represent the stock *before* this sale, or total for this product type.
    // If it's stock before sale, quantity_sold should not exceed it.
    if ($totalQuantity === null || !is_numeric($totalQuantity) || $totalQuantity < 0) {
        $errors[] = 'Total Quantity must be a non-negative number.';
    } elseif (is_numeric($quantitySold) && is_numeric($totalQuantity) && $quantitySold > $totalQuantity) {
        // This validation might be more complex depending on how 'total_quantity' is used.
        // For instance, if total_quantity is the overall stock of that product type,
        // this specific validation might not apply directly here but rather during an inventory check.
        // However, if 'total_quantity' refers to the amount available for *this specific transaction/batch*, then it's relevant.
        // $errors[] = 'Quantity Sold cannot be greater than Total Quantity available for this transaction.';
    }

    if ($totalAmount === null || !is_numeric($totalAmount) || $totalAmount < 0) {
        // Allow 0 for totalAmount if items are given for free, but typically it's positive.
        $errors[] = 'Total Amount must be a non-negative number.';
    }

    if (empty($saleDate)) {
        $errors[] = 'Sale Date is required.';
    } else {
        $dateObj = DateTime::createFromFormat('Y-m-d', $saleDate);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $saleDate) {
            $errors[] = 'Invalid Sale Date format. Please use YYYY-MM-DD.';
        }
        // Optionally, check if the sale date is not in the future, if that's a business rule.
        // if ($dateObj && $dateObj > new DateTime()) {
        //     $errors[] = 'Sale Date cannot be in the future.';
        // }
    }

    // Example validation for customer_name if you add it
    // if (empty($customerName)) $errors[] = 'Customer Name is required.';


    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
        http_response_code(400); // Bad Request
        exit;
    }

    // If product_id is not provided by the client and you need to generate it:
    if (empty($productId)) {
        // Example: GEN-SALE-PRODUCTNAME-TIMESTAMP
        $productId = 'SALE-' . strtoupper(substr(str_replace(' ', '', $productName), 0, 5)) . '-' . time();
    }

    // --- Database Insertion ---
    $conn = connectDB();
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => 'Error: Could not connect to the database. Please check server logs.']);
        http_response_code(500); // Internal Server Error
        exit;
    }

    // Prepare SQL statement to prevent SQL injection
    // Adjust table name 'sales' and column names as per your database schema.
    // Added `customer_name` as an example, remove or adjust if not needed.
    $stmt = $conn->prepare(
        "INSERT INTO sales (product_id, product_name, quantity_sold, total_quantity, total_amount, sale_date) 
         VALUES (?, ?, ?, ?, ?, ?)"
        // If you have customer_name:
        // "INSERT INTO sales (product_id, product_name, quantity_sold, total_quantity, total_amount, sale_date, customer_name)
        //  VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        error_log("Statement preparation failed: " . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Error preparing database statement. Please check server logs.']);
        http_response_code(500); // Internal Server Error
        $conn->close();
        exit;
    }

    // Bind parameters: 's' for string, 'd' for double (float/decimal for quantity/amount)
    // Adjust the type string ("ssd dds") if you change columns or their types.
    $stmt->bind_param("ssddds",
        $productId,
        $productName,
        $quantitySold,
        $totalQuantity,
        $totalAmount,
        $saleDate
        // If you have customer_name (string):
        // $customerName
    );

    if ($stmt->execute()) {
        $insertedId = $stmt->insert_id; // Get the ID of the newly inserted row
        echo json_encode([
            'success' => true,
            'message' => 'Sale record added successfully.',
            'sale_id' => $insertedId, // Optionally return the new sale's ID
            'generated_product_id' => $productId // If you generated it
        ]);
        http_response_code(201); // Created
    } else {
        error_log("Statement execution failed: " . $stmt->error);
        // Provide a more specific error if it's a duplicate entry, for example
        if ($conn->errno == 1062) { // Error code for duplicate entry
             echo json_encode(['success' => false, 'message' => 'Error: Duplicate entry. This sale record (e.g., based on product_id and sale_date) might already exist.']);
        } else {
             echo json_encode(['success' => false, 'message' => 'Error adding sale record to database. ' . $stmt->error]);
        }
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
