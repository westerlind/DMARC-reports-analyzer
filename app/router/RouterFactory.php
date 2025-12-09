<?php

namespace App;

use Nette;
use Nette\Application\Routers\Route;
use Nette\Application\Routers\RouteList;
use Nette\Routing\Router;


class RouterFactory
{
    use Nette\StaticClass;

    public static function createRouter(): Router
    {
        $router = new RouteList;
        //$router[] = new Route('<presenter>/<action>[/<id>]', 'Homepage:default');
        $router[] = new Route('[overview]', 'Homepage:overview');
        $router[] = new Route('details', 'Homepage:details');
        $router[] = new Route('reports', 'Homepage:reports');
        $router[] = new Route('login', 'Homepage:login');
        $router[] = new Route('xml', 'Homepage:xml');
        return $router;
    }
}
