<?php

use App\Models\sms;
use GoogleMaps\Facade\GoogleMapsFacade;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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
});

Route::get('/point_to_point_directions', function () {
    request()->validate([
        'origin' => 'required|string|max:250',
        'destination' => 'required|string|max:250',
        'depart_at' => 'required|string|max:45'
    ]);
    $response = GoogleMapsFacade::load('distancematrix')
        ->setParam([
            'origins' => request()->input('origin'),
            'destinations' => request()->input('destination'),
            'arrival_time' => strtotime(request()->input('depart_at')),
            'traffic_model' => 'best_guess'
        ])
        ->get();
        return response()->json(['message' => 'google distance between', 'distance' => json_decode($response)]);
    });

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
