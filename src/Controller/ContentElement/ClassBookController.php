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

namespace Markocupic\ContaoClassBook\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\Controller\ContentElement\AbstractContentElementController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsContentElement;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\FrontendUser;
use Contao\StringUtil;
use Contao\System;
use Markocupic\ContaoClassBook\Controller\Api\EntryController;
use Markocupic\ContaoClassBook\Controller\Api\MasterDataController;
use Markocupic\ContaoClassBook\Controller\Api\WeekController;
use Markocupic\ContaoClassBook\Security\AccessTokenManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The class book app as a content element. It is only available to logged in members.
 */
#[AsContentElement(self::TYPE, category: 'miscellaneous', template: 'content_element/class_book')]
class ClassBookController extends AbstractContentElementController
{
    public const TYPE = 'class_book';

    public function __construct(private readonly AccessTokenManager $tokenManager)
    {
    }

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        $user = $this->getUser();

        if (!$user instanceof FrontendUser) {
            $template->set('logged_in', false);

            return $template->getResponse();
        }

        $this->initializeContaoFramework();
        System::loadLanguageFile('default');

        $template->set('logged_in', true);

        $template->set('app', [
            'token' => $this->tokenManager->create((int) $user->id, (int) $model->id),
            'urls' => [
                'week' => $this->generateUrl(WeekController::ROUTE),
                'masterDataList' => $this->generateUrl(MasterDataController::ROUTE, ['action' => 'list']),
                'masterDataSave' => $this->generateUrl(MasterDataController::ROUTE, ['action' => 'save']),
                'masterDataDelete' => $this->generateUrl(MasterDataController::ROUTE, ['action' => 'delete']),
                'entrySave' => $this->generateUrl(EntryController::ROUTE, ['action' => 'save']),
                'entryDone' => $this->generateUrl(EntryController::ROUTE, ['action' => 'done']),
                'entryMove' => $this->generateUrl(EntryController::ROUTE, ['action' => 'move']),
                'entryCopy' => $this->generateUrl(EntryController::ROUTE, ['action' => 'copy']),
                'entryDelete' => $this->generateUrl(EntryController::ROUTE, ['action' => 'delete']),
                'entryOpen' => $this->generateUrl(EntryController::ROUTE, ['action' => 'open']),
            ],
            'labels' => $GLOBALS['TL_LANG']['MSC']['classBook'] ?? [],
            'member' => [
                // Contao stores the values HTML encoded, Vue encodes them again
                'firstname' => StringUtil::decodeEntities((string) $user->firstname),
                'lastname' => StringUtil::decodeEntities((string) $user->lastname),
            ],
        ]);

        // The page contains a personal access token
        $response = $template->getResponse();
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
