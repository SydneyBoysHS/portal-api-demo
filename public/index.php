<?php

use GuzzleHttp\Exception\ClientException;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

// Ensure you run `composer install` to install dependencies before using this demo
require __DIR__ . '/../vendor/autoload.php';

// Ensure you create a .env file and fill in the necessary variables
Dotenv\Dotenv::createImmutable(__DIR__.'/..', ['.env.local', '.env'])->load();

$provider = new GenericProvider([
    // The client ID assigned to you by the provider
    'clientId'                => $_ENV['PORTAL_API_CLIENT_ID'],        
    // The client password assigned to you by the provider
    'clientSecret'            => $_ENV['PORTAL_API_CLIENT_SECRET'],  
    // The redirect URI for your app. You need to register this.
    // In testing it will probably just be https://localhost  
    // For production, while you can calculate it dynamically, 
    // you still have to register it
    'redirectUri'             => $_ENV['APP_REDIRECT_URI'],
    // These URLs are documented for the Student Portal API
    'urlAuthorize'            => 'https://student.sbhs.net.au/api/authorize',
    'urlAccessToken'          => 'https://student.sbhs.net.au/api/token',
    // This option is not in the OAuth standard, but this particular
    // library requires it.
    'urlResourceOwnerDetails' => '',
]);

// This URL is documented for the Student Portal API
$api_url = 'https://student.sbhs.net.au/api/';
$api_function = 'timetable/daytimetable.json';

// Extend the length of time PHP will maintain the session information about the OAuth 
// token refresh tokens are valid for 90 days, so if you store them in a persistent fashion
// you can continue to use the token without requiring the user to reauthenticate for much 
// longer than the two hours this session is configured to last for.
// Note that you shouldn't store refresh tokens in cookies directly. Encrypt them or store
// them in a key-value store (and set a persistent cookie a key you generate)
ini_set('session.gc_maxlifetime', 7200);
session_start();

// retrieve the tokens we previously obtained
$accessToken = $refreshToken = null;

// handle the "log out" capabilities of the demo
if (isset($_GET['session'])) {
    if ($_GET['session'] == 'clear') {
        unset($_SESSION['access_token']);
    }
    if ($_GET['session'] == 'clearall') {
        unset($_SESSION['access_token']);
        unset($_SESSION['refresh_token']);
    }
}
if (!empty($_SESSION['refresh_token'])) {
    $refreshToken = $_SESSION['refresh_token'];
}
if (!empty($_SESSION['access_token'])) {
    $accessToken = $_SESSION['access_token'];
}

/** 
 * An example function that handles all the authentication logic:
 *  (1) if a refresh token is available, use it
 *  (2) if no authorization `code` query parameter is available, try to
 *      get one (redirects the browser to the authorization provider)
 *  (3) a sanity check of the `state` query parameter that the
 *      authorization provider will pass through in a genuine request
 *      to obtain an authorization code
 *  (4) consume an authorization code and obtain an access token
 * 
 * @return string an access token
 **/
function authenticate(AbstractProvider $provider): string
{ 
    // (1) Try to get an access token using an existing refresh token.
    // Refresh tokens are returned together with access tokens
    if (!empty($_SESSION['refresh_token'])) {
        try {
            $tokens = $provider->getAccessToken('refresh_token', [
                'refresh_token' => $_SESSION['refresh_token']
            ]);

            echo '<pre>';
            echo 'Consumed refresh token' . "\n";
            echo 'Received access Token:    ' . $tokens->getToken() . "\n";
            echo 'Received refresh Token:   ' . $tokens->getRefreshToken() . "\n";
            echo '</pre>';
        
            $_SESSION['access_token'] = $tokens->getToken();
            $_SESSION['refresh_token'] = $tokens->getRefreshToken();
    
            return $tokens->getToken();
        }
        catch (IdentityProviderException|UnexpectedValueException $e) {
            // failed to use refresh token - fall through to full reauthentication
        }
    }

    // (2) If we don't have an authorization code then get one
    if (!isset($_GET['code'])) {

        // Fetch the authorization URL from the provider; this returns the
        // urlAuthorize option and generates and applies any necessary parameters
        // (e.g. state).
        $authorizationUrl = $provider->getAuthorizationUrl();

        // Get the state generated for you and store it to the session.
        $_SESSION['oauth2state'] = $provider->getState();

        // Redirect the user to the authorization URL.
        header('Location: ' . $authorizationUrl);
        exit;

    // (3) Check given state against previously stored one to mitigate CSRF attack
    } 
    elseif (empty($_GET['state']) || empty($_SESSION['oauth2state']) || $_GET['state'] !== $_SESSION['oauth2state']) {

        if (isset($_SESSION['oauth2state'])) {
            unset($_SESSION['oauth2state']);
        }

        exit('Invalid state');

    } 
    // (4) Try to get an access token using the authorization code grant.
    else {

        try {

            $tokens = $provider->getAccessToken('authorization_code', [
                'code' => $_GET['code']
            ]);

            // We have an access token, which we may use in authenticated
            // requests against the service provider's API.
            echo '<pre>';
            echo 'Consumed authorization code' . "\n";
            echo 'Received access Token:    ' . $tokens->getToken() . "\n";
            echo 'Received refresh Token:   ' . $tokens->getRefreshToken() . "\n";
            echo '</pre>';

            // store the token and refresh token
            $_SESSION['access_token'] = $tokens->getToken();
            $_SESSION['refresh_token'] = $tokens->getRefreshToken();

        } catch (IdentityProviderException $e) {

            // Failed to get the access token or user details.
            exit('Error: '. $e->getMessage());

        }

        return $tokens->getToken();
    }
}

/** 
 * An example function that makes an API call. This demonstrates
 * reauthenticating when an access token has expired. Note: this
 * particular library provides AbstractProvider#getParsedResponse()
 * which will cleanly handle JSON decoding. This demo does is manually
 **/
function call_api(AbstractProvider $provider, RequestInterface $request, int $depth = 0): ResponseInterface
{
    try {
        return $provider->getResponse($request);
    }
    catch (ClientException $e) {
        // If the token is expired, a 401 response code will be received
        // If so, the authentication process should be retried before erroring
        if ($e->getCode() == 401 && $depth < 2) {
            $accessToken = authenticate($provider);
            $newRequest = $provider->getAuthenticatedRequest(
                $request->getMethod(),
                $request->getUri(),
                $accessToken
            );
            return call_api($provider, $newRequest, $depth++);
        }
        else {
            throw $e;
        }
    }

}

// If no access token stored in the session, we need to get one
if (!$accessToken) {
    $accessToken = authenticate($provider);
}

// Build a request to the Portal API that is authenticated with the 
// access token. You don't have to use the HttpClient of the library
// to do this - it's just an Http request with the access token added
// as the Authorization header (Authorization: Bearer <token>)
$request = $provider->getAuthenticatedRequest(
    'GET',
    $api_url . $api_function,
    $accessToken
);

$apiResponse = call_api($provider, $request);
$apiResponseContent = $apiResponse->getBody()->getContents();

echo '<pre style="text-wrap-mode: wrap;">';
echo 'Called: ' . $api_url . $api_function . "\n\n";
echo 'Raw API Response'."\n";
echo '-----------------------------------------------------------'."\n";
echo print_r($apiResponseContent, true)."\n";
echo '-----------------------------------------------------------'."\n";
echo 'Decoded API Response'."\n";
echo var_export(json_decode($apiResponseContent, true), true)."\n";
echo '-----------------------------------------------------------'."\n";
echo "\n\n";
echo '<a href="?session=clear">Remove Access Token</a>'."\n";
echo '<a href="?session=clearall">Remove Access Token and Refresh Token (log out)</a>';
echo "\n\n\n";
echo '</pre>';


