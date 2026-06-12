<?php

use Illuminate\Support\Facades\Route;
use App\Livewire\Marketplace;

Route::get('/', function () {
    return view('welcome');
});



Route::livewire('/', 'pages::marketplace');

Route::livewire('/cars/{vehicle}', 'pages::vehicle');
