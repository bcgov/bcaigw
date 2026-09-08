<?php

namespace App\Http\Requests;

final class StoreApplicationRequest extends ApplicationDataRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }
}
