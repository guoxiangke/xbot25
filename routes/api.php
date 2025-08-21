<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


// hack
Route::post('/xbot/login', function (Request $request) {
    Log::warning('XBOT_LOGIN',[$request->all()]);
    //{"secret":"x x x x",
    //"mac_addr":["e4:5d:e8:cc:xx:b2","00:15:54:xx:f0:c6"],
    //"host_name":"DESKTOP-8Dxx2GA"}
    return [
        "err_code" => 0,
        "license" => config('services.xbot.license'),
        "version" => "1.0.7",
        "expired_in"=> 2499184
    ];
});
//Route::post('/xbot/heartbeat', function (Request $request) {
//    Log::warning('XBOT_HEARTBEAT',[$request->all()]);
//    return [];
//    // {"secret":"x x x x"}
//    return [
//        "err_code"=> 0,
//        "expired_in"=> 2499184
//    ];
//});
Route::post('/xbot/license/info', function (Request $request) {
    Log::warning('XBOT_LICENSE_INFO',[$request->all()]);
    return [
        "err_code"=> 0,
        "license" => config('services.xbot.license'),
    ];
});

use App\Http\Controllers\XbotController;
Route::any('/xbot/{winToken}', XbotController::class);
