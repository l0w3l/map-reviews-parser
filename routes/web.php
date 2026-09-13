<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'maps')->name('home');
Route::redirect('/dashboard', '/')->middleware('auth')->name('dashboard');
