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

namespace Markocupic\ContaoClassBook\Service\Registration;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\OptIn\OptInInterface;
use Contao\CoreBundle\OptIn\OptInTokenInterface;
use Contao\FrontendUser;
use Contao\OptInModel;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Registers a teacher as a Contao member that has to be activated by a link in an e-mail.
 *
 * The link is a double opt-in token of Contao. The prefix is the one of the registration
 * module of the core, so that the core removes registrations that were never activated.
 */
final class RegistrationService
{
    public const TOKEN_PREFIX = 'reg';

    public const TOKEN_PARAMETER = 'token';

    private const DOMAIN = 'contao_default';

    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'contao.opt_in')]
        private readonly OptInInterface $optIn,
        private readonly PasswordHasherFactoryInterface $passwordHasherFactory,
        private readonly TranslatorInterface $translator,
        private readonly ContaoFramework $framework,
    ) {
    }

    /**
     * Creates the member and sends the activation link.
     *
     * The caller gets no result on purpose: the form shows the same message whether the
     * address is new or already registered, so that nobody can find out who has an account.
     *
     * @param array{firstname: string, lastname: string, email: string, password: string} $data validated, see RegistrationValidator
     * @param string                                                                         $url    the page with the class book
     */
    public function register(array $data, string $url, string $locale): void
    {
        $member = $this->connection->fetchAssociative(
            'SELECT id, disable FROM tl_member WHERE LOWER(email) = ? OR LOWER(username) = ? ORDER BY id LIMIT 1',
            [$data['email'], $data['email']],
        );

        if (false !== $member) {
            // Not activated yet: send the link again. The data of the first registration stays,
            // otherwise somebody else could set the password of an account that is not theirs.
            if ($member['disable']) {
                $this->sendActivation((int) $member['id'], $data['email'], $url, $locale);
            }

            return;
        }

        $time = time();
        $password = $this->passwordHasherFactory->getPasswordHasher(FrontendUser::class)->hash($data['password']);

        $this->connection->insert('tl_member', [
            'tstamp' => $time,
            'dateAdded' => $time,
            // Contao 5 stores texts HTML encoded
            'firstname' => StringUtil::specialchars($data['firstname']),
            'lastname' => StringUtil::specialchars($data['lastname']),
            'email' => $data['email'],
            'username' => $data['email'],
            'password' => $password,
            'language' => $locale,
            'login' => 1,
            'disable' => 1,
        ]);

        $this->sendActivation((int) $this->connection->lastInsertId(), $data['email'], $url, $locale);
    }

    /**
     * Activates the member the token belongs to.
     *
     * @return bool false if the link is invalid or has expired
     */
    public function activate(string $identifier): bool
    {
        if (!str_starts_with($identifier, self::TOKEN_PREFIX.'-')) {
            return false;
        }

        $token = $this->optIn->find($identifier);

        if (null === $token || !$token->isValid()) {
            return false;
        }

        $related = $token->getRelatedRecords();

        if (['tl_member'] !== array_keys($related) || 1 !== \count($related['tl_member'])) {
            return false;
        }

        $memberId = (int) current($related['tl_member']);
        $email = $this->connection->fetchOne('SELECT email FROM tl_member WHERE id = ?', [$memberId]);

        if (false === $email || mb_strtolower((string) $email) !== mb_strtolower($token->getEmail())) {
            return false;
        }

        // A link that is opened twice is fine
        if (!$token->isConfirmed()) {
            $this->connection->update('tl_member', ['disable' => 0, 'tstamp' => time()], ['id' => $memberId]);

            $token->confirm();
        }

        return true;
    }

    private function sendActivation(int $memberId, string $email, string $url, string $locale): void
    {
        $token = $this->findOpenToken($memberId) ?? $this->optIn->create(self::TOKEN_PREFIX, $email, ['tl_member' => [$memberId]]);
        $link = $url.(str_contains($url, '?') ? '&' : '?').self::TOKEN_PARAMETER.'='.$token->getIdentifier();
        $host = (string) parse_url($url, PHP_URL_HOST);

        $token->send(
            $this->translator->trans('MSC.classBook.registerMailSubject', [$host], self::DOMAIN, $locale),
            $this->translator->trans('MSC.classBook.registerMailText', [$host, $link], self::DOMAIN, $locale),
        );
    }

    /**
     * A token that was sent already is sent again unchanged, with the link of the first e-mail.
     */
    private function findOpenToken(int $memberId): OptInTokenInterface|null
    {
        $this->framework->initialize();

        $models = $this->framework->getAdapter(OptInModel::class)->findByRelatedTableAndIds('tl_member', [$memberId]);

        foreach ($models ?? [] as $model) {
            if (!str_starts_with((string) $model->token, self::TOKEN_PREFIX.'-')) {
                continue;
            }

            $token = $this->optIn->find((string) $model->token);

            if (null !== $token && $token->isValid() && !$token->isConfirmed()) {
                return $token;
            }
        }

        return null;
    }
}
