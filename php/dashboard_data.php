<?php

/**
 * dashboard_data.php
 *
 * This script fetches (or simulates fetching) dashboard data,
 * including total sales of all time,
 * and returns it as a JSON object for the Mangsho admin dashboard.
 */

// Set the content type header to JSON
header('Content-Type: application/json');
// Allow requests from any origin (IMPORTANT: Adjust for security in production)
header('Access-Control-Allow-Origin: *');


// --- Database Configuration (XAMPP Defaults) ---
$servername = "localhost"; // Standard XAMPP server address
$username = "root";      // Standard XAMPP username
$password = "";          // Standard XAMPP password (usually empty)
$dbname = "mangsho_db";  // Your database name

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    // Return an error JSON response if connection fails
    echo json_encode([
        'error' => 'Database connection failed',
        'details' => $conn->connect_error
    ]);
    http_response_code(500); // Internal Server Error
    exit(); // Stop script execution
}

// Set character set to UTF-8 for proper encoding
$conn->set_charset("utf8mb4");

// --- Data Fetching/Calculation ---

// 1. MODIFIED: Get Total Sales for ALL TIME from the 'sales' table
$totalSalesAllTime = 0; // Default value
// The SQL query is changed to sum all 'total_amount' without any date condition.
$stmtSales = $conn->prepare("SELECT SUM(total_amount) as totalSales FROM sales");
if ($stmtSales) {
    $stmtSales->execute();
    $resultSales = $stmtSales->get_result();
    if ($resultSales) {
        $totalSalesRow = $resultSales->fetch_assoc();
        $totalSalesAllTime = $totalSalesRow['totalSales'] ?? 0;
    } else {
        error_log("Error fetching all-time sales data: " . $stmtSales->error);
    }
    $stmtSales->close();
} else {
    error_log("Error preparing all-time sales statement: " . $conn->error);
}


// 2. Get Total Inventory Quantity (e.g., in kg)
$inventoryKg = 0; // Default value
$stmtInventory = $conn->prepare("SELECT SUM(quantity) as totalInventory FROM inventory");
if ($stmtInventory) {
    $stmtInventory->execute();
    $resultInventory = $stmtInventory->get_result();
    if ($resultInventory) {
        $inventoryRow = $resultInventory->fetch_assoc();
        $inventoryKg = $inventoryRow['totalInventory'] ?? 0;
    } else {
        error_log("Error fetching inventory data: " . $stmtInventory->error);
    }
    $stmtInventory->close();
} else {
    error_log("Error preparing inventory statement: " . $conn->error);
}


// 3. MODIFIED: Get the meat type with the LEAST stock among low stock items
$lowStockThresholdValue = 125; // Your defined low stock threshold in kg (e.g., if an item has less than 125kg, it's low stock)
$leastStockItemName = 'N/A';     // Default if no low stock items or items at exactly 0
$leastStockItemQuantity = null;  // Default quantity, null if no item found
$lowStockAlertCount = 0;         // Count of items below threshold (can be used for a badge or general alert)

// First, count how many items are below the threshold to know if we need to display any alert.
$stmtLowStockCount = $conn->prepare("SELECT COUNT(*) as lowStockItemCount FROM inventory WHERE quantity < ? AND quantity > 0");
if ($stmtLowStockCount) {
    $stmtLowStockCount->bind_param("d", $lowStockThresholdValue);
    $stmtLowStockCount->execute();
    $resultLowStockCount = $stmtLowStockCount->get_result();
    if ($resultLowStockCount) {
        $lowStockCountRow = $resultLowStockCount->fetch_assoc();
        $lowStockAlertCount = (int)($lowStockCountRow['lowStockItemCount'] ?? 0);
    } else {
        error_log("Error fetching low stock count: " . $stmtLowStockCount->error);
    }
    $stmtLowStockCount->close();
} else {
    error_log("Error preparing low stock count statement: " . $conn->error);
}


// If there are items below the threshold, find the one with the absolute least quantity.
if ($lowStockAlertCount > 0) {
    $sqlLeastStock = "SELECT meat_type, quantity
                      FROM inventory
                      WHERE quantity < ? AND quantity > 0
                      ORDER BY quantity ASC
                      LIMIT 1";

    $stmtLeastStock = $conn->prepare($sqlLeastStock);
    if ($stmtLeastStock) {
        $stmtLeastStock->bind_param("d", $lowStockThresholdValue);
        $stmtLeastStock->execute();
        $resultLeastStock = $stmtLeastStock->get_result();

        if ($resultLeastStock && $resultLeastStock->num_rows > 0) {
            $leastStockItemData = $resultLeastStock->fetch_assoc();
            $leastStockItemName = $leastStockItemData['meat_type'];
            $leastStockItemQuantity = (float)$leastStockItemData['quantity'];
        } elseif (!$resultLeastStock) {
            error_log("Error fetching least stock item data: " . $stmtLeastStock->error);
            // Keep $leastStockItemName as 'N/A' or a general error message
            $leastStockItemName = 'Error fetching';
        }
        // If num_rows is 0 here but lowStockAlertCount > 0, it's an inconsistency or all low items are 0.
        // The initial N/A for $leastStockItemName will be used.
        $stmtLeastStock->close();
    } else {
        error_log("Error preparing least stock item statement: " . $conn->error);
        $leastStockItemName = 'DB Error'; // Indicate a database preparation error
    }
}
// END OF MODIFICATION for section 3


// 4. Get Recent Losses (e.g., last 30 days) - This logic remains the same
$lossKg = 0;
$lossStartDate = date('Y-m-d', strtotime('-30 days'));
$stmtLoss = $conn->prepare("SELECT SUM(wastage_amount) as totalLossKg FROM loss_records WHERE record_date >= ?");
if ($stmtLoss) {
    $stmtLoss->bind_param("s", $lossStartDate);
    $stmtLoss->execute();
    $resultLoss = $stmtLoss->get_result();
    if ($resultLoss) {
        $lossRow = $resultLoss->fetch_assoc();
        $lossKg = $lossRow['totalLossKg'] ?? 0;
    } else {
        error_log("Error fetching loss data: " . $stmtLoss->error);
    }
    $stmtLoss->close();
} else {
    error_log("Error preparing loss statement: " . $conn->error);
}

$totalBeforeLoss = $inventoryKg + $lossKg; // This calculation might need review if inventoryKg is all-time
$lossPercentage = ($totalBeforeLoss > 0) ? ($lossKg / $totalBeforeLoss) * 100 : 0;


// --- Close Database Connection ---
if ($conn) {
    $conn->close();
}

// --- Prepare Data Array ---
$dashboardData = [
    'totalSales' => (float)$totalSalesAllTime, // MODIFIED: Use the all-time sales variable
    'inventoryKg' => (float)$inventoryKg,
    'lowStockAlertCount' => $lowStockAlertCount,
    'leastStockItemName' => $leastStockItemName,
    'leastStockItemQuantity' => $leastStockItemQuantity,
    'lossKg' => (float)$lossKg,
    'lossPercentage' => round((float)$lossPercentage, 1)
];

// --- Output JSON ---
echo json_encode($dashboardData);

exit();

?>
