<?php
// 60Score / STUMPS Backend Configuration

return [
    'app_name' => '60Score API',
    'app_version' => '1.1.0',
    'jwt_secret' => getenv('JWT_SECRET') ?: '60Score_Stumps_Secret_Key_Change_In_Production_2026',
    'token_expiry_days' => 365,
    'allowed_origins' => ['*'], // In production, restrict to your domain if needed
    'google_client_ids' => [
        // Web OAuth Client ID (used as serverClientId on Android & client_id on Web)
        '848526833051-enfbtj6pq70jpv707ebtb3ubskemcie0.apps.googleusercontent.com',
        // Android Release OAuth Client ID
        '848526833051-nvrnpk5r6giu0uqsev50mrptise533j1.apps.googleusercontent.com',
        // Android Debug OAuth Client ID
        '848526833051-uhh7j9vaed0g1pges3tqu4onlehcift9.apps.googleusercontent.com',
    ],
    'debug' => true,
];
