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

    protected function admin()
    {
        return Auth::guard('admin')->user();
    }

    protected function orgScopedQuery()
    {
        $admin = $this->admin();
        return $admin?->organization_id
            ? Vehicle::where('organization_id', $admin->organization_id)
            : Vehicle::query(); // super-admin: see all
    }

    public function index()
    {
        $vehicles = $this->orgScopedQuery()->get();

        return Inertia::render('Vehicle/Index', [
            'vehicles' => $vehicles,
        ]);
    }

    public function list($status)
    {
        $q = $this->orgScopedQuery();

        if ($status !== 'all') {
            $q->where('status', $status);
        }

        $vehicles = $q->get();

        return Inertia::render('Vehicle/Index', [
            'vehicles' => $vehicles,
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
        ]);
    }

    public function create(Request $request)
    {
        try {
            $request->validate([
                'title'       => 'required',
                'description' => 'required',
                'reg_num'     => 'required|unique:' . Vehicle::class,
                'price'       => 'required',
                'seats'       => 'required|integer|min:2',
                'image'       => 'required',
                'mileage'     => 'required',
                'color'       => 'required',
                'model'       => 'required',
                'year'        => 'required',
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
            $vehicle->price           = $request->price;
            $vehicle->seats           = $request->seats;
            $vehicle->per_km_price    = $request->per_km_price;
            $vehicle->reg_num         = $request->reg_num;
            $vehicle->model           = $request->model;
            $vehicle->year            = $request->year;
            $vehicle->color           = $request->color;
            $vehicle->mileage         = $request->mileage;
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
                'price'   => 'required',
                'seats'   => 'required|integer|min:2',
                'mileage' => 'required',
                'color'   => 'required',
                'model'   => 'required',
                'year'    => 'required',
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
            $vehicle->price           = $request->price;
            $vehicle->seats           = $request->seats;
            $vehicle->per_km_price    = $request->per_km_price;
            $vehicle->reg_num         = $request->reg_num;
            $vehicle->slug            = $slug;
            $vehicle->model           = $request->model;
            $vehicle->year            = $request->year;
            $vehicle->color           = $request->color;
            $vehicle->mileage         = $request->mileage;

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
