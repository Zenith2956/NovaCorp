<?php

namespace App\Http\Controllers;

use App\Models\ConnexionLog;

class ConnexionLogController extends Controller
{
    public function index()
    {
        return view('logs.index', [
            'logs' => ConnexionLog::with('user')->latest('created_at')->paginate(30),
        ]);
    }
}
