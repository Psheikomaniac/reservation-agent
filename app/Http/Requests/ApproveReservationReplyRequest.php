<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveReservationReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is enforced by the route's `can:approve,reply` gate
        // — this FormRequest only validates the optional body override.
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // Operator-edited body — optional. When present it overwrites
            // the AI draft before the send job dispatches. The hard cap is
            // generous but bounded; a reply much longer than this is almost
            // certainly an unintentional paste.
            'body' => ['sometimes', 'string', 'max:4000'],
        ];
    }
}
