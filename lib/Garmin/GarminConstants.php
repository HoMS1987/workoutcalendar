<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin;

/** Endpoints and headers of the Garmin Connect mobile app, taken from python-garminconnect 0.3.19. */
final class GarminConstants
{
    public const SSO = 'https://sso.garmin.com';
    public const CONNECT_API = 'https://connectapi.garmin.com';

    public const NATIVE_HEADERS = [
        'User-Agent' => 'GCM-Android-5.23',
        'X-Garmin-User-Agent' => 'com.garmin.android.apps.connectmobile/5.23; ; Google/sdk_gphone64_arm64/google; Android/33; Dalvik/2.1.0',
        'X-Garmin-Paired-App-Version' => '10861',
        'X-Garmin-Client-Platform' => 'Android',
        'X-App-Ver' => '10861',
        'X-Lang' => 'en',
        'X-GCExperience' => 'GC5',
        'Accept-Language' => 'en-US,en;q=0.9',
    ];

    public const DESKTOP_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
    public const IOS_USER_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148';

    private function __construct()
    {
    }
}
