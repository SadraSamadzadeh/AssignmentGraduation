<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Wire\AMQPTable;

class InitializeRabbitMQ extends Command
{
    protected $signature = 'rabbitmq:init';
    protected $description = 'Initialize RabbitMQ exchanges, queues, and bindings';

    public function handle(): int
    {
        try {
            $this->info('Connecting to RabbitMQ...');
            
            $connection = new AMQPStreamConnection(
                config('queue.connections.rabbitmq.hosts.0.host'),
                config('queue.connections.rabbitmq.hosts.0.port'),
                config('queue.connections.rabbitmq.hosts.0.user'),
                config('queue.connections.rabbitmq.hosts.0.password'),
                config('queue.connections.rabbitmq.hosts.0.vhost', '/')
            );
            
            $channel = $connection->channel();
            
            // Declare exchange
            $exchangeName = 'integration.layer.events';
            $exchangeType = 'topic';
            
            $this->info("Declaring exchange: {$exchangeName}");
            $channel->exchange_declare(
                $exchangeName,
                $exchangeType,
                false,  // passive
                true,   // durable
                false   // auto_delete
            );
            
            // Declare queues
            $queues = [
                'imocloud.match.events',
                'video.data.events'
            ];
            
            foreach ($queues as $queueName) {
                $this->info("Declaring queue: {$queueName}");
                $channel->queue_declare(
                    $queueName,
                    false,  // passive
                    true,   // durable
                    false,  // exclusive
                    false   // auto_delete
                );
                
                // Bind queue to exchange
                $this->info("Binding queue '{$queueName}' to exchange with routing key: #");
                $channel->queue_bind(
                    $queueName,
                    $exchangeName,
                    '#'  // routing key - bind all messages
                );
            }
            
            $channel->close();
            $connection->close();
            
            $this->info('RabbitMQ initialization completed successfully');
            return 0;
            
        } catch (\Exception $e) {
            $this->error('Failed to initialize RabbitMQ: ' . $e->getMessage());
            return 1;
        }
    }
}
