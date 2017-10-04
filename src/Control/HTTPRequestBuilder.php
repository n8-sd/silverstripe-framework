<?php

namespace SilverStripe\Control;

use SilverStripe\Core\Environment;

class HTTPRequestBuilder
{
    /**
     * Create HTTPRequest instance from the current environment variables.
     * May throw errors if request is invalid.
     *
     * @throws HTTPResponse_Exception
     * @return HTTPRequest
     */
    public static function createFromEnvironment()
    {
        // Clean and update live global variables
        $variables = static::cleanEnvironment(Environment::getVariables());

        // Health-check prior to creating environment
        $req = static::createFromVariables($variables, @file_get_contents('php://input'));

        Environment::setVariables($variables); // Currently necessary for SSViewer, etc to work

        return $req;
    }

    /**
     * Build HTTPRequest from given variables
     *
     * @param array $variables
     * @param string $input Request body
     * @return HTTPRequest
     */
    public static function createFromVariables(array $variables, $input)
    {
        $url = $variables['_SERVER']['REQUEST_URI'];

        // Build request
        $request = new HTTPRequest(
            $variables['_SERVER']['REQUEST_METHOD'],
            $url,
            $variables['_GET'],
            $variables['_POST'],
            $input
        );

        // Set the scheme to HTTPS if needed
        if ((!empty($variables['_SERVER']['HTTPS']) && $variables['_SERVER']['HTTPS'] != 'off')
            || isset($variables['_SERVER']['SSL'])) {
            $request->setScheme('https');
        }

        // Set the client IP
        if (!empty($variables['_SERVER']['REMOTE_ADDR'])) {
            $request->setIP($variables['_SERVER']['REMOTE_ADDR']);
        }

        // Add headers
        $headers = static::extractRequestHeaders($variables['_SERVER']);
        foreach ($headers as $header => $value) {
            $request->addHeader($header, $value);
        }

        // Initiate an empty session - doesn't initialize an actual PHP session (see HTTPApplication)
        $session = new Session(isset($variables['_SESSION']) ? $variables['_SESSION'] : null);
        $request->setSession($session);

        return $request;
    }

    /**
     * Takes a $_SERVER data array and extracts HTTP request headers.
     *
     * @param array $server
     *
     * @return array
     */
    public static function extractRequestHeaders(array $server)
    {
        $headers = array();
        foreach ($server as $key => $value) {
            if (substr($key, 0, 5) == 'HTTP_') {
                $key = substr($key, 5);
                $key = strtolower(str_replace('_', ' ', $key));
                $key = str_replace(' ', '-', ucwords($key));
                $headers[$key] = $value;
            }
        }

        if (isset($server['CONTENT_TYPE'])) {
            $headers['Content-Type'] = $server['CONTENT_TYPE'];
        }
        if (isset($server['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = $server['CONTENT_LENGTH'];
        }

        return $headers;
    }

    /**
     * Clean up HTTP global vars for $_GET / $_REQUEST prior to bootstrapping
     *
     * @param array $variables
     * @return array Cleaned variables
     */
    public static function cleanEnvironment(array $variables)
    {
        // IIS will sometimes generate this.
        if (!empty($variables['_SERVER']['HTTP_X_ORIGINAL_URL'])) {
            $variables['_SERVER']['REQUEST_URI'] = $variables['_SERVER']['HTTP_X_ORIGINAL_URL'];
        }

        // Override REQUEST_METHOD
        if (isset($variables['_SERVER']['X-HTTP-Method-Override'])) {
            $variables['_SERVER']['REQUEST_METHOD'] = $variables['_SERVER']['X-HTTP-Method-Override'];
        }

        // Merge $_FILES into $_POST
        $variables['_POST'] = array_merge((array)$variables['_POST'], (array)$variables['_FILES']);

        // Merge $_POST, $_GET, and $_COOKIE into $_REQUEST
        $variables['_REQUEST'] = array_merge(
            (array)$variables['_GET'],
            (array)$variables['_POST'],
            (array)$variables['_COOKIE']
        );

        // Remove query parameters (they're retained separately through $server['_GET']
        $url = parse_url($variables['_SERVER']['REQUEST_URI'], PHP_URL_PATH);

        // Remove SCRIPT_NAME from url in case no rewriting is in place on the webserver.
        // Example: index.php/admin/pages -> /admin/pages
        $url = preg_replace('#^' . preg_quote($server['SCRIPT_NAME'], '#') . '#', '', $url);

        // Remove base folders from the URL if webroot is hosted in a subfolder
        if (substr(strtolower($url), 0, strlen(BASE_URL)) === strtolower(BASE_URL)) {
            $url = substr($url, strlen(BASE_URL));
        }

        // Normalise URI
        $variables['_SERVER']['REQUEST_URI'] = $url;

        return $variables;
    }

}
