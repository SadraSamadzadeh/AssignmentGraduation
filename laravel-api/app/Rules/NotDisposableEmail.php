<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NotDisposableEmail implements ValidationRule
{
    /**
     * List of known disposable email domains
     * In production, consider using a package like "matt-allan/laravel-disposable-email"
     * or maintain an updated list via API service
     */
    protected array $disposableDomains = [
        'tempmail.com',
        'throwaway.email',
        'guerrillamail.com',
        '10minutemail.com',
        'mailinator.com',
        'maildrop.cc',
        'temp-mail.org',
        'yopmail.com',
        'getnada.com',
        'fakeinbox.com',
        'trashmail.com',
        'dispostable.com',
        'mohmal.com',
        'sharklasers.com',
    ];

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $domain = strtolower(substr(strrchr($value, "@"), 1));
        
        if (in_array($domain, $this->disposableDomains)) {
            $fail('Disposable email addresses are not allowed.');
        }
    }
}
