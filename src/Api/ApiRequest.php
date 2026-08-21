<?php

declare(strict_types=1);

namespace PHPAML\Api;

use PHPAML\Http\Request;
use PHPAML\Validation\Validator;

abstract class ApiRequest
{
    /** @return array<string, list<string>|string> */
    abstract public function rules(): array;

    /** @return array<string, mixed> */
    final public function validated(Request $request): array
    {
        $input = $request->input();
        $validator = new Validator();
        if (!$validator->validate($input, $this->rules())) {
            throw new ApiValidationException($validator->errors());
        }
        return $input;
    }
}
