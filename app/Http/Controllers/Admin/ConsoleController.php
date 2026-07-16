<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

class ConsoleController extends Controller
{
    public function index()
    {
        return view('admin.console', [
            'user' => auth('admin')->user(),
        ]);
    }
}
