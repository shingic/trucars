<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::marketplace');

Route::livewire('/cars/{vehicle}', 'pages::vehicle');

Route::livewire('/cars/{vehicle}/reserve', 'pages::checkout')
    ->name('checkout');

Route::livewire('/dealer/login', 'pages::login')
    ->middleware('guest')
    ->name('dealer.login');

Route::post('/dealer/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect('/dealer/login');
})->middleware('auth')->name('dealer.logout');

Route::livewire('/dealer/reservations', 'pages::reservations')
    ->middleware('auth')
    ->name('dealer.reservations');

Route::livewire('/dealer/reservations/{lead}', 'pages::lead-detail')
    ->middleware('auth')
    ->name('dealer.lead');
