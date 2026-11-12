<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminController;

Route::get('/', function () {
    return redirect('/admin/dashboard');
});

Route::prefix('admin')->group(function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard']);
    Route::get('/employees', [AdminController::class, 'employees']);
    Route::get('/locations', [AdminController::class, 'locations']);
    Route::get('/shifts', [AdminController::class, 'shifts']);
    Route::get('/attendance', [AdminController::class, 'attendance']);
    Route::get('/corrections', [AdminController::class, 'corrections']);
    Route::post('/corrections/{uuid}/approve', [AdminController::class, 'approveCorrection']);
    Route::post('/corrections/{uuid}/reject', [AdminController::class, 'rejectCorrection']);
    Route::get('/audit', [AdminController::class, 'audit']);
});

// Stream system and realestate uploads directly to mobile app client
Route::get('/sys/uploads/{path}', function ($path) {
    $fullPath = '/Applications/XAMPP/xamppfiles/htdocs/sys/uploads/' . $path;
    if (!file_exists($fullPath)) {
        $fullPath = public_path('uploads/' . $path);
        if (!file_exists($fullPath)) {
            abort(404);
        }
    }
    $mime = mime_content_type($fullPath) ?: 'image/jpeg';
    return response()->file($fullPath, [
        'Content-Type' => $mime,
        'Cache-Control' => 'public, max-age=86400',
    ]);
})->where('path', '.*');

Route::get('/uploads/{path}', function ($path) {
    $fullPath = '/Applications/XAMPP/xamppfiles/htdocs/sys/uploads/' . $path;
    if (!file_exists($fullPath)) {
        $fullPath = public_path('uploads/' . $path);
        if (!file_exists($fullPath)) {
            abort(404);
        }
    }
    $mime = mime_content_type($fullPath) ?: 'image/jpeg';
    return response()->file($fullPath, [
        'Content-Type' => $mime,
        'Cache-Control' => 'public, max-age=86400',
    ]);
})->where('path', '.*');
