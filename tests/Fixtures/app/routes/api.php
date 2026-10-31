<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::post('/orders', 'OrderController@store')->middleware('api');
