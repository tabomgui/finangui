<?php

use App\Http\Controllers\Auth\GoogleController;
use Illuminate\Support\Facades\Route;

// OAuth do Google: grupo `web` porque precisa de sessão (o callback vem do Google,
// fora do fluxo stateful do Sanctum). O frontend chama estas URLs pelo mesmo origin.
Route::get('/api/auth/google/redirect', [GoogleController::class, 'redirect']);
Route::get('/api/auth/google/callback', [GoogleController::class, 'callback']);
