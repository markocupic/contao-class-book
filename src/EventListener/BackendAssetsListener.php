<?php

declare(strict_types=1);

/*
 * This file is part of Contao Class Book.
 *
 * (c) Marko Cupic <m.cupic@gmx.ch>
 * @license MIT
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 * @link https://github.com/markocupic/contao-class-book
 */

namespace Markocupic\ContaoClassBook\EventListener;

use Contao\CoreBundle\Routing\ScopeMatcher;
use Symfony\Component\Asset\Packages;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds the style sheet with the icon of the module group to the back end.
 */
#[AsEventListener(event: KernelEvents::REQUEST)]
final class BackendAssetsListener
{
    private const PACKAGE = 'markocupic_contao_class_book';

    public function __construct(
        private readonly ScopeMatcher $scopeMatcher,
        private readonly Packages $packages,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$this->scopeMatcher->isBackendMainRequest($event)) {
            return;
        }

        // Contao expects a path without a leading slash
        $GLOBALS['TL_CSS']['class_book_backend'] = ltrim($this->packages->getUrl('css/class-book-backend.css', self::PACKAGE), '/');
    }
}
