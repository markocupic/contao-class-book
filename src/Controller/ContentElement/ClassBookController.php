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
use Markocupic\ContaoClassBook\Controller\Api\AccountController;
use Markocupic\ContaoClassBook\Controller\Api\EntryController;
use Markocupic\ContaoClassBook\Controller\Api\MasterDataController;
use Markocupic\ContaoClassBook\Controller\Api\TwoFactorController;
use Markocupic\ContaoClassBook\Controller\Api\WeekController;
use Markocupic\ContaoClassBook\EventListener\LoginIdentifierListener;
use Markocupic\ContaoClassBook\Security\AccessTokenManager;
use Markocupic\ContaoClassBook\Security\ApiAuthenticator;
use Markocupic\ContaoClassBook\Service\Registration\LegalInfo;
use Markocupic\ContaoClassBook\Service\Registration\RegistrationService;
use Markocupic\ContaoClassBook\Service\Registration\RegistrationValidator;
use Scheb\TwoFactorBundle\Security\Authentication\Exception\InvalidTwoFactorCodeException;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
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
 * If the element allows it, teachers can register and activate their account by e-mail.
 */
#[AsContentElement(self::TYPE, category: 'miscellaneous', template: 'content_element/class_book')]
class ClassBookController extends AbstractContentElementController
{
    public const TYPE = 'class_book';

    public const STATE_LOGIN = 'login';

    public const STATE_TWO_FACTOR = 'two_factor';

    public const STATE_APP = 'app';

    public const STATE_BACKEND = 'backend';

    public const STATE_LEGAL = 'legal';

    // Query parameter that shows the registration form instead of the login form
    public const REGISTER_PARAMETER = 'register';

    // A field that people do not see and bots fill in
    private const HONEYPOT = 'website';

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
        private readonly RegistrationService $registrationService,
        private readonly RegistrationValidator $registrationValidator,
        // The limiter of the core for password resets: three attempts in 15 minutes
        #[Autowire(service: 'contao.rate_limit.member_password_factory')]
        private readonly RateLimiterFactory $rateLimiterFactory,
    ) {
    }

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        // The back end shows a placeholder only
        if ($this->isBackendScope($request)) {
            $template->set('state', self::STATE_BACKEND);

            return $template->getResponse();
        }

        // Standalone: the app fills the page, see the template and html.cb-standalone in the styles
        $template->set('standalone', (bool) $model->cbStandalone);

        // Imprint, terms of use and privacy policy: for everybody, logged in or not
        $legal = $this->getLegal($model, $request);

        $template->set('legal', $legal);

        if (null !== $legal['page']) {
            $template->set('state', self::STATE_LEGAL);

            return $this->getPrivateResponse($template);
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
                'accountDelete' => $this->generateUrl(AccountController::ROUTE, ['action' => 'delete']),
                'accountUsername' => $this->generateUrl(AccountController::ROUTE, ['action' => 'username']),
                'logout' => $this->logoutUrlGenerator->getLogoutPath(),
                ...$legal['urls'],
            ],
            'labels' => $GLOBALS['TL_LANG']['MSC']['classBook'] ?? [],
            'member' => [
                // Contao stores the values HTML encoded, Vue encodes them again
                'firstname' => StringUtil::decodeEntities((string) $user->firstname),
                'lastname' => StringUtil::decodeEntities((string) $user->lastname),
                'username' => StringUtil::decodeEntities((string) $user->username),
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

        $template->set('registration', $twoFactorInProgress ? ['enabled' => false] : $this->getRegistration($model, $request));

        return $this->getPrivateResponse($template);
    }

    /**
     * The legal pages: their addresses for the links, and the page to show if the
     * request asks for one.
     *
     * @return array{page: string|null, template: string|null, home: string, urls: array<string, string>, data: array<string, string>}
     */
    private function getLegal(ContentModel $model, Request $request): array
    {
        $home = $request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo();

        $page = $request->query->get(LegalInfo::PARAMETER);
        $page = \in_array($page, LegalInfo::PAGES, true) ? $page : null;

        $urls = [];

        foreach (LegalInfo::PAGES as $name) {
            $urls[$name] = $home.'?'.LegalInfo::PARAMETER.'='.$name;
        }

        return [
            'page' => $page,
            'template' => null === $page ? null : LegalInfo::getTemplate($page),
            'home' => $home,
            'urls' => $urls,
            'data' => [
                'host' => $request->getHost(),
                // Contao stores the values HTML encoded, Twig encodes them again
                'operator' => trim(StringUtil::decodeEntities((string) $model->cbOperator)),
                'email' => trim((string) $model->cbOperatorEmail),
                'hosting' => trim(StringUtil::decodeEntities((string) $model->cbHosting)),
                'version' => LegalInfo::getVersionDate(),
            ],
        ];
    }

    /**
     * Registration of new teachers, if the element allows it: the form, its result and
     * the activation by the link in the e-mail.
     *
     * @return array<string, mixed>
     */
    private function getRegistration(ContentModel $model, Request $request): array
    {
        if (!$model->cbRegistration) {
            return ['enabled' => false];
        }

        $formId = 'cb_register_'.$model->id;
        $page = $request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo();

        $registration = [
            'enabled' => true,
            'form_id' => $formId,
            'honeypot' => self::HONEYPOT,
            'login_url' => $page,
            'register_url' => $page.'?'.self::REGISTER_PARAMETER.'=1',
            // What the template shows: login, form, sent, activated or invalid
            'view' => $request->query->has(self::REGISTER_PARAMETER) ? 'form' : 'login',
            'values' => ['firstname' => '', 'lastname' => '', 'email' => ''],
            'errors' => [],
            'min_password_length' => RegistrationValidator::MIN_PASSWORD_LENGTH,
        ];

        // The link in the e-mail
        $token = $request->query->get(RegistrationService::TOKEN_PARAMETER);

        if (\is_string($token) && str_starts_with($token, RegistrationService::TOKEN_PREFIX.'-')) {
            $registration['view'] = $this->registrationService->activate($token) ? 'activated' : 'invalid';

            return $registration;
        }

        if (!$request->isMethod(Request::METHOD_POST) || $formId !== $request->request->get('FORM_SUBMIT')) {
            return $registration;
        }

        $data = $this->registrationValidator->normalize($request->request->all());
        $errors = $this->registrationValidator->validate($data, (string) $model->cbRegistrationDomains);

        $registration['view'] = 'form';
        $registration['values'] = ['firstname' => $data['firstname'], 'lastname' => $data['lastname'], 'email' => $data['email']];
        $registration['errors'] = $errors;

        if ([] !== $errors) {
            return $registration;
        }

        // A bot gets the same answer as everybody else, but nothing happens
        if ('' !== (string) $request->request->get(self::HONEYPOT, '')) {
            $registration['view'] = 'sent';

            return $registration;
        }

        // Every registration sends an e-mail, so the number per visitor is limited
        if (!$this->rateLimiterFactory->create('class_book_registration_'.$request->getClientIp())->consume()->isAccepted()) {
            $registration['errors'] = ['form' => 'tooMany'];

            return $registration;
        }

        $this->registrationService->register($data, $page, $request->getLocale());

        $registration['view'] = 'sent';

        return $registration;
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
