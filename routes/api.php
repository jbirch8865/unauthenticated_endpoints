<?php

use App\Models\equipment;
use App\Models\shift;
use App\Models\sms;
use App\Models\trip;
use App\Models\trip_data;
use GoogleMaps\Facade\GoogleMapsFacade;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::post('/twilio_status_callback', function () {
    $status = ['accepted' => 0, 'queued' => 1, 'sending' => 2, 'sent' => 3, 'receiving' => 4, 'received' => 5, 'delivered' => 6, 'undelivered' => 7, 'failed' => 8];
    request()->validate([
        'SmsSid' => 'required|string|max:40',
        'SmsStatus' => 'required|string|max:16|in:' . implode(",", array_keys($status))
    ]);
    $sms = sms::findOrFail(request()->input('SmsSid'));
    if ($sms->message_received < $status[request()->input('SmsStatus')] || $sms->message_received === 9) {
        $sms->message_received = $status[request()->input('SmsStatus')];
        $sms->save();
    }
    return response()->json(['message' => 'updated']);
})->middleware('throttle:200');
Route::get('/point_to_point_distance', function () {
    request()->validate([
        'origin' => 'required|string|max:250',
        'destination' => 'required|string|max:250',
        'depart_at' => 'required|date:Y-m-d H:i'
    ]);

    $depart_in_seconds = new DateTime(request()->input('depart_at'));
    $depart_in_seconds = strtotime($depart_in_seconds->format('Y-m-d H:i'));
    $now_in_seconds = strtotime(now());
    $difference_in_weeks = $now_in_seconds > $depart_in_seconds ? abs($now_in_seconds - $depart_in_seconds) / 604800 : 0;
    $adjust_weeks_by = ceil($difference_in_weeks);
    $response = GoogleMapsFacade::load('distancematrix')
        ->setParam([
            'origins' => request()->input('origin'),
            'destinations' => request()->input('destination'),
            'departure_time' => strtotime(date('Y-m-d H:i', strtotime("+" . $adjust_weeks_by . " week", strtotime(request()->input('depart_at')))) . ' America/Los_Angeles'),
            'traffic_model' => 'best_guess'
        ])
        ->get();
    return response()->json(['message' => 'google distance between', 'distance' => json_decode($response)]);
})->middleware('throttle:200');

Route::get('/google_address_autofill', function () {
    request()->validate([
        'address' => 'required|string|max:100',
        'sessiontoken' => 'required|string|max:23'
    ]);
    $response = GoogleMapsFacade::load('placeautocomplete')
        ->setParam(['input' => request()->input('address'), 'sessiontoken' => request()->input('sessiontoken')])
        ->get();
    return response()->json(['message' => 'google autocomplete', 'addresses' => json_decode($response)]);
});
Route::get('/google_address_details', function () {
    request()->validate([
        'placeid' => 'required|string|max:150',
    ]);
    $response = GoogleMapsFacade::load('placedetails')
        ->setParam(['placeid' => request()->input('placeid')])
        ->get();
    return response()->json(['message' => 'google place details', 'details' => json_decode($response)]);
});
Route::post('vehicleTripStart', function () {
    if (request()->header('Authorization', false) !== '21687a50-d139-4d7e-ad31-2c3c38fb9418') {
        return response()->json(['message' => 'malformed request'], 401);
    }
    request()->validate([
        'vin' => 'required|string|max:17',
        'transactionId' => 'required|string|max:32',
        'start.timestamp' => 'required|date'
    ]);
    $shift = getClosestShift(date('Y-m-d H:i', strtotime('+5 hours', strtotime(request()->input('start.timestamp')))));
    $trip = new trip;
    $trip->vin = request()->input('vin');
    $trip->bouncie_trip_id = request()->input('transactionId');
    $trip->start = date('Y-m-d H:i', strtotime(request()->input('start.timestamp')));
    $trip->save();
    return response()->json(['message' => 'Started Trip', 'trip' => $trip, 'matchingShift' => $shift],201);
});

Route::post('vehicleTripEnd', function () {
    if (request()->header('Authorization', false) !== '21687a50-d139-4d7e-ad31-2c3c38fb9418') {
        return response()->json(['message' => 'malformed request'], 401);
    }
    request()->validate([
        'vin' => 'required|string|max:17',
        'transactionId' => 'required|string|max:32',
        'end.timestamp' => 'required|date',
        'end.odometer' => 'integer|min:0|max:999999',
        'end.fuelConsumed' => 'numeric|min:0|max:999999'
    ]);
    $trip = new trip;
    $trip->vin = request()->input('vin');
    $trip->bouncie_trip_id = request()->input('transactionId');
    $trip->end = date('Y-m-d H:i', strtotime(request()->input('end.timestamp')));
    $trip->fuel_consumed = request()->input('end.fuelConsumed',0);
    $trip->odometer_at_end = request()->input('end.odometer',0);
    $trip->save();
    return response()->json(['message' => 'End Trip', 'trip' => $trip],201);
});

Route::post('vehicleTripData', function () {
    if (request()->header('Authorization', false) !== '21687a50-d139-4d7e-ad31-2c3c38fb9418') {
        return response()->json(['message' => 'malformed request'], 401);
    }
    request()->validate([
        'vin' => 'required|string|max:17',
        'transactionId' => 'required|string|max:32',
        'data' => 'required|array',
        'data.*.timestamp' => 'required|date',
        'data.*.gps.lat' => 'numeric',
        'data.*.gps.lon' => 'numeric',
        'data.*.speed' => 'integer|min:0|max:180',
        'data.*.gps.heading' => 'integer|min:0|max:365',
        'data.*.fuelLevelInput' => 'numeric|min:0|max:100',
        'data.*.gps.obdMaxSpeed' => 'numeric|min:0|max:150',
        'data.*.gps.obdAverageSpeed' => 'numeric|min:0|max:150',
    ]);
    $return_data = [];
    foreach (request()->input('data') as $data) {
        $data = json_decode(json_encode($data));
        $trip_data = new trip_data;
        $trip_data->bouncie_trip_id = request()->input('transactionId');
        $trip_data->lat = property_exists($data, 'gps') ? (property_exists($data->gps, 'lat') ? $data->gps->lat : "") : "";
        $trip_data->lon = property_exists($data, 'gps') ? (property_exists($data->gps, 'lon') ? $data->gps->lon : "") : "";
        $trip_data->curr_speed = property_exists($data, 'speed') ? $data->speed : null;
        $trip_data->heading = property_exists($data, 'gps') ? (property_exists($data->gps, 'heading') ? $data->gps->heading : 0) : 0;
        $trip_data->fuelLevelInput = property_exists($data, 'fuelLevelInput') ? $data->fuelLevelInput : 0;
        $trip_data->obdMaxSpeed = property_exists($data, 'gps') ? (property_exists($data->gps, 'obdMaxSpeed') ? $data->gps->obdMaxSpeed : 0) : 0;
        $trip_data->obdAverageSpeed = property_exists($data, 'gps') ? (property_exists($data->gps, 'obdAverageSpeed') ? $data->gps->obdAverageSpeed : 0) : 0;
        $trip_data->save();
        $return_data[] = $trip_data;
    }
    return response()->json(['message' => 'Trip Data', 'trip_data' => $return_data],201);
});

Route::post('vehicleTripMetrics', function () {
    if (request()->header('Authorization', false) !== '21687a50-d139-4d7e-ad31-2c3c38fb9418') {
        return response()->json(['message' => 'malformed request'], 401);
    }
    request()->validate([
        'vin' => 'required|string|max:17',
        'transactionId' => 'required|string|max:32',
        'metrics.timestamp' => 'required|date',
        'metrics.tripTime' => 'integer|min:0|max:999',
        'metrics.tripDistance' => 'numeric|min:0|max:999',
        'metrics.totalIdlingTime' => 'integer|min:0|max:999',
        'metrics.maxSpeed' => 'integer|min:0|max:365',
        'metrics.averageDriveSpeed' => 'numeric|min:0|max:365',
        'metrics.hardBrakingCounts' => 'integer|min:0|max:9999',
        'metrics.hardAccelerationCounts' => 'integer|min:0|max:9999',
    ]);
    $trip = new trip;
    $trip->created_at = request()->input('metrics.timestamp');
    $trip->vin = request()->input('vin');
    $trip->bouncie_trip_id = request()->input('transactionId');
    $trip->tripTime = request()->input('metrics.tripTime',0);
    $trip->tripDistance = request()->input('metrics.tripDistance',0);
    $trip->totalIdlingTime = request()->input('metrics.totalIdlingTime',0);
    $trip->maxSpeed = request()->input('metrics.maxSpeed',0);
    $trip->averageDriveSpeed = request()->input('metrics.averageDriveSpeed',0);
    $trip->hardBrakingCounts = request()->input('metrics.hardBrakingCounts',0);
    $trip->hardAccelerationCounts = request()->input('metrics.hardAccelerationCounts',0);
    $trip->save();
    return response()->json(['message' => 'Trip Metrics', 'trip' => $trip],201);
});

Route::post('uploadPublicImage', function () {
    request()->validate(['file' => 'image|required']);
    $filename = uniqid('public_upload_');
    Storage::disk('s3')->put($filename, file_get_contents(request()->file('file')));
    return response()->json(['message' => '', 'public_url' => 'https://dhpublicicons.s3-us-west-2.amazonaws.com/' . $filename],201);
});

function getClosestShift(string $time): ?object
{
    $shift = shift::with('equipment_needs.equipment')->with('address')->whereHas('equipment_needs', function ($query) {
        $query->whereHas('equipment', function ($query) {
            $query->where('third_party_unique_id', request()->input('vin'));
        });
    })->where('Active_Status', 1)->whereRaw(DB::raw('CONCAT(`Shift`.`date`," ",`Shift`.`go_time`) >= "' . $time . '"'))->orderByRaw('CONCAT(`Shift`.`date`," ",`Shift`.`go_time`)', 'ASC')->first();
    return $shift;
}

function distance($lat1, $lon1, $lat2, $lon2, $unit = "M")
{
    if ($lat1 === "" || $lat2 === "" || $lon1 === "" || $lon2 === "") {
        $lat1 = 0;
        $lat2 = 0;
        $lon1 = 0;
        $lon2 = 0;
    }
    if (($lat1 == $lat2) && ($lon1 == $lon2)) {
        return 0;
    } else {
        $theta = $lon1 - $lon2;
        $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
        $dist = acos($dist);
        $dist = rad2deg($dist);
        $miles = $dist * 60 * 1.1515;
        $unit = strtoupper($unit);

        if ($unit == "K") {
            return ($miles * 1.609344);
        } else if ($unit == "N") {
            return ($miles * 0.8684);
        } else {
            return $miles;
        }
    }
}
