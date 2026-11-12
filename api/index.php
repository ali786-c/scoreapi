<?php
// 60Score / STUMPS Central API Router & Dispatcher
// Handles CORS, pre-flight OPTIONS requests, JSON payload extraction, and route dispatching.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/helpers/response.php';

// Check if backend is installed
if (!file_exists(__DIR__ . '/config/installed.lock')) {
    Response::error('60Score Backend is not installed yet. Please visit /install.php in your web browser to run the 1-Step Setup Wizard.', 503);
}

require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/PlayerController.php';
require_once __DIR__ . '/controllers/TeamController.php';
require_once __DIR__ . '/controllers/MatchController.php';
require_once __DIR__ . '/controllers/TournamentController.php';
require_once __DIR__ . '/controllers/SyncController.php';

// Parse incoming request path
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Strip script directory or /api prefix to obtain normalized route
$scriptName = dirname($_SERVER['SCRIPT_NAME']);
$route = '/' . trim(str_replace($scriptName, '', $uri), '/');

// Extract JSON body for POST/PUT
$rawBody = file_get_contents('php://input');
$input = !empty($rawBody) ? json_decode($rawBody, true) : $_POST;
if (!is_array($input)) {
    $input = [];
}

try {
    // ----------------- AUTH ROUTES -----------------
    if ($route === '/auth/google' && $method === 'POST') {
        AuthController::googleLogin($input);
    } elseif ($route === '/auth/complete-onboarding' && $method === 'POST') {
        AuthController::completeOnboarding($input);
    } elseif ($route === '/auth/register' && $method === 'POST') {
        AuthController::register($input);
    } elseif ($route === '/auth/login' && $method === 'POST') {
        AuthController::login($input);
    } elseif ($route === '/auth/me' && $method === 'GET') {
        AuthController::me();
    } elseif ($route === '/auth/profile' && $method === 'POST') {
        AuthController::updateProfile($input);
    }

    // ----------------- PLAYER ROUTES -----------------
    elseif ($route === '/players/profile' && $method === 'GET') {
        $id = $_GET['id'] ?? $_GET['code'] ?? '';
        PlayerController::getProfile($id);
    } elseif ($route === '/players/claim' && $method === 'POST') {
        PlayerController::claimProfile($input);
    } elseif ($route === '/players/search' && $method === 'GET') {
        $q = $_GET['q'] ?? '';
        PlayerController::search($q);
    }

    // ----------------- TEAM ROUTES -----------------
    elseif ($route === '/teams/search' && $method === 'GET') {
        $q = $_GET['q'] ?? '';
        TeamController::search($q);
    } elseif ($route === '/teams' && $method === 'GET') {
        TeamController::list();
    } elseif (preg_match('#^/teams/([a-zA-Z0-9_\-]+)$#', $route, $m) && $method === 'GET') {
        TeamController::get($m[1]);
    } elseif ($route === '/teams' && $method === 'POST') {
        TeamController::create($input);
    }

    // ----------------- MATCH ROUTES -----------------
    elseif ($route === '/matches/feed' && $method === 'GET') {
        MatchController::feed();
    } elseif ($route === '/matches/live' && $method === 'GET') {
        MatchController::listLive();
    } elseif ($route === '/matches' && $method === 'GET') {
        MatchController::listAll();
    } elseif (preg_match('#^/matches/([a-zA-Z0-9_\-]+)$#', $route, $m) && $method === 'GET') {
        MatchController::getDetail($m[1]);
    } elseif ($route === '/matches' && $method === 'POST') {
        MatchController::create($input);
    } elseif (preg_match('#^/matches/([a-zA-Z0-9_\-]+)$#', $route, $m) && ($method === 'PUT' || $method === 'POST')) {
        MatchController::update($m[1], $input);
    }

    // ----------------- TOURNAMENT ROUTES -----------------
    elseif ($route === '/tournaments/public' && $method === 'GET') {
        TournamentController::listPublic();
    } elseif (preg_match('#^/tournaments/([a-zA-Z0-9_\-]+)$#', $route, $m) && $method === 'GET') {
        TournamentController::getDetail($m[1]);
    } elseif ($route === '/tournaments/publish' && $method === 'POST') {
        TournamentController::publish($input);
    }

    // ----------------- 100% OFFLINE-FIRST 2-WAY SYNC ROUTES -----------------
    elseif ($route === '/sync/batch' && $method === 'POST') {
        SyncController::batchSync($input);
    } elseif ($route === '/sync/pull' && $method === 'GET') {
        SyncController::pullSync();
    }

    // ----------------- STATUS HEALTHCHECK -----------------
    elseif ($route === '' || $route === '/' || $route === '/health') {
        Response::success([
            'service' => '60Score / STUMPS Backend API',
            'status' => 'operational',
            'version' => '1.1.0',
            'time' => date('c')
        ]);
    }

    else {
        Response::notFound("Endpoint not found: {$method} {$route}");
    }

} catch (Throwable $e) {
    Response::error('Internal Server Error: ' . $e->getMessage(), 500);
}
