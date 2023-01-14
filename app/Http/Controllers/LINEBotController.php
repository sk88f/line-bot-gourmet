<?php

namespace App\Http\Controllers;

use App\Services\LINEBotService;
use Illuminate\Http\Request;

class LINEBotController extends Controller
{
    public LINEBotService $service;
    public function __construct() {
        $this->service = new LINEBotService();
    }

    public function reply(Request $request)
    {
        $status_code = $this->service->eventHandler($request);

        return response('', $status_code, []);
    }
}
