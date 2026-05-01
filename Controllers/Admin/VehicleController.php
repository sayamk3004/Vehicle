<?php

namespace App\Modules\Vehicle\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Helpers\Helpers;
use App\Modules\Shared\Models\JobVehicle;
use App\Modules\Shared\Models\Organization;
use App\Modules\Shared\Models\Vehicle;
use App\Modules\Vehicle\Services\VehicleAvailabilityService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class VehicleController extends Controller
{
    public function __construct(
        protected VehicleAvailabilityService $availabilityService
    ) {}

    protected function nullableNumeric(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return null;
            }
        }

        return is_numeric($value) ? $value : null;
    }

    protected function admin()
    {
        return Auth::guard('admin')->user();
    }

    protected function orgScopedQuery()
    {
        $admin = $this->admin();
        $base = Vehicle::select(['id', 'organization_id', 'title', 'reg_num', 'mileage', 'seats', 'image', 'status', 'created_at']);
        return $admin?->organization_id
            ? $base->where('organization_id', $admin->organization_id)
            : $base; // super-admin: see all
    }

    public function index()
    {
        $vehicles = $this->orgScopedQuery()->latest()->paginate(20);

        return Inertia::render('Vehicle/Index', [
            'vehicles' => $vehicles,
            'routeBase' => 'admin.vehicle',
        ]);
    }

    public function list($status)
    {
        $q = $this->orgScopedQuery();

        if ($status !== 'all') {
            $q->where('status', $status);
        }

        $vehicles = $q->latest()->paginate(20);

        return Inertia::render('Vehicle/Index', [
            'vehicles' => $vehicles,
            'routeBase' => 'admin.vehicle',
        ]);
    }

    public function fetch($slug)
    {
        $organization = Organization::where('slug', $slug)->firstOrFail();

        $vehicles = $organization->vehicles()
            ->where('status', 'active')
            ->get();

        return response()->json($vehicles);
    }

    public function view($id)
    {
        $vehicle = $this->orgScopedQuery()->findOrFail($id);

        return Inertia::render('Admin/Vehicle/View', [
            'vehicle' => $vehicle,
        ]);
    }

    public function add()
    {
        return Inertia::render('Admin/Vehicle/Create');
    }

    public function edit($id)
    {
        $vehicle = $this->orgScopedQuery()->findOrFail($id);

        return Inertia::render('Admin/Vehicle/Edit', [
            'vehicle' => $vehicle,
            'routeBase' => 'admin.vehicle',
            'redirectUrl' => route('admin.vehicle.index'),
        ]);
    }

    public function create(Request $request)
    {
        try {
            $request->validate([
                'title'       => 'required',
                'description' => 'required',
                'reg_num'     => 'required|unique:' . Vehicle::class,
                'seats'       => 'required|integer|min:2',
                'image'       => 'required',
                'mileage'     => 'required|numeric|min:0',
                'color'       => 'required',
                'model'       => 'required',
                'year'        => 'required|integer|min:1900',
            ]);

            $slugBase = Str::slug($request->title, '-');
            $slug = Vehicle::where('slug', $slugBase)->exists()
                ? Str::slug($request->title . ' ' . (Vehicle::max('id') + 1), '-')
                : $slugBase;

            $admin = $this->admin();

            $vehicle = new Vehicle();
            $vehicle->user_id         = NULL;
            $vehicle->organization_id = NULL;
            $vehicle->title           = $request->title;
            $vehicle->description     = $request->description;
            $vehicle->seats           = (int) $request->seats;
            $vehicle->reg_num         = $request->reg_num;
            $vehicle->model           = $request->model;
            $vehicle->year            = (int) $request->year;
            $vehicle->color           = $request->color;
            $vehicle->mileage         = $this->nullableNumeric($request->mileage);
            $vehicle->slug            = $slug;
            $vehicle->status          = 'active';

            if ($request->hasFile('image')) {
                $vehicle->image = Helpers::upload('vehicles/', 'webp', $request->file('image'));
            }

            $vehicle->save();

            return redirect()
                ->route('admin.vehicle.list', ['status' => 'active'])
                ->with('success', 'Vehicle created successfully');
        } catch (Exception $e) {
            return back()->withErrors($e->getMessage())->withInput();
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'title'       => 'required',
                'description' => 'required',
                'reg_num'     => [
                    'required',
                    Rule::unique('vehicles', 'reg_num')->ignore($id),
                ],
                'seats'   => 'required|integer|min:2',
                'mileage' => 'required|numeric|min:0',
                'color'   => 'required',
                'model'   => 'required',
                'year'    => 'required|integer|min:1900',
            ]);

            $vehicle = $this->orgScopedQuery()->findOrFail($id);

            $slugBase = Str::slug($request->title, '-');
            $slug = Vehicle::where('slug', $slugBase)->where('id', '!=', $vehicle->id)->exists()
                ? Str::slug($request->title . ' ' . $vehicle->id, '-')
                : $slugBase;

            $admin = $this->admin();

            $vehicle->user_id         = NULL;
            $vehicle->organization_id = NULL;
            $vehicle->title           = $request->title;
            $vehicle->description     = $request->description;
            $vehicle->seats           = (int) $request->seats;
            $vehicle->reg_num         = $request->reg_num;
            $vehicle->slug            = $slug;
            $vehicle->model           = $request->model;
            $vehicle->year            = (int) $request->year;
            $vehicle->color           = $request->color;
            $vehicle->mileage         = $this->nullableNumeric($request->mileage);

            if ($request->hasFile('image')) {
                $vehicle->image = Helpers::upload('vehicles/', 'webp', $request->file('image'));
            }

            $vehicle->save();

            return back()->with('success', 'Vehicle updated successfully.');
        } catch (Exception $e) {
            return back()->withErrors($e->getMessage());
        }
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|string|in:active,paused',
        ]);

        $vehicle = $this->orgScopedQuery()->findOrFail($id);

        // Example rule if you later need to restrict pausing when future jobs exist
        // $today = now()->toDateString();

        $vehicle->status = $request->input('status');
        $vehicle->save();

        return back()->with('success', 'Vehicle status updated successfully');
    }

    public function delete($id)
    {
        $vehicle = $this->orgScopedQuery()->findOrFail($id);



        foreach ($vehicle->maintenances as $maintenance) {
            if ($maintenance->image) {
                Storage::disk('public')->delete("maintainance/" . $maintenance->image);
            }
            $maintenance->delete();
        }

        foreach ($vehicle->jobVehicles as $jv) {
            $jv->delete();
        }

        if ($vehicle->image) {
            Storage::disk('public')->delete('Vehicles/' . $vehicle->image);
        }

        $vehicle->delete();

        return back()->with('success', 'Vehicle and related records deleted successfully.');
    }

    public function check(Request $request, $vehicleId)
    {
        $request->validate([
            'date'     => 'required|date',
            'time'     => 'required',
            'duration' => 'nullable|integer|min:1',
            'timezone' => 'nullable|string',
        ]);

        $available = $this->availabilityService->isAvailable(
            $vehicleId,
            $request->date,
            $request->time,
            $request->duration ?? 1,
            []
        );

        return response()->json(['available' => $available]);
    }
}
