<?php

use App\Http\Controllers\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::resource('invoices', InvoiceController::class)->only(['index']);

Route::get('/', function () {
    return view('welcome');
});
