<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class BenchmarkDashboardCommand extends Command
{
    protected $signature = 'dashboard:benchmark
        {--restaurant= : Restaurant id to benchmark against (defaults to first benchmark-restaurant-* slug, then the first restaurant in the table)}
        {--iterations=20 : Number of timed query runs}
        {--warmup=2 : Untimed runs to prime the SQLite page cache}';

    protected $description = 'Time the default dashboard query and dump EXPLAIN QUERY PLAN to validate index usage.';

    public function handle(): int
    {
        $restaurant = $this->resolveRestaurant();

        if ($restaurant === null) {
            $this->error('No restaurant available. Run `php artisan db:seed --class=PerformanceBenchmarkSeeder` first.');

            return self::FAILURE;
        }

        $rowCount = DB::table('reservation_requests')->count();
        $tenantRowCount = DB::table('reservation_requests')
            ->where('restaurant_id', $restaurant->id)
            ->count();

        $this->line(sprintf(
            'Driver: %s | total rows: %d | tenant rows: %d | restaurant: #%d %s',
            DB::connection()->getDriverName(),
            $rowCount,
            $tenantRowCount,
            $restaurant->id,
            $restaurant->name,
        ));

        $iterations = max(1, (int) $this->option('iterations'));
        $warmup = max(0, (int) $this->option('warmup'));

        for ($i = 0; $i < $warmup; $i++) {
            $this->buildQuery($restaurant->id)->paginate(25);
        }

        $samples = [];

        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);
            $this->buildQuery($restaurant->id)
                ->with(['latestReply'])
                ->paginate(25);
            $samples[] = (hrtime(true) - $start) / 1_000_000;
        }

        $this->printStats($samples);
        $this->printExplain($restaurant->id);

        return self::SUCCESS;
    }

    private function resolveRestaurant(): ?Restaurant
    {
        $explicit = $this->option('restaurant');

        if ($explicit !== null) {
            return Restaurant::query()->find((int) $explicit);
        }

        return Restaurant::query()
            ->where('slug', 'like', 'benchmark-restaurant-%')
            ->orderBy('id')
            ->first()
            ?? Restaurant::query()->orderBy('id')->first();
    }

    /**
     * Mirrors `DashboardController::index` for the default filter set:
     * status ∈ {new, in_review} AND desired_at >= today, ordered by
     * created_at DESC. Restaurant scope is applied manually because the
     * command runs without an authenticated user.
     *
     * @return Builder<ReservationRequest>
     */
    private function buildQuery(int $restaurantId): Builder
    {
        return ReservationRequest::query()
            ->withoutGlobalScopes()
            ->where('restaurant_id', $restaurantId)
            ->whereIn('status', [ReservationStatus::New, ReservationStatus::InReview])
            ->where('desired_at', '>=', Carbon::now()->startOfDay())
            ->orderByDesc('created_at');
    }

    /**
     * @param  list<float>  $samples  Milliseconds.
     */
    private function printStats(array $samples): void
    {
        sort($samples);
        $count = count($samples);
        $sum = array_sum($samples);

        $this->newLine();
        $this->info(sprintf('Iterations: %d', $count));
        $this->line(sprintf('  min : %6.2f ms', $samples[0]));
        $this->line(sprintf('  avg : %6.2f ms', $sum / $count));
        $this->line(sprintf('  p50 : %6.2f ms', $samples[(int) floor($count * 0.50)] ?? $samples[$count - 1]));
        $this->line(sprintf('  p95 : %6.2f ms', $samples[(int) floor($count * 0.95)] ?? $samples[$count - 1]));
        $this->line(sprintf('  max : %6.2f ms', $samples[$count - 1]));
    }

    private function printExplain(int $restaurantId): void
    {
        $query = $this->buildQuery($restaurantId);
        $sql = $query->toSql();
        $bindings = $query->getBindings();

        $driver = DB::connection()->getDriverName();
        $explain = match ($driver) {
            'sqlite' => 'EXPLAIN QUERY PLAN '.$sql,
            'mysql', 'mariadb' => 'EXPLAIN '.$sql,
            'pgsql' => 'EXPLAIN '.$sql,
            default => null,
        };

        $this->newLine();
        $this->info('EXPLAIN');

        if ($explain === null) {
            $this->warn(sprintf('No EXPLAIN strategy registered for driver "%s".', $driver));

            return;
        }

        foreach (DB::select($explain, $bindings) as $row) {
            $this->line('  '.json_encode($row, JSON_UNESCAPED_SLASHES));
        }
    }
}
