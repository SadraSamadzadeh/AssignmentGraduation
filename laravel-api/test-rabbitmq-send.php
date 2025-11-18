<?php

require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

echo "🚀 Testing RabbitMQ Message Sending...\n\n";

try {
    // Connect to RabbitMQ (use localhost since we're outside Docker)
    $connection = new AMQPStreamConnection(
        'localhost',  // Host (Docker exposes on localhost)
        5672,         // Port
        'admin',         // Username
        'admin'          // Password
    );
    
    $channel = $connection->channel();
    
    echo "✅ Connected to RabbitMQ!\n\n";
    
    // Declare the queue (same as in your app)
    $channel->queue_declare(
        'tracking_data_queue',  // Queue name
        false,                   // Passive
        true,                    // Durable
        false,                   // Exclusive
        false                    // Auto-delete
    );
    
    // Create test tracking data message
    $trackingData = [
        'type' => 'tracking',
        'data' => [
            'id' => 193,
            'name' => 'Match Capelle - Westlandia',
            'typeId' => 1,
            'typeName' => 'Match',
            'teamName' => 'Capelle 1',
            'sourceId' => 2,
            'startTime' => '2025-11-01T11:51:09.100Z',
            'endTime' => '2025-11-01T20:53:00.900Z',
            'trimmedStartTime' => '02:42:52',
            'trimmedEndTime' => '04:42:30',
            'avgTeamDistanceInMeters' => 8281.99,
            'devices' => [
                [
                    'deviceId' => 17247,
                    'serialNumber' => '300421',
                    'customName' => 4,
                    'playerId' => 14,
                    'playerName' => 'Najim Haidary',
                    'role' => 'Central defender'
                ]
            ]
        ]
    ];
    
    // Convert to JSON
    $messageBody = json_encode($trackingData);
    
    // Create AMQP message
    $message = new AMQPMessage(
        $messageBody,
        ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]
    );
    
    // Publish message to queue
    $channel->basic_publish($message, '', 'tracking_data_queue');
    
    echo "📨 Message sent successfully!\n\n";
    echo "Message content:\n";
    echo json_encode($trackingData, JSON_PRETTY_PRINT) . "\n\n";
    
    // Close connection
    $channel->close();
    $connection->close();
    
    echo "✅ Test completed!\n\n";
    echo "Next steps:\n";
    echo "1. Open RabbitMQ Management: http://localhost:15672\n";
    echo "2. Login with username: v, password: v\n";
    echo "3. Go to 'Queues' tab and click 'tracking_data_queue'\n";
    echo "4. You should see 1 message ready\n\n";
    echo "To process the message, run:\n";
    echo "docker exec -it matching_app php artisan rabbitmq:consume tracking_data_queue\n\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "\nMake sure:\n";
    echo "1. Docker containers are running: docker-compose ps\n";
    echo "2. RabbitMQ is accessible on localhost:5672\n";
    exit(1);
}