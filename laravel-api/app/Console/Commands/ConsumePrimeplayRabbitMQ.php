<?php

namespace App\Console\Commands;

use App\Jobs\ProcessPrimeplayMessage;
use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class ConsumePrimeplayRabbitMQ extends Command
{
    protected $signature = 'rabbitmq:consume-primeplay';
    protected $description = 'Consume messages from Primeplay RabbitMQ queue';

    public function handle()
    {
        $this->info('Starting Primeplay RabbitMQ consumer...');
        
        $connection = new AMQPStreamConnection(
            env('RABBITMQ_HOST', 'rabbitmq'),
            env('RABBITMQ_PORT', 5672),
            env('RABBITMQ_USER', 'admin'),
            env('RABBITMQ_PASSWORD', 'admin'),
            env('RABBITMQ_VHOST', '/')
        );
        
        $channel = $connection->channel();
        
        $exchangeName = env('RABBITMQ_EXCHANGE', 'integration.layer.events');
        $queueName = env('RABBITMQ_QUEUE_PRIMEPLAY', 'imocloud.match.events');
        
        // Declare the exchange
        $channel->exchange_declare(
            $exchangeName,
            'topic',
            false,  // passive
            true,   // durable
            false   // auto_delete
        );
        
        // Declare the queue
        $channel->queue_declare(
            $queueName,
            false,  // passive
            true,   // durable
            false,  // exclusive
            false   // auto_delete
        );
        
        // Bind the queue to the exchange with routing key pattern
        $channel->queue_bind($queueName, $exchangeName, 'match.#');
        
        $this->info("Connected to RabbitMQ. Listening on queue: {$queueName}");
        $this->info('Waiting for messages. To exit press CTRL+C');
        
        $callback = function (AMQPMessage $msg) {
            $this->info('[' . date('Y-m-d H:i:s') . '] Message received');
            
            try {
                $messageData = json_decode($msg->body, true);
                $routingKey = $msg->getRoutingKey();
                
                $this->info("Routing Key: {$routingKey}");
                $this->line(json_encode($messageData, JSON_PRETTY_PRINT));
                
                // Process the message directly using the Job's handle method
                $job = new ProcessPrimeplayMessage($messageData, $routingKey);
                $job->handle();
                
                $msg->ack();
                
            } catch (\Exception $e) {
                $this->error('Error processing message: ' . $e->getMessage());
                // Reject and don't requeue if there's an error
                $msg->nack(false);
            }
        };
        
        $channel->basic_qos(0, 1, false);
        $channel->basic_consume(
            $queueName,
            '',
            false,  // no_local
            false,  // no_ack
            false,  // exclusive
            false,  // nowait
            $callback
        );
        
        while ($channel->is_consuming()) {
            $channel->wait();
        }
        
        $channel->close();
        $connection->close();
        
        return Command::SUCCESS;
    }
}
