<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use App\Jobs\ProcessVideoMessage;

class ConsumeVideoRabbitMQ extends Command
{
    protected $signature = 'rabbitmq:consume-video';
    protected $description = 'Consume video messages from RabbitMQ';

    public function handle()
    {
        $config = config('queue.connections.rabbitmq_video');
        $host = $config['hosts'][0];
        
        $connection = new AMQPStreamConnection(
            $host['host'],
            $host['port'],
            $host['user'],
            $host['password'],
            $host['vhost']
        );

        $channel = $connection->channel();
        
        $exchange = $config['options']['exchange']['name'];
        $queue = $config['queue'];

        // Declare the exchange
        $channel->exchange_declare(
            $exchange,
            'topic',
            false,  // passive
            true,   // durable
            false   // auto_delete
        );
        
        // Declare the queue
        $channel->queue_declare(
            $queue,
            false,  // passive
            true,   // durable
            false,  // exclusive
            false   // auto_delete
        );
        
        // Bind the queue to the exchange with routing key patterns for video data
        $channel->queue_bind($queue, $exchange, 'video.#');
        $channel->queue_bind($queue, $exchange, 'video.data.#');

        $this->info("Starting Video RabbitMQ consumer...");
        $this->info("Connected to RabbitMQ. Listening on queue: {$queue}");

        $callback = function (AMQPMessage $msg) {
            try {
                $messageData = json_decode($msg->body, true);
                $routingKey = $msg->getRoutingKey();

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->error('Failed to decode message: ' . json_last_error_msg());
                    $msg->ack();
                    return;
                }

                // Process the video message directly (no dispatch)
                $job = new ProcessVideoMessage($messageData, $routingKey);
                $job->handle();

                $msg->ack();

            } catch (\Exception $e) {
                $this->error('Error processing message: ' . $e->getMessage());
                $msg->nack(false, false); // Don't requeue
            }
        };

        $channel->basic_qos(null, 1, null);
        $channel->basic_consume($queue, '', false, false, false, false, $callback);

        $this->info('Waiting for messages. To exit press CTRL+C');

        while ($channel->is_consuming()) {
            $channel->wait();
        }

        $channel->close();
        $connection->close();
    }
}
