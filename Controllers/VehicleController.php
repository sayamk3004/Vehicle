<?php

namespace App\Modules\Vehicle\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Contracts\AuthContract;
use App\Modules\Shared\Helpers\Helpers;
use App\Modules\Shared\Models\JobVehicle;
use App\Modules\Shared\Models\Organization;
use App\Modules\Shared\Models\Vehicle;
use App\Modules\Vehicle\Services\VehicleAvailabilityService;
use Inertia\Inertia;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class VehicleController extends Controller
{
    protected $authService;
    protected $availabilityService;

    public function __construct(AuthContract $authService, VehicleAvailabilityService $availabilityService)
    {
        $this->authService = $authService;
        $this->availabilityService = $availabilityService;
    }


    public function check(Request $request, $vehicleId)
    {
        $request->validate([
            'date' => 'required|date',
            'time' => 'required',
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
    public function list($status)
    {
        if ($status != 'all') {
            $vehicles = Vehicle::where('organization_id', $this->authService->getAuthenticatedUser()->organization_id)->where('status', $status)->get();
        } else {
            $vehicles = Vehicle::where('organization_id', $this->authService->getAuthenticatedUser()->organization_id)->get();
        }

        return Inertia::render('Vehicle/Index', [
            'vehicles' => $vehicles
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
    public function index()
    {
        if (!$this->authService->getAuthenticatedUser()) {
            if (Auth::guard('admin')->check()) {
                $vehicles = Vehicle::all();

                return Inertia::render('Vehicle/Index', [
                    'vehicles' => $vehicles
                ]);
            }
            abort(403, 'No Permission');
        } else {
            $vehicles = Vehicle::where('organization_id', $this->authService->getAuthenticatedUser()->organization_id)->get();

            return Inertia::render('Vehicle/Index', [
                'vehicles' => $vehicles
            ]);
        }
    }
    public function view($id)
    {
        $vehicle = Vehicle::findOrfail($id);

        return Inertia::render('Vehicle/View', [
            'vehicle' => $vehicle,
        ]);
    }
    public function add()
    {
        return Inertia::render('Vehicle/Create');
    }
    public function edit($id)
    {
        $vehicle = Vehicle::findOrfail($id);
        return Inertia::render('Vehicle/Edit', [
            'vehicle' => $vehicle,
        ]);
    }
    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'title' => 'required',
                'description' => 'required',
                'reg_num' => [
                    'required',
                ],
                'price' => 'required',
                'seats' => 'required|integer|min:2', // Ensures seats is at least 2
                // 'image' => 'required',
                'mileage' => 'required',
                'color' => 'required',
                'model' => 'required',
                'year' => 'required'
            ], [
                'title.required' => 'Title is required',
                'description.required' => 'Description is required',
                'reg_num.required' => 'Registration number is required',
                'reg_num.unique' => 'Registration number is already taken',
                'price.required' => 'Price is required',
                'seats.required' => 'Seats is required',
                'seats.integer' => 'Seats must be a valid number',
                'seats.min' => 'Seats must be at least 2',
                'image.required' => 'Vehicle image is required',
                'mileage.required' => 'Mileage is required',
                'color.required' => 'Color is required',
                'model.required' => 'Model is required',
                'year.required' => 'Vehicle year is required'
            ]);

            $check_slug = Vehicle::where('slug', Str::slug($request->title, '-'))->first();
            if (!empty($check_slug)) {
                $last_id = Vehicle::select('id')->orderByDesc('id')->first();
                $slug = Str::slug($request->title . ' ' . $last_id->id, '-');
            } else {
                $slug = Str::slug($request->title, '-');
            }
            $vehicle = Vehicle::findOrfail($id);
            $vehicle->title = $request->title;
            $vehicle->description = $request->description;
            $vehicle->price = $request->price;
            $vehicle->seats = $request->seats;
            $vehicle->per_km_price = $request->per_km_price;
            $vehicle->reg_num = $request->reg_num;
            $vehicle->slug = $slug;
            $vehicle->model = $request->model;
            $vehicle->year = $request->year;
            $vehicle->color = $request->color;
            $vehicle->mileage = $request->mileage;

            if ($request->has('image') && $request->image != '') {
                $image_name = null;
                $image_name = Helpers::upload('vehicles/', 'webp', $request->file('image'));
                $vehicle->image = $image_name;
            }
            $vehicle->save();
            return redirect()->back()->with('success', 'Vehicle updated successfully.');
        } catch (Exception $exception) {
            return back()->withErrors($exception->getMessage());
        }
    }
    public function create(Request $request)
    {

        try {
            $request->validate([
                'title' => 'required',
                'description' => 'required',
                'reg_num' => 'required|unique:' . Vehicle::class,
                'price' => 'required',
                'seats' => 'required|integer|min:2',
                'image' => 'required',
                'mileage' => 'required',
                'color' => 'required',
                'model' => 'required',
                'year' => 'required'
            ], [
                'title.required' => 'Title is required',
                'description.required' => 'Description is required',
                'reg_num.required' => 'Registration number is required',
                'reg_num.unique' => 'Registration number is already taken',
                'price.required' => 'Price is required',
                'seats.required' => 'Seats is required',
                'seats.integer' => 'Seats must be a valid number',
                'seats.min' => 'Seats must be at least 2',
                'image.required' => 'Vehicle image is required',
                'mileage.required' => 'Mileage is required',
                'color.required' => 'Color is required',
                'model.required' => 'Model is required',
                'year.required' => 'Vehicle year is required'
            ]);

            $check_slug = Vehicle::where('slug', Str::slug($request->title, '-'))->first();
            if (!empty($check_slug)) {
                $last_id = Vehicle::select('id')->orderByDesc('id')->first();
                $slug = Str::slug($request->title . ' ' . $last_id->id, '-');
            } else {
                $slug = Str::slug($request->title, '-');
            }
            $data = [
                'mode' => 'vehicle',
                'pageType' => 'Booking', // for example
                'type' => 'private', // public or private
                'bookingSlug' => $slug
            ];
            $token = Crypt::encryptString(json_encode($data));
            $vehicle = new Vehicle();
            $user = Auth::user();
            $vehicle->user_id = $user->id;
            $vehicle->organization_id = $user->organization_id;
            $vehicle->title = $request->title;
            $vehicle->description = $request->description;
            $vehicle->price = $request->price;
            $vehicle->seats = $request->seats;
            $vehicle->per_km_price = $request->per_km_price;
            $vehicle->reg_num = $request->reg_num;
            $vehicle->model = $request->model;
            $vehicle->year = $request->year;
            $vehicle->color = $request->color;
            $vehicle->mileage = $request->mileage;
            $vehicle->slug = $slug;
            $vehicle->status = 'active';
            $vehicle->token = $token;
            $image_name = null;
            if ($request->has('image')) {
                $image_name = Helpers::upload('vehicles/', 'webp', $request->file('image'));
            }
            $vehicle->image = $image_name;
            $vehicle->save();
            return redirect()->route('vehicle.list', ['status' => 'active'])->with('success', 'Vehicle created successfully');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->withInput();
        } catch (Exception $exception) {
            return back()->withErrors([
                'status' => $exception->getMessage()
            ]);
        }
    }
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|string|in:active,paused',
        ]);

        $vehicle = Vehicle::findOrFail($id);

        $today = now()->toDateString();
        // if ($request->input('status') == 'paused') {

        //     $hasFutureJobs = JobVehicle::where('vehicle_id', $vehicle->id)
        //         ->whereHas('job', function ($query) use ($today) {
        //             $query->whereNull('deleted_at')
        //                 ->whereHas('jobable', function ($jobableQuery) use ($today) {
        //                     $jobableQuery->whereNull('deleted_at')->whereDate('date', '>=', $today);
        //                 });
        //         })->exists();

        //     if ($hasFutureJobs) {
        //         return back()->withErrors(['error' => 'Vehicle cannot be paused as it has scheduled jobs.']);
        //     }
        // }

        $vehicle->status = $request->input('status');
        $vehicle->save();

        return back()->with('success', 'Vehicle status updated successfully');
    }
    public function delete($id)
    {
        $vehicle = Vehicle::findOrFail($id);


        foreach ($vehicle->maintenances as $maintenance) {
            if ($maintenance->image) {
                Storage::disk('s3')->delete("maintainance/{$maintenance->image}");
            }
            $maintenance->delete();
        }

        foreach ($vehicle->jobVehicles as $jobVehicle) {
            $jobVehicle->delete();
        }

        if ($vehicle->image && $vehicle->image !== 'def.png') {
            Storage::disk('s3')->delete(ltrim($vehicle->image, '/'));
        }

        $vehicle->delete();

        return redirect()->back()->with('success', 'Vehicle and all related data (excluding jobs) deleted successfully.');
    }
}
