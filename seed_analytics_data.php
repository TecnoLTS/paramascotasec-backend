<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Database;

try {
    $db = Database::getInstance();

    echo "Seeding Analytics Data...\n";

    // 1. Ensure we have some users
    // Create users 2, 3, 4 if they don't exist to simulate clients
    $users = [
        ['id' => 2, 'name' => 'Juan Perez', 'email' => 'juan@example.com', 'password' => password_hash('password', PASSWORD_DEFAULT)],
        ['id' => 3, 'name' => 'Maria Lopez', 'email' => 'maria@example.com', 'password' => password_hash('password', PASSWORD_DEFAULT)],
        ['id' => 4, 'name' => 'Carlos Andrade', 'email' => 'carlos@example.com', 'password' => password_hash('password', PASSWORD_DEFAULT)],
    ];

    $stmtUserCheck = $db->prepare('SELECT id FROM "User" WHERE id = :id');
    $stmtUserInsert = $db->prepare('INSERT INTO "User" (id, name, email, password, role, "updatedAt", "createdAt") VALUES (:id, :name, :email, :password, \'client\', NOW(), NOW())');

    foreach ($users as $u) {
        $stmtUserCheck->execute(['id' => $u['id']]);
        if (!$stmtUserCheck->fetch()) {
            $stmtUserInsert->execute([
                'id' => $u['id'],
                'name' => $u['name'],
                'email' => $u['email'],
                'password' => $u['password']
            ]);
            echo "Created User: {$u['name']}\n";
        }
    }

    // Fetch all valid user IDs
    $stmtUsers = $db->query('SELECT id FROM "User"');
    $availableUserIds = $stmtUsers->fetchAll(PDO::FETCH_COLUMN);
    
    // 2. Clear existing orders (optional, but good for clean slate if requested)
    // Uncomment the next line if you want to wipe orders first
    // $db->exec('TRUNCATE TABLE "Order" CASCADE'); 

    // 3. Generate Orders for the last 10 days
    $statuses = ['pending', 'processing', 'completed', 'shipped', 'delivered'];
    
    // Fake product names for items
    $products = [
        ['id' => 1, 'name' => 'Royal Canin Size Health Nutrition', 'price' => 45.00],
        ['id' => 2, 'name' => 'Purina Pro Plan Adulto', 'price' => 55.50],
        ['id' => 3, 'name' => 'Blue Buffalo Life Protection', 'price' => 62.00],
        ['id' => 4, 'name' => 'Hill\'s Science Diet Dry Dog Food', 'price' => 48.99],
        ['id' => 5, 'name' => 'Wellness CON CORE Natural', 'price' => 75.00],
        ['id' => 6, 'name' => 'Taste of the Wild High Prairie', 'price' => 52.00],
    ];

    $orderCount = 35;

    for ($i = 0; $i < $orderCount; $i++) {
        // Random date within last 10 days
        $daysAgo = rand(0, 9); 
        $hour = rand(8, 20);
        $minute = rand(0, 59);
        $dateStr = date('Y-m-d H:i:s', strtotime("-$daysAgo days $hour:$minute:00")); // e.g. "2023-10-27 14:30:00"

        $orderId = uniqid('ORD-');
        $userId = $availableUserIds[array_rand($availableUserIds)];
        $status = $statuses[array_rand($statuses)];
        
        // Random items
        $numItems = rand(1, 4);
        $orderTotal = 0;
        $orderItems = [];

        for ($j = 0; $j < $numItems; $j++) {
            $prod = $products[array_rand($products)];
            $qty = rand(1, 2);
            $lineTotal = $prod['price'] * $qty;
            $orderTotal += $lineTotal;
            $orderItems[] = [
                'product_id' => $prod['id'],
                'product_name' => $prod['name'],
                'quantity' => $qty,
                'price' => $prod['price']
            ];
        }

        // Insert Order
        $stmtOrder = $db->prepare('INSERT INTO "Order" ("id", "user_id", "total", "status", "created_at", "shipping_address", "billing_address") VALUES (:id, :user_id, :total, :status, :created_at, :shipping_address, :billing_address)');
        
        $stmtOrder->execute([
            'id' => $orderId,
            'user_id' => $userId,
            'total' => $orderTotal,
            'status' => $status,
            'created_at' => $dateStr,
            'shipping_address' => json_encode(['address' => '123 Fake St', 'city' => 'Quito']),
            'billing_address' => json_encode(['address' => '123 Fake St', 'city' => 'Quito'])
        ]);

        // Insert Items
        $stmtItem = $db->prepare('INSERT INTO "OrderItem" ("order_id", "product_id", "product_name", "product_image", "quantity", "price") VALUES (:order_id, :product_id, :product_name, :product_image, :quantity, :price)');
        
        foreach ($orderItems as $item) {
            $stmtItem->execute([
                'order_id' => $orderId,
                'product_id' => $item['product_id'],
                'product_name' => $item['product_name'],
                'product_image' => '/images/product/1000x1000.png', // Placeholder
                'quantity' => $item['quantity'],
                'price' => $item['price']
            ]);
        }
        
        echo "Created Order $orderId ($dateStr) - Total: $$orderTotal\n";
    }

    echo "Done! Created $orderCount orders.\n";

} catch (PDOException $e) {
    echo "DB Error: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
