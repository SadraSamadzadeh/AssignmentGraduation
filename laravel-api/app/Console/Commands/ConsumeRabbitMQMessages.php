<?php

namespace App\Console\Commands;

use App\Jobs\ProcessIncomingMessage;
use App\Services\RabbitMQService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ConsumeRabbitMQMessages extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'rabbitmq:consume {queue=tracking_data_queue}';

    /**
     * The console command description.
     */
    protected $description = 'Consume messages from RabbitMQ queue and process them';

    private RabbitMQService $rabbitMQService;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $queueName = $this->argument('queue');
        
        $this->info("Starting RabbitMQ consumer for queue: {$queueName}");
        
        $this->rabbitMQService = new RabbitMQService();
        
        if (!$this->rabbitMQService->isConnected()) {
            $this->error('Failed to connect to RabbitMQ');
            return 1;
        }

        $this->info('Connected to RabbitMQ successfully');
        $this->info('Waiting for messages... (Press CTRL+C to exit)');

        try {
            // Determine message type based on queue name
            $messageType = $this->getMessageType($queueName);

            // Start consuming messages
            $this->rabbitMQService->consumeQueue($queueName, function ($message) use ($messageType) {
                $this->processMessage($message, $messageType);
            });

        } catch (\Exception $e) {
            $this->error('Error consuming messages: ' . $e->getMessage());
            Log::error('RabbitMQ consumer error', [
                'queue' => $queueName,
                'error' => $e->getMessage()
            ]);
            return 1;
        }

        return 0;
    }

    /**
     * Process incoming message
     */
    private function processMessage($amqpMessage, string $type): void
    {
        try {
            $messageBody = json_decode($amqpMessage->body, true);
            
            $id = $messageBody['id'] ?? 'unknown';
            $this->info("Received {$type} message - ID: {$id}");

            // Dispatch job to process the message
            ProcessIncomingMessage::dispatch($messageBody, $type);

            // Acknowledge the message
            $amqpMessage->ack();
            
            $this->line("✓ Message processed and acknowledged");

        } catch (\Exception $e) {
            $this->error("✗ Failed to process message: " . $e->getMessage());
            
            // Reject and requeue the message
            $amqpMessage->nack(true);
        }
    }

    /**
     * Determine message type from queue name
     */
    private function getMessageType(string $queueName): string
    {
        if (str_contains($queueName, 'tracking')) {
            return 'tracking';
        } elseif (str_contains($queueName, 'video')) {
            return 'video';
        }
        return 'unknown';
    }
}
