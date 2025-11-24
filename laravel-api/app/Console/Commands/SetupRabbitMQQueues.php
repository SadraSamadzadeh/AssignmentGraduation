<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;

class SetupRabbitMQQueues extends Command
{
    protected $signature = 'rabbitmq:setup-queues';
    protected $description = 'Setup RabbitMQ queues, exchanges, and bindings for the application';

    public function handle()
    {
        $this->info('Setting up RabbitMQ queues, exchanges, and bindings...');
        
        try {
            $connection = new AMQPStreamConnection(
                env('RABBITMQ_HOST', 'rabbitmq'),
                env('RABBITMQ_PORT', 5672),
                env('RABBITMQ_USER', 'admin'),
                env('RABBITMQ_PASSWORD', 'admin123'),
                env('RABBITMQ_VHOST', '/')
            );
            
            $channel = $connection->channel();
            
            $this->info('Connected to RabbitMQ successfully');
            
            $exchangeName = env('RABBITMQ_EXCHANGE_NAME', 'imocloud.events');
            $channel->exchange_declare(
                $exchangeName,
                AMQPExchangeType::TOPIC,
                false,
                true,
                false
            );
            $this->info("Exchange declared: {$exchangeName} (type: topic)");
            
            $primeplayQueue = env('RABBITMQ_QUEUE_PRIMEPLAY', 'imocloud.match.events');
            $channel->queue_declare(
                $primeplayQueue,
                false,
                true,
                false,
                false
            );
            $this->info("Queue declared: {$primeplayQueue}");
            
            $primeplayRoutingKey = env('RABBITMQ_ROUTING_KEY_PRIMEPLAY', 'match.#');
            $channel->queue_bind(
                $primeplayQueue,
                $exchangeName,
                $primeplayRoutingKey
            );
            $this->info("Queue bound: {$primeplayQueue} -> {$exchangeName} (routing key: {$primeplayRoutingKey})");
            
            $videoQueue = env('RABBITMQ_QUEUE_VIDEO', 'video.data.events');
            $channel->queue_declare(
                $videoQueue,
                false,
                true,
                false,
                false
            );
            $this->info("Queue declared: {$videoQueue}");
            
            $videoRoutingKey = env('RABBITMQ_ROUTING_KEY_VIDEO', 'video.#');
            $channel->queue_bind(
                $videoQueue,
                $exchangeName,
                $videoRoutingKey
            );
            $this->info("Queue bound: {$videoQueue} -> {$exchangeName} (routing key: {$videoRoutingKey})");
            
            $channel->close();
            $connection->close();
            
            $this->newLine();
            $this->info('RabbitMQ setup completed successfully');
            $this->newLine();
            $this->info('Summary:');
            $this->info("  - Exchange: {$exchangeName} (topic)");
            $this->info("  - Queue 1: {$primeplayQueue} (routing: {$primeplayRoutingKey})");
            $this->info("  - Queue 2: {$videoQueue} (routing: {$videoRoutingKey})");
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('Failed to setup RabbitMQ queues');
            $this->error('Error: ' . $e->getMessage());
            
            return Command::FAILURE;
        }
    }
}
