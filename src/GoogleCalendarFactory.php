<?php

namespace Spatie\GoogleCalendar;

use Google_Client;
use Google_Service_Calendar;
use Spatie\GoogleCalendar\Exceptions\InvalidConfiguration;
use App\Models\User;

class GoogleCalendarFactory
{
    public static function createForCalendarId(string $calendarId, ?User $user = null): GoogleCalendar
    {
        $config = config('google-calendar');

        $client = self::createAuthenticatedGoogleClient($config, $user);

        $service = new Google_Service_Calendar($client);

        return self::createCalendarClient($service, $calendarId);
    }

    public static function createAuthenticatedGoogleClient(array $config, ?User $user = null): Google_Client
    {
        $authProfile = $config['default_auth_profile'];

        if ($authProfile === 'service_account') {
            return self::createServiceAccountClient($config['auth_profiles']['service_account']);
        }
        if ($authProfile === 'oauth' && $user) {
            return self::createOAuthClient($config['auth_profiles']['oauth'], $user);
        }

        throw InvalidConfiguration::invalidAuthenticationProfile($authProfile);
    }

    protected static function createServiceAccountClient(array $authProfile): Google_Client
    {
        $client = new Google_Client;

        $client->setScopes([
            Google_Service_Calendar::CALENDAR,
        ]);

        $client->setAuthConfig($authProfile['credentials_json']);

        if (config('google-calendar')['user_to_impersonate']) {
            $client->setSubject(config('google-calendar')['user_to_impersonate']);
        }

        return $client;
    }

    protected static function createOAuthClient(array $authProfile, User $user): Google_Client
    {
        $client = new Google_Client;

        $client->setScopes([
            Google_Service_Calendar::CALENDAR,
        ]);

        $client->setAuthConfig($authProfile['credentials_json']);

        $token = [
            'access_token' => $user->google_access_token,
            'refresh_token' => $user->google_refresh_token,
            'created' => $user->google_token_created_at,
            'expires_in' => $user->google_token_expires_in,
        ];

        if (isset($user->google_access_token)) {
            $client->setAccessToken($token);
        }

        if (!isset($user->google_access_token) || $client->isAccessTokenExpired()) {
            $refreshed = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken() ?? $user->google_refresh_token);

            $user->google_access_token = $refreshed['access_token'];
            $user->google_refresh_token = $refreshed['refresh_token'];
            $user->google_token_created_at = $refreshed['created'];
            $user->google_token_expires_in = $refreshed['expires_in'];
            $user->save();

            $token['access_token'] = $refreshed['access_token'];
            $token['refresh_token'] = $refreshed['refresh_token'];
            $token['created'] = $refreshed['created'];
            $token['expires_in'] = $refreshed['expires_in'];
        }
        
        $client->setAccessToken($token);

        return $client;
    }

    protected static function createCalendarClient(Google_Service_Calendar $service, string $calendarId): GoogleCalendar
    {
        return new GoogleCalendar($service, $calendarId);
    }
}
