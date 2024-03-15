<?php

namespace Hebbinkpro\WebServer\router;

use Hebbinkpro\WebServer\exception\FileNotFoundException;
use Hebbinkpro\WebServer\exception\FolderNotFoundException;
use Hebbinkpro\WebServer\exception\RouteExistsException;
use Hebbinkpro\WebServer\http\HttpHeaders;
use Hebbinkpro\WebServer\http\HttpMethod;
use Hebbinkpro\WebServer\http\message\HttpRequest;
use Hebbinkpro\WebServer\http\message\HttpResponse;
use Hebbinkpro\WebServer\http\server\HttpClient;
use Hebbinkpro\WebServer\http\status\HttpStatus;
use Hebbinkpro\WebServer\http\status\HttpStatusCodes;
use Hebbinkpro\WebServer\libs\Laravel\SerializableClosure\Exceptions\PhpVersionNotSupportedException;
use Hebbinkpro\WebServer\route\FileRoute;
use Hebbinkpro\WebServer\route\Route;
use Hebbinkpro\WebServer\route\RouterRoute;
use Hebbinkpro\WebServer\route\StaticRoute;
use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeArray;

/**
 * A Router that handles requests by calling the Route corresponding to the request path
 */
class Router extends ThreadSafe implements RouterInterface
{
    /** @var array<string, Route|array<string, Route>> */
    private ThreadSafeArray $routes;

    public function __construct()
    {
        $this->routes = new ThreadSafeArray();
    }

    /**
     * Let the correct route handle an HTTP request
     * @param HttpClient $client the client
     * @param HttpRequest $request the request from the client
     * @return void
     */
    public function handleRequest(HttpClient $client, HttpRequest $request): void
    {
        // get the route that will handle the request
        $routePath = $this->getRoutePath($request);

        // no route was found
        if ($routePath === null) {
            // send a 404 not found message
            HttpResponse::notFound($client)->end();
            return;
        }

        if ($this->routes[$routePath] instanceof Route) {
            $route = $this->routes[$routePath];
        } else {
            $route = $this->routes[$routePath][$request->getMethod()->name];
        }

        // add the route path in the request, used for path params and sub paths
        $request->appendRoutePath($routePath);

        // handle the request
        $route->handleRequest($client, $request);
    }

    public function getRoutePath(HttpRequest $req): ?string
    {
        $reqPath = $req->getSubPath();
        foreach ($this->routes as $routePath => $routes) {
            if (($routes instanceof Route && $this->matchesRoutePath($reqPath, $routePath))
                || isset($routes[$req->getMethod()->name]) && $this->matchesRoutePath($reqPath, $routePath)) {
                return $routePath;
            }
        }

        return null;
    }

    /**
     * Checks if the request path matches the given route path
     * @param string $reqPath
     * @param string $routePath
     * @return bool if the path matches
     */
    public function matchesRoutePath(string $reqPath, string $routePath): bool
    {
        // any route
        if ($routePath === "*") return true;

        // get the route path as an array
        $splitReqPath = explode("/", $reqPath);
        $splitRoutePath = explode("/", $routePath);

        // the request path is smaller than the route path, which isn't possible
        if (sizeof($splitReqPath) < sizeof($splitRoutePath)) return false;

        // loop through all sub paths of the route
        foreach ($splitReqPath as $i => $reqSubPath) {
            $routeSubPath = $splitRoutePath[$i] ?? null;
            if ($routeSubPath === null) return false;

            if ($routeSubPath === "*") return true;

            if ($reqSubPath !== $routeSubPath && !str_starts_with($routeSubPath, ":")) return false;
        }

        // the given path is valid
        return true;
    }

    /**
     * Reject a request with a given status
     * @param HttpClient $client
     * @param int|HttpStatus $status
     * @param string $body
     * @return void
     */
    public function rejectRequest(HttpClient $client, int|HttpStatus $status = HttpStatusCodes::BAD_REQUEST, string $body = ""): void
    {
        $res = new HttpResponse($client, $status);
        $res->getHeaders()->setHeader(HttpHeaders::CONNECTION, "close");

        if (strlen($body) == 0) $body = $res->getStatus()->toString();
        $res->text($body);

        $res->end();
    }

    /**
     * Add a GET route to the router
     * @param string $path
     * @param callable $action
     * @param mixed ...$params
     * @return void
     * @throws RouteExistsException
     */
    public function get(string $path, callable $action, mixed ...$params): void
    {
        $this->addRoute($path, new Route(HttpMethod::GET, $action, ...$params));
    }

    /**
     * @throws RouteExistsException
     */
    public function addRoute(string $path, Route $route): void
    {
        $path = trim($path, "/");

        if (isset($this->routes[$path])) {
            // it's a route for all methods
            if (!$this->routes[$path] instanceof ThreadSafeArray) throw new RouteExistsException($path, HttpMethod::ALL);

            // there exists already a route for this method, or if an any route is added
            if (isset($this->routes[$path][$route->getMethod()->name]) || $route->getMethod() === HttpMethod::ALL) {
                throw new RouteExistsException($path, $route->getMethod());
            }
        }

        if ($route->getMethod() === HttpMethod::ALL) $this->routes[$path] = $route;
        else {
            if (!isset($this->routes[$path])) $this->routes[$path] = new ThreadSafeArray();
            $this->routes[$path][$route->getMethod()->name] = $route;
        }
    }

    /**
     * Add a FileRoute to the router
     * @param string $path
     * @param string $file the path of the file
     * @param string|null $default default value used when the file does not exist
     * @return void
     * @throws FileNotFoundException|RouteExistsException
     */
    public function getFile(string $path, string $file, ?string $default = null): void
    {
        $this->addRoute($path, new FileRoute($file, $default));
    }

    /**
     * Add a POST route to the router
     * @param string $path
     * @param callable $action
     * @param mixed $params
     * @return void
     * @throws RouteExistsException
     */
    public function post(string $path, callable $action, mixed ...$params): void
    {
        $this->addRoute($path, new Route(HttpMethod::POST, $action, ...$params));
    }

    /**
     * Add a HEAD route to the router
     * @param string $path
     * @param callable $action
     * @param mixed $params
     * @return void
     * @throws RouteExistsException
     */
    public function head(string $path, callable $action, mixed ...$params): void
    {
        $this->addRoute($path, new Route(HttpMethod::HEAD, $action, ...$params));
    }

    /**
     * Add a PUT route to the router
     * @param string $path
     * @param callable $action
     * @param mixed $params
     * @return void
     * @throws RouteExistsException
     */
    public function put(string $path, callable $action, mixed ...$params): void
    {
        $this->addRoute($path, new Route(HttpMethod::PUT, $action, ...$params));
    }

    /**
     * Add a DELETE route to the router
     * @param string $path
     * @param callable $action
     * @param mixed $params
     * @return void
     * @throws RouteExistsException
     */
    public function delete(string $path, callable $action, mixed ...$params): void
    {
        $this->addRoute($path, new Route(HttpMethod::DELETE, $action, ...$params));
    }

    /**
     * Add a route to the router that listens to all HTTP methods.
     *
     * @param string $path
     * @param callable $action
     * @param mixed $params
     * @return void
     * @throws RouteExistsException
     */
    public function all(string $path, callable $action, mixed ...$params): void
    {
        $this->addRoute($path, new Route(HttpMethod::ALL, $action, ...$params));
    }

    /**
     * Add a RouterRoute to the router.
     * @param string $path
     * @param Router $router
     * @return void
     * @throws RouteExistsException
     */
    public function route(string $path, Router $router): void
    {
        $this->addAnyRoute($path, new RouterRoute($router));
    }

    /**
     * Add a route to the router that accepts every path staring with the given path
     * @param string $path the path that should match
     * @param Route $route the route that handles the request
     * @return void
     * @throws RouteExistsException
     */
    public function addAnyRoute(string $path, Route $route): void
    {
        // make sure the static route ends with a *
        if (!str_ends_with($path, "/*")) $path .= "/*";
        else if (!str_ends_with($path, "*")) $path .= "*";

        $this->addRoute($path, $route);
    }

    /**
     * Create a GET route for a static folder
     * @param string $path
     * @param string $folder
     * @return void
     * @throws PhpVersionNotSupportedException
     * @throws FolderNotFoundException|RouteExistsException when the given folder does not exist
     */
    public function getStatic(string $path, string $folder): void
    {
        $this->addAnyRoute($path, new StaticRoute($folder));

    }
}