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
        '241986365674-oac3shqmfifnt54bs3nlnktkqvnd8qka.apps.googleusercontent.com',
        // Android Release OAuth Client ID
        '241986365674-orkk70eg3ms3e4hh0hpote40t84goeom.apps.googleusercontent.com',
        // Android Debug OAuth Client ID
        '241986365674-eb7nqicqhrgqv1kr8orrg3v8no188ntm.apps.googleusercontent.com',
    ],
    'debug' => true,
];
