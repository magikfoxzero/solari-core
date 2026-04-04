<?php

namespace NewSolari\Core\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Base Form Request for API endpoints
 *
 * Provides consistent validation error response format matching the API standard:
 * - value: boolean indicating success/failure
 * - result: error message or data
 * - code: HTTP status code
 * - errors: validation errors (optional)
 */
abstract class BaseApiFormRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Authorization is handled in the controller via plugin permission checks.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Handle a failed validation attempt.
     * Returns JSON response matching the API standard format.
     *
     * @return void
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        $errors = $validator->errors();
        $firstError = $errors->first();

        throw new HttpResponseException(response()->json([
            'value' => false,
            'result' => 'Validation failed: '.$firstError,
            'code' => 422,
            'errors' => $errors,
        ], 422));
    }
}
