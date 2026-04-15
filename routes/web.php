<?php

use App\Http\Controllers\Web\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');

Route::get('/', function () {
    return view('welcome');
});
