<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Frontend;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\Exception\InvalidDataException;
use TYPO3\CMS\Core\Configuration\ConfigurationManager;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Exception as CoreException;
use TYPO3\CMS\Core\Http\MiddlewareDispatcher;
use TYPO3\CMS\Core\Http\MiddlewareStackResolver;
use TYPO3\CMS\Core\Package\AbstractServiceProvider;
use TYPO3\CMS\Core\Routing\BackendEntryPointResolver;

/**
 * @internal
 */
class ServiceProvider extends AbstractServiceProvider
{
    protected static function getPackagePath(): string
    {
        return __DIR__ . '/../';
    }

    protected static function getPackageName(): string
    {
        return 'typo3/cms-frontend';
    }

    public function getFactories(): array
    {
        return [
            Http\Application::class => [ static::class, 'getApplication' ],
            'frontend.middlewares' => [ static::class, 'getFrontendMiddlewares' ],
        ];
    }

    public function getExtensions(): array
    {
        return [
            Http\RequestHandler::class => [ static::class, 'provideFallbackRequestHandler' ],
        ] + parent::getExtensions();
    }

    public static function getApplication(ContainerInterface $container): Http\Application
    {
        $requestHandler = new MiddlewareDispatcher(
            $container->get(Http\RequestHandler::class),
            $container->get('frontend.middlewares'),
            $container
        );
        return new Http\Application(
            $requestHandler,
            $container->get(ConfigurationManager::class),
            $container->get(Context::class),
            $container->get(BackendEntryPointResolver::class)
        );
    }

    public static function provideFallbackRequestHandler(
        ContainerInterface $container,
        RequestHandlerInterface $requestHandler = null
    ): RequestHandlerInterface {
        // Provide fallback request handler instace for the case where the system is not installed yet (that means when we run without symfony DI).
        // This request handler is intended to be never executed, as the frontend application will perform an early redirect to the install tool.
        return $requestHandler ?? new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('not implemented', 1689684150);
            }
        };
    }

    /**
     * @throws InvalidDataException
     * @throws CoreException
     */
    public static function getFrontendMiddlewares(ContainerInterface $container): \ArrayObject
    {
        return new \ArrayObject($container->get(MiddlewareStackResolver::class)->resolve('frontend'));
    }
}
