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
use Markocupic\ContaoClassBook\Controller\Api\TwoFactorController;
use Markocupic\ContaoClassBook\Controller\Api\WeekController;
use Markocupic\ContaoClassBook\EventListener\LoginIdentifierListener;
use Markocupic\ContaoClassBook\Security\AccessTokenManager;
use Markocupic\ContaoClassBook\Security\ApiAuthenticator;
use Scheb\TwoFactorBundle\Security\Authentication\Exception\InvalidTwoFactorCodeException;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Logout\LogoutUrlGenerator;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The class book app as a content element.
 *
 * Without a logged in member the element shows its own login form. The form is
 * handled by the login of Contao (same fields as the login element of the core),
 * including the two-factor code as a second step. A logged in member gets the app.
 */
#[AsContentElement(self::TYPE, category: 'miscellaneous', template: 'content_element/class_book')]
class ClassBookController extends AbstractContentElementController
{
    public const TYPE = 'class_book';

    public const STATE_LOGIN = 'login';

    public const STATE_TWO_FACTOR = 'two_factor';

    public const STATE_APP = 'app';

    public const STATE_BACKEND = 'backend';

    public function __construct(
        private readonly AccessTokenManager $tokenManager,
        #[Autowire(service: 'security.authentication_utils')]
        private readonly AuthenticationUtils $authenticationUtils,
        // There is no alias for the class, so the service is named
        #[Autowire(service: 'security.logout_url_generator')]
        private readonly LogoutUrlGenerator $logoutUrlGenerator,
        #[Autowire(service: 'security.token_storage')]
        private readonly TokenStorageInterface $tokenStorage,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        // The back end shows a placeholder only
        if ($this->isBackendScope($request)) {
            $template->set('state', self::STATE_BACKEND);

            return $template->getResponse();
        }

        $user = $this->getUser();
        $twoFactorInProgress = $this->isGranted('IS_AUTHENTICATED_2FA_IN_PROGRESS');

        // The app is for members who are logged in completely, i.e. with the two-factor code if they use one
        if (!$user instanceof FrontendUser || $twoFactorInProgress || !$this->isGranted(ApiAuthenticator::ROLE)) {
            return $this->getLoginResponse($template, $model, $request, $twoFactorInProgress);
        }

        $this->initializeContaoFramework();
        System::loadLanguageFile('default');

        $template->set('state', self::STATE_APP);

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
                'twoFactorStatus' => $this->generateUrl(TwoFactorController::ROUTE, ['action' => 'status']),
                'twoFactorSetup' => $this->generateUrl(TwoFactorController::ROUTE, ['action' => 'setup']),
                'twoFactorEnable' => $this->generateUrl(TwoFactorController::ROUTE, ['action' => 'enable']),
                'twoFactorDisable' => $this->generateUrl(TwoFactorController::ROUTE, ['action' => 'disable']),
                'twoFactorBackupCodes' => $this->generateUrl(TwoFactorController::ROUTE, ['action' => 'backup-codes']),
                'logout' => $this->logoutUrlGenerator->getLogoutPath(),
            ],
            'labels' => $GLOBALS['TL_LANG']['MSC']['classBook'] ?? [],
            'member' => [
                // Contao stores the values HTML encoded, Vue encodes them again
                'firstname' => StringUtil::decodeEntities((string) $user->firstname),
                'lastname' => StringUtil::decodeEntities((string) $user->lastname),
            ],
        ]);

        return $this->getPrivateResponse($template);
    }

    /**
     * The login form or, after the password, the form for the two-factor code.
     */
    private function getLoginResponse(FragmentTemplate $template, ContentModel $model, Request $request, bool $twoFactorInProgress): Response
    {
        $message = null;
        $username = '';

        // Only ask for the last error if there is a session, to not start an empty one
        if ($request->hasSession() && ($request->hasPreviousSession() || $request->getSession()->isStarted())) {
            $exception = $this->authenticationUtils->getLastAuthenticationError();
            $username = $this->authenticationUtils->getLastUsername();

            // What the member has typed, which may be the e-mail address (see LoginIdentifierListener)
            $typed = $request->getSession()->remove(LoginIdentifierListener::SESSION_KEY);

            if (\is_string($typed) && '' !== $username) {
                $username = $typed;
            }

            if ($exception instanceof TooManyLoginAttemptsAuthenticationException) {
                $message = 'ERR.tooManyLoginAttempts';
            } elseif ($exception instanceof InvalidTwoFactorCodeException) {
                $message = 'ERR.invalidTwoFactor';
            } elseif ($exception instanceof AuthenticationException) {
                $message = 'ERR.invalidLogin';
            }
        }

        if ($twoFactorInProgress) {
            // Prepares the two-factor providers, like the login element of the core
            $token = $this->tokenStorage->getToken();

            if (null !== $token) {
                $this->eventDispatcher->dispatch(new TwoFactorAuthenticationEvent($request, $token), TwoFactorAuthenticationEvents::FORM);
            }
        }

        $template->set('state', $twoFactorInProgress ? self::STATE_TWO_FACTOR : self::STATE_LOGIN);

        $template->set('login', [
            // The login of Contao handles forms with this id
            'form_id' => 'tl_login_'.$model->id,
            // Back to this page after the login
            'target_path' => base64_encode($request->getUri()),
            'message' => $message,
            'username' => $username,
            'logout_url' => $this->logoutUrlGenerator->getLogoutPath(),
            'marker' => LoginIdentifierListener::FIELD,
        ]);

        return $this->getPrivateResponse($template);
    }

    /**
     * The page contains a personal access token or a form with a request token.
     */
    private function getPrivateResponse(FragmentTemplate $template): Response
    {
        $response = $template->getResponse();
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
