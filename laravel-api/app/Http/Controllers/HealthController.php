<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function check(): JsonResponse
    {
        try {
            // Test database connectivity
            $dbStatus = 'connected';
            $dbMessage = 'Database connection successful';
            
            try {
                DB::connection()->getPdo();
            } catch (\Exception $e) {
                $dbStatus = 'disconnected';
                $dbMessage = 'Database connection failed: ' . $e->getMessage();
            }

            $health = [
                'status' => $dbStatus === 'connected' ? 'healthy' : 'unhealthy',
                'timestamp' => now()->toISOString(),
                'uptime' => $this->getUptime(),
                'database' => [
                    'status' => $dbStatus,
                    'message' => $dbMessage
                ],
                'memory' => [
                    'usage' => memory_get_usage(true),
                    'peak' => memory_get_peak_usage(true)
                ]
            ];

            $statusCode = $dbStatus === 'connected' ? 200 : 503;
            
            return $this->successResponse($health, 'Health check completed', $statusCode);
        } catch (\Exception $e) {
            return $this->errorResponse('Health check failed: ' . $e->getMessage(), 500);
        }
    }

    private function getUptime(): array
    {
        $uptime = time() - (int)($_SERVER['REQUEST_TIME'] ?? time());
        
        return [
            'seconds' => $uptime,
            'formatted' => gmdate('H:i:s', $uptime)
        ];
    }
}