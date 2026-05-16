<?php

namespace App\Modules\Vehicle\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Contracts\AuthContract;
use App\Modules\Shared\Helpers\Helpers;
use App\Modules\Shared\Models\City;
use App\Modules\Shared\Models\JobVehicle;
use App\Modules\Shared\Models\Organization;
use App\Modules\Shared\Models\Vehicle;
use App\Modules\Shared\Models\Zone;
use App\Modules\Shared\Models\ZoneAssignment;
use App\Modules\Vehicle\Services\VehicleAvailabilityService;
use App\Services\DistanceCalculator;
use App\Services\PricingEngine;
use App\Services\ZoneService;
use Inertia\Inertia;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VehicleController extends Controller
{
    protected $authService;
    protected $availabilityService;
    protected $zoneService;

    public function __construct(AuthContract $authService, VehicleAvailabilityService $availabilityService, ZoneService $zoneService)
    {
        $this->authService = $authService;
        $this->availabilityService = $availabilityService;
        $this->zoneService = $zoneService;
    }

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


    public function check(Request $request, $vehicleId)
    {
        $request->validate([
            'date'                => 'required|date',
            'time'                => 'required',
            'duration'            => 'nullable|integer|min:1',
            'timezone'            => 'nullable|string',
            'buffer_time_minutes' => 'nullable|integer|min:0|max:1440',
            'max_bookings_per_day' => 'nullable|integer|min:1|max:999',
        ]);

        $available = $this->availabilityService->isAvailable(
            $vehicleId,
            $request->date,
            $request->time,
            $request->duration ?? 1,
            [],
            (int) ($request->buffer_time_minutes ?? 0),
            $request->filled('max_bookings_per_day') ? (int) $request->max_bookings_per_day : null
        );

        return response()->json(['available' => $available]);
    }
    public function list($status)
    {
        $query = Vehicle::select(['id', 'organization_id', 'title', 'reg_num', 'mileage', 'seats', 'image', 'status', 'vehicle_type_id', 'created_at'])
            ->with(['vehicleType:id,name', 'vehicleTypes:id,name'])
            ->where('organization_id', $this->authService->getAuthenticatedUser()->organization_id);
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        $vehicles = $query->latest()->paginate(20);

        return Inertia::render('Vehicle/Index', [
            'vehicles' => $vehicles,
            'routeBase' => 'vehicle',
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
    public function search(Request $request, $slug)
    {
        $request->validate([
            'from_lat' => 'nullable|numeric',
            'from_lng' => 'nullable|numeric',
            'to_lat'   => 'nullable|numeric',
            'to_lng'   => 'nullable|numeric',
            'service_type' => 'required|string|in:airport_transfer,point_to_point,hourly',
            'date' => 'required|date',
            'time' => 'required|date_format:H:i',
            'duration_hours' => 'nullable|integer|min:1',
            'return_date' => 'nullable|date|required_with:return_time',
            'return_time' => 'nullable|date_format:H:i|required_with:return_date',
            'return_from_lat' => 'nullable|numeric|required_with:return_date',
            'return_from_lng' => 'nullable|numeric|required_with:return_date',
            'return_to_lat' => 'nullable|numeric|required_with:return_date',
            'return_to_lng' => 'nullable|numeric|required_with:return_date',
        ]);

        $organization = Organization::where('slug', $slug)->firstOrFail();

        $vehicles = Vehicle::where('organization_id', $organization->id)
            ->where('status', 'active')
            ->with('pricingTemplate')
            ->get();
        $isRoundTrip = $request->filled('return_date');

        $filtered = $vehicles->filter(function ($vehicle) use ($request, $isRoundTrip) {
            $zones = $this->zoneService->getZonesForService($vehicle);

            if ($zones->isEmpty()) {
                return false;
            }

            // Check pickup location
            // if ($request->filled('from_lat') && $request->filled('from_lng')) {
            //     $pickupPoint = [
            //         'lat' => $request->from_lat,
            //         'lng' => $request->from_lng,
            //     ];
            //     if (!$this->zoneService->validateLocationInZones($pickupPoint, $zones)) {
            //         return false;
            //     }
            // }
            if (!$this->validateLocation($request, $zones, 'from')) return false;

            // For non-hourly services, also check dropoff
            if ($request->service_type !== 'hourly' && !$this->validateLocation($request, $zones, 'to')) return false;
            if ($isRoundTrip) {
                if (!$this->validateLocation($request, $zones, 'return_from')) return false;
                if (!$this->validateLocation($request, $zones, 'return_to')) return false;
            }

            $outwardAvailable = $this->availabilityService->isAvailable(
                $vehicle->id,
                $request->date,
                $request->time,
                $request->duration_hours ?? 1
            );
            if (!$outwardAvailable) return false;

            // Availability check for return leg (if round trip)
            if ($isRoundTrip) {
                $returnAvailable = $this->availabilityService->isAvailable(
                    $vehicle->id,
                    $request->return_date,
                    $request->return_time,
                    $request->duration_hours ?? 1  // assume same duration for return
                );
                if (!$returnAvailable) return false;
            }
            return true;
        });
        $distanceCalculator = app(DistanceCalculator::class);
        $distance = null;
        if ($request->service_type === 'point_to_point' && $request->filled('from_lat') && $request->filled('to_lat')) {
            $distance = $distanceCalculator->getDistance(
                $request->from_lat,
                $request->from_lng,
                $request->to_lat,
                $request->to_lng,
                'imperial' // or based on org preference
            );
        }

        $pricingEngine = app(PricingEngine::class);

        $result = $filtered->map(function ($v) use ($request, $distance, $pricingEngine) {
            $hours      = (float) ($request->duration_hours ?? 1);
            $totalPrice = 0;
            $priceLabel = '';

            if ($request->service_type === 'hourly') {
                $est        = $pricingEngine->estimateForVehicle($v, $hours);
                $totalPrice = $est['estimated_price'];
                $priceLabel = '/total';
            } elseif ($request->service_type === 'point_to_point' && $distance) {
                $totalPrice = $v->pricingTemplate
                    ? $pricingEngine->calculate($v->pricingTemplate, ['distance' => $distance])
                    : 0;
                $priceLabel = '/total';
            } else {
                // airport transfer
                $est        = $pricingEngine->estimateForVehicle($v, $hours);
                $totalPrice = $est['minimum_charge'] > 0 ? $est['minimum_charge'] : $est['estimated_price'];
                $priceLabel = '/trip';
            }

            $vehicleEst = $pricingEngine->estimateForVehicle($v, 1);

            return [
                'id'               => $v->id,
                'title'            => $v->title,
                'image'            => $v->image,
                'seats'            => $v->seats,
                'total_price'      => round($totalPrice, 2),
                'price_label'      => $priceLabel,
                'luggage_capacity' => $v->luggage_capacity,
                'series'           => $v->series ?? $v->model,
                'hourly_rate'      => $vehicleEst['hourly_rate'],
            ];
        });

        return response()->json(['vehicles' => $result]);
    }
    private function validateLocation($request, $zones, $prefix)
    {
        $lat = $request->input($prefix . '_lat');
        $lng = $request->input($prefix . '_lng');
        if ($lat && $lng) {
            return $this->zoneService->validateLocationInZones(['lat' => $lat, 'lng' => $lng], $zones);
        }
        return true; // no coordinates means no validation
    }
    public function index()
    {
        if (!$this->authService->getAuthenticatedUser()) {
            if (Auth::guard('admin')->check()) {
                $vehicles = Vehicle::select(['id', 'organization_id', 'title', 'reg_num', 'mileage', 'seats', 'image', 'status', 'created_at'])->latest()->paginate(20);

                return Inertia::render('Vehicle/Index', [
                    'vehicles' => $vehicles,
                    'routeBase' => 'vehicle',
                ]);
            }
            abort(403, 'No Permission');
        } else {
            $vehicles = Vehicle::select(['id', 'organization_id', 'title', 'reg_num', 'mileage', 'seats', 'image', 'status', 'created_at'])
                ->where('organization_id', $this->authService->getAuthenticatedUser()->organization_id)
                ->latest()->paginate(20);

            return Inertia::render('Vehicle/Index', [
                'vehicles' => $vehicles,
                'routeBase' => 'vehicle',
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
    public function add(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $cities       = City::orderBy('name')->get();
        $zones        = Zone::where('organization_id', $orgId)->orderBy('name')->get();
        $templates    = \App\Modules\Booking\Models\PricingTemplate::where('organization_id', $orgId)->orderBy('name')->get(['id', 'name']);
        $vehicleTypes = \App\Modules\Booking\Models\VehicleType::whereNull('organization_id')->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']);

        return Inertia::render('Vehicle/Create', [
            'cities'       => $cities,
            'zones'        => $zones,
            'templates'    => $templates,
            'vehicleTypes' => $vehicleTypes,
        ]);
    }
    public function edit($id)
    {
        $vehicle = Vehicle::with(['zoneAssignments', 'pricingTemplate', 'vehicleTypes:id'])->findOrFail($id);
        abort_unless($vehicle->organization_id === $this->authService->getAuthenticatedUser()->organization_id, 403);

        $orgId        = $vehicle->organization_id;
        $cities       = City::orderBy('name')->get();
        $zones        = Zone::where('organization_id', $orgId)->orderBy('name')->get();
        $templates    = \App\Modules\Booking\Models\PricingTemplate::where('organization_id', $orgId)->orderBy('name')->get(['id', 'name']);
        $vehicleTypes = \App\Modules\Booking\Models\VehicleType::whereNull('organization_id')->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']);

        return Inertia::render('Vehicle/Edit', [
            'vehicle'      => $vehicle,
            'cities'       => $cities,
            'zones'        => $zones,
            'templates'    => $templates,
            'vehicleTypes' => $vehicleTypes,
            'routeBase'    => 'vehicle',
            'redirectUrl'  => route('vehicle.index'),
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
                'seats' => 'required|integer|min:2', // Ensures seats is at least 2
                // 'image' => 'required',
                'mileage' => 'required|numeric|min:0',
                'color' => 'required',
                'model' => 'required',
                'year' => 'required|integer|min:1900',
                'city_id'              => 'required|exists:cities,id',
                'zone_ids'             => 'nullable|array',
                'zone_ids.*'           => 'exists:zones,id',
                'pricing_template_id'  => 'nullable|integer',
                'vehicle_type_ids'     => 'nullable|array',
                'vehicle_type_ids.*'   => ['integer', Rule::exists(\App\Modules\Booking\Models\VehicleType::class, 'id')],
            ], [
                'title.required' => 'Title is required',
                'description.required' => 'Description is required',
                'reg_num.required' => 'Registration number is required',
                'reg_num.unique' => 'Registration number is already taken',
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
            abort_unless($vehicle->organization_id === $this->authService->getAuthenticatedUser()->organization_id, 403);
            $vehicle->title = $request->title;
            $vehicle->description = $request->description;
            $vehicle->seats = (int) $request->seats;
            $vehicle->reg_num = $request->reg_num;
            $vehicle->slug = $slug;
            $vehicle->model = $request->model;
            $vehicle->year = (int) $request->year;
            $vehicle->color = $request->color;
            $vehicle->mileage = $this->nullableNumeric($request->mileage);
            $vehicle->city_id = $request->city_id;
            $vehicle->pricing_template_id = $request->input('pricing_template_id') ?? null;

            $typeIds = collect($request->input('vehicle_type_ids', []))
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();
            $vehicle->vehicle_type_id = $typeIds->first();

            if ($request->has('image') && $request->image != '') {
                $image_name = null;
                $image_name = Helpers::upload('vehicles/', 'webp', $request->file('image'));
                $vehicle->image = $image_name;
            }
            $vehicle->save();
            $vehicle->vehicleTypes()->sync($typeIds->all());
            $vehicle->zoneAssignments()->delete();
            if ($request->filled('zone_ids')) {
                $zoneAssignments = [];
                foreach ($request->zone_ids as $zoneId) {
                    $zoneAssignments[] = new ZoneAssignment(['zone_id' => $zoneId]);
                }
                $vehicle->zoneAssignments()->saveMany($zoneAssignments);
            }
            return redirect()->back()->with('success', 'Vehicle updated successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->withInput();
        } catch (Exception $exception) {
            return back()->withErrors(['status' => 'Failed to update vehicle. Please try again.']);
        }
    }
    public function create(Request $request)
    {

        try {
            $request->validate([
                'title' => 'required',
                'description' => 'required',
                'reg_num' => 'required|unique:' . Vehicle::class,
                'seats' => 'required|integer|min:2',
                'image' => 'required',
                'mileage' => 'required|numeric|min:0',
                'color' => 'required',
                'model' => 'required',
                'year' => 'required|integer|min:1900',
                'city_id'             => 'required|exists:cities,id',
                'zone_ids'            => 'nullable|array',
                'zone_ids.*'          => 'exists:zones,id',
                'pricing_template_id' => 'nullable|integer',
                'vehicle_type_ids'    => 'nullable|array',
                'vehicle_type_ids.*'  => ['integer', Rule::exists(\App\Modules\Booking\Models\VehicleType::class, 'id')],
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
            $vehicle->seats = (int) $request->seats;
            $vehicle->reg_num = $request->reg_num;
            $vehicle->model = $request->model;
            $vehicle->year = (int) $request->year;
            $vehicle->color = $request->color;
            $vehicle->mileage = $this->nullableNumeric($request->mileage);
            $vehicle->slug = $slug;
            $vehicle->status = 'active';
            $vehicle->token = $token;
            $vehicle->city_id = $request->city_id;
            $vehicle->pricing_template_id = $request->input('pricing_template_id') ?? null;

            $typeIds = collect($request->input('vehicle_type_ids', []))
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();
            $vehicle->vehicle_type_id = $typeIds->first();

            $image_name = null;
            if ($request->has('image')) {
                $image_name = Helpers::upload('vehicles/', 'webp', $request->file('image'));
            }
            $vehicle->image = $image_name;
            $vehicle->save();
            $vehicle->vehicleTypes()->sync($typeIds->all());
            if ($request->filled('zone_ids')) {
                $zoneAssignments = [];
                foreach ($request->zone_ids as $zoneId) {
                    $zoneAssignments[] = new ZoneAssignment(['zone_id' => $zoneId]);
                }
                $vehicle->zoneAssignments()->saveMany($zoneAssignments);
            }
            return redirect()->route('vehicle.list', ['status' => 'active'])->with('success', 'Vehicle created successfully');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->withInput();
        } catch (Exception $exception) {
            return back()->withErrors([
                'status' => 'Failed to create vehicle. Please check your input and try again.'
            ]);
        }
    }
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|string|in:active,paused',
        ]);

        $vehicle = Vehicle::findOrFail($id);
        abort_unless($vehicle->organization_id === $this->authService->getAuthenticatedUser()->organization_id, 403);

        if ($request->input('status') === 'paused') {
            $activeTours = \App\Modules\Booking\Models\PrivateTour::where('vehicle_id', $vehicle->id)
                ->whereHas('service', fn ($q) => $q->where('status', 'published'))
                ->with('service:id,serviceable_id,serviceable_type,title')
                ->get();

            $activeLimos = \App\Modules\Booking\Models\LimousineService::where('vehicle_id', $vehicle->id)
                ->whereHas('service', fn ($q) => $q->where('status', 'published'))
                ->with('service:id,serviceable_id,serviceable_type,title')
                ->get();

            $conflicting = $activeTours->merge($activeLimos);

            if ($conflicting->isNotEmpty()) {
                $names = $conflicting->map(fn ($s) => optional($s->service)->title ?? 'Untitled')->implode(', ');
                return back()->withErrors([
                    'status' => "Cannot pause this vehicle — it is assigned to active service(s): {$names}. Please reassign or deactivate those services first.",
                ]);
            }
        }

        $vehicle->status = $request->input('status');
        $vehicle->save();

        return back()->with('success', 'Vehicle status updated successfully');
    }
    public function delete($id)
    {
        $vehicle = Vehicle::findOrFail($id);

        $orgId = $this->authService->getAuthenticatedUser()->organization_id;
        if ($vehicle->organization_id !== $orgId) {
            abort(403, 'You do not have permission to delete this vehicle.');
        }


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
