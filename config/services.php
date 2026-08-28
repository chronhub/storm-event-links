<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

/*
 * EventLinks package wiring: the read side of the link bookkeeping.
 *
 * Only the two Dbal readers are services, autowired on the default connection because the
 * `event_links` table lives with the event store. The filters are per-query values built with
 * their target stream, and the schema classes are static DDL providers; neither is a service.
 */
return static function (ContainerConfigurator $container): void {
    $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->load('Storm\\EventLinks\\', dirname(__DIR__).'/')
        ->exclude([
            dirname(__DIR__).'/DerivedStreamFilter.php',
            dirname(__DIR__).'/DerivedStreamProjectionFilter.php',
            dirname(__DIR__).'/Schema/',
            dirname(__DIR__).'/Tests/',
            dirname(__DIR__).'/config/',
        ]);
};
