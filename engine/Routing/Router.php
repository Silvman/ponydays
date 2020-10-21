<?php

namespace Engine\Routing;

use Engine\CallResolver;
use Engine\Config;
use Engine\Engine;
use Engine\Routing\Parser\RouteLexer;
use Engine\Routing\Parser\RouteParser;
use Engine\Routing\Parser\RouteWalker;
use FastRoute;
use FastRoute\RouteCollector;
use Throwable;

class Router
{
    private static $instance = null;
    public static function getInstance(): Router
    {
        return self::$instance ?: self::$instance = new Router();
    }

    /**
     * @var \FastRoute\Dispatcher
     */
    private $dispatcher = null;
    /**
     * @var array
     */
    private $controllers = [];

    public function init()
    {
        $this->dispatcher = FastRoute\cachedDispatcher(function (RouteCollector $r) {
            $lexer = new RouteLexer();
            $parser = new RouteParser();
            $walker = new RouteWalker();

            foreach (Config::Get('router.registries') as $reg) {
                try {
                    $routeSource = file_get_contents($reg);
                    $lexer->init($routeSource, $reg);
                    $parser->init($lexer);
                    $result = $parser->parse();
                    $walker->walkList($result, $r);
                } catch (Throwable $t) {
                    file_put_contents(Config::Get('router.logFile'), $t, FILE_APPEND);
                }
            }
        }, ['cacheFile' => Config::Get('router.cacheFile')]);
    }

    public function route()
    {
        $method = RequestUtils::getHTTPMethod();
        $uri = RequestUtils::getUri();
        $routeInfo = $this->dispatcher->dispatch($method, $uri);
        switch($routeInfo[0]) {
            case FastRoute\Dispatcher::NOT_FOUND:
                $this->handleNotFound();
                break;
            case FastRoute\Dispatcher::FOUND:
                $handler = $routeInfo[1];
                $vars = $routeInfo[2];
                $this->handleFound($handler, $vars);
                break;
            case FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
                $allowedMethods = $routeInfo[1];
                $this->handleMethodNotAllowed($allowedMethods);
                break;
        }

        foreach ($this->controllers as $controller) {
            $controller->Shutdown();
        }
    }

    private function provideController(string $controllerName)
    {
        $class = Config::Get("router.page.$controllerName");
        if ($class == null) $class = $controllerName;

        if (! class_exists($class)) {
            throw new RoutingException("Could not find a controller: `$controllerName`");
        }

        if (isset($this->controllers[$class])) {
            return $this->controllers[$class];
        } else {
            $controller = new $class(Engine::getInstance(), $controllerName);
            $controller->Init();
            return $controller;
        }
    }

    /*
     * $params = [
     *      'to' => 'controller#method',
     *      'after' => 'middleware',
     *      'before' => 'middleware',
     * ]
     */
    public function runAction(array $params, array $vars = [])
    {
        if (! isset($params['to'])) return;
        $to = $params['to'];

        $split = explode('#', $to);
        if (sizeof($split) != 2) {
            throw new RoutingException("Wrong handler syntax: `$to`");
        }

        $controller = $this->provideController($split[0]);
        $method = $split[1];

        $call = [$controller, $method];

        \Engine\Router::SetAction($controller);

        CallResolver::resolve($call)->with(function (string $type, string $name) use ($vars) {
            if (isset($vars[$name]) && gettype($vars[$name]) == $type) {
                return [$vars[$name], true];
            }
            if ($name == '_vars' && $type == 'array') {
                return $vars;
            }
            return [null, false];
        })->with([Engine::getInstance(), 'resolve'])->call();
    }

    private function handleNotFound() {
        $action = Config::Get('router.config.action_not_found');
        $this->runAction($action['params'], $action['vars']);
    }

    private function handleFound(array $handler, array $vars) {
        if (RequestUtils::getHTTPMethod() == 'OPTIONS' && isset($handler['options'])) {
            http_response_code(204);
            header('Allow: ' . implode(', ', $handler['options']));
            return;
        }
        if (! isset($handler['params'])) return;
        $this->runAction($handler['params'], $vars);
    }

    private function handleMethodNotAllowed(array $allowedMethods) {
        echo 'method not allowed: ' . var_export($allowedMethods, true);
    }
}