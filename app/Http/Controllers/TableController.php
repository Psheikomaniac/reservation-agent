<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\TableRequest;
use App\Http\Resources\TableResource;
use App\Models\Table;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD for table master data (PRD-011).
 *
 * Tenant isolation is not done here: the Table model carries the global
 * RestaurantScope, so every query and route-model binding is already
 * confined to the acting user's restaurant. A foreign table id therefore
 * resolves to a 404 at route binding — the project's tenant-isolation
 * contract — before the policy gate would even fire. Role/ability checks
 * live in TablePolicy and are wired via the `can:` middleware on the routes.
 */
final class TableController extends Controller
{
    public function index(): Response
    {
        $tables = Table::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return Inertia::render('Tables', [
            'tables' => TableResource::collection($tables),
        ]);
    }

    public function store(TableRequest $request): RedirectResponse
    {
        Table::create([
            ...$request->validated(),
            // restaurant_id is taken from the authenticated user, never from
            // the request body (TablePolicy::create only gates role + membership).
            'restaurant_id' => $request->user()->restaurant_id,
        ]);

        return redirect()->route('tables.index')->with('success', 'Tisch angelegt.');
    }

    public function update(TableRequest $request, Table $table): RedirectResponse
    {
        $table->update($request->validated());

        return redirect()->route('tables.index')->with('success', 'Tisch aktualisiert.');
    }

    /**
     * Soft-deactivate rather than hard-delete: the row is kept so historical
     * reservation-table assignments stay referenceable; the table simply drops
     * out of the active grid and can be reactivated via update.
     */
    public function destroy(Table $table): RedirectResponse
    {
        $table->update(['active' => false]);

        return redirect()->route('tables.index')->with('success', 'Tisch deaktiviert.');
    }
}
