<?php

namespace Database\Factories;

use App\Models\ReservationRequest;
use App\Models\ReservationTableAssignment;
use App\Models\Table;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReservationTableAssignment>
 */
class ReservationTableAssignmentFactory extends Factory
{
    protected $model = ReservationTableAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reservation_request_id' => ReservationRequest::factory(),
            'table_id' => Table::factory(),
            'assigned_at' => now(),
            'assigned_by_user_id' => null,
        ];
    }

    public function forReservationRequest(ReservationRequest $request): static
    {
        return $this->state(fn () => [
            'reservation_request_id' => $request->id,
        ]);
    }
}
