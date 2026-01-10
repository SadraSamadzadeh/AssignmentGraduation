#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

// Create a test user
$email = 'test@zipper.com';

// Check if user exists
$existingUser = User::where('email', $email)->first();

if ($existingUser) {
    echo "User already exists with ID: {$existingUser->id}\n";
    echo "Email: {$existingUser->email}\n";
} else {
    $user = User::create([
        'name' => 'Test User',
        'email' => $email,
        'password' => Hash::make('password123'),
    ]);

    echo "User created successfully!\n";
    echo "ID: {$user->id}\n";
    echo "Name: {$user->name}\n";
    echo "Email: {$user->email}\n";
}

// Test JWT token generation
echo "\nGenerating JWT token...\n";

try {
    $token = \Tymon\JWTAuth\Facades\JWTAuth::fromUser(User::where('email', $email)->first());
    echo "Token generated successfully!\n";
    echo "Token (first 50 chars): " . substr($token, 0, 50) . "...\n";
    echo "\nUse this token for testing:\n";
    echo "Authorization: Bearer {$token}\n";
} catch (\Exception $e) {
    echo "Error generating token: {$e->getMessage()}\n";
}
