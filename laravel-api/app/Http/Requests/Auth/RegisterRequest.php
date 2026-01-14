<?php

namespace App\Http\Requests\Auth;

use App\Rules\NotDisposableEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:255',
                'regex:/^[a-zA-Z\s\-\'\.]+$/', // Only letters, spaces, hyphens, apostrophes, dots
            ],
            'email' => [
                'required',
                'string',
                'email:rfc,dns', // Validate email format and DNS
                'max:255',
                'unique:users,email',
                new NotDisposableEmail(), // Prevent disposable email addresses
            ],
            'password' => [
                'required',
                'string',
                'confirmed', // Requires password_confirmation field
                Password::min(8)
                    ->mixedCase() // Requires uppercase and lowercase
                    ->letters() // Requires at least one letter
                    ->numbers() // Requires at least one number
                    ->symbols() // Requires at least one symbol
                    ->uncompromised(), // Checks against pwned passwords database
            ],
            'password_confirmation' => [
                'required',
                'string',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Name is required.',
            'name.min' => 'Name must be at least 2 characters.',
            'name.max' => 'Name cannot exceed 255 characters.',
            'name.regex' => 'Name can only contain letters, spaces, hyphens, apostrophes, and dots.',
            
            'email.required' => 'Email address is required.',
            'email.email' => 'Please provide a valid email address.',
            'email.unique' => 'This email address is already registered.',
            
            'password.required' => 'Password is required.',
            'password.confirmed' => 'Password confirmation does not match.',
            
            'password_confirmation.required' => 'Password confirmation is required.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'name',
            'email' => 'email address',
            'password' => 'password',
            'password_confirmation' => 'password confirmation',
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422)
        );
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim($this->email ?? '')),
            'name' => trim($this->name ?? ''),
        ]);
    }
}
