<?php
// 60Score / STUMPS Backend Configuration

return [
    'app_name' => '60Score API',
    'app_version' => '1.0.0',
    'jwt_secret' => getenv('JWT_SECRET') ?: '60Score_Stumps_Secret_Key_Change_In_Production_2026',
    'token_expiry_days' => 365,
    'allowed_origins' => ['*'], // In production, restrict to your domain if needed
    'debug' => true,
];
