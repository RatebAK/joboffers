<?php

require __DIR__.'/vendor/autoload.php';

echo "Testing MongoDB Connection\n";
echo str_repeat("=", 60) . "\n\n";

$uri = "mongodb+srv://sa20sy_db_user:O1tUrvlHB8XUP6tj@cluster0.pjsoi1u.mongodb.net/joboffers?retryWrites=true&w=majority&appName=Cluster0";

echo "Connection URI: " . substr($uri, 0, 30) . "...\n";
echo "Attempting to connect...\n\n";

try {
    $client = new MongoDB\Client($uri, [], [
        'serverSelectionTimeoutMS' => 5000, // 5 seconds timeout
    ]);
    
    echo "✅ Client created\n";
    
    // Try to ping the server
    echo "Pinging server...\n";
    $result = $client->selectDatabase('admin')->command(['ping' => 1]);
    
    echo "✅ Successfully connected to MongoDB!\n";
    echo "Ping result: " . json_encode($result) . "\n\n";
    
    // Try to access your database
    echo "Accessing 'joboffers' database...\n";
    $database = $client->selectDatabase('joboffers');
    $collections = iterator_to_array($database->listCollections());
    
    echo "✅ Database accessible\n";
    echo "Collections found: " . count($collections) . "\n";
    
    foreach ($collections as $collection) {
        echo "  - " . $collection->getName() . "\n";
    }
    
} catch (MongoDB\Driver\Exception\ConnectionTimeoutException $e) {
    echo "\n❌ CONNECTION TIMEOUT\n";
    echo "Error: " . $e->getMessage() . "\n\n";
    echo "Possible solutions:\n";
    echo "1. Check MongoDB Atlas Network Access settings\n";
    echo "   - Add your server IP to whitelist\n";
    echo "   - Or add 0.0.0.0/0 to allow all IPs (testing only)\n\n";
    echo "2. Check if your server allows outbound connections to MongoDB Atlas\n";
    echo "   - Contact your hosting provider\n";
    echo "   - Check firewall rules\n\n";
    echo "3. Verify MongoDB cluster is running\n";
    echo "   - Check MongoDB Atlas dashboard\n";
    echo "   - Ensure cluster is not paused\n";
    
} catch (Exception $e) {
    echo "\n❌ ERROR\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "Type: " . get_class($e) . "\n";
}

echo "\n" . str_repeat("=", 60) . "\n";
