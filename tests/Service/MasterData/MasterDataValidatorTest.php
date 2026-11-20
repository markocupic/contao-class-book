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

namespace Markocupic\ContaoClassBook\Tests\Service\MasterData;

use Markocupic\ContaoClassBook\Exception\ValidationException;
use Markocupic\ContaoClassBook\Service\MasterData\MasterDataValidator;
use PHPUnit\Framework\TestCase;

class MasterDataValidatorTest extends TestCase
{
    public function testNormalizesValidData(): void
    {
        $values = $this->validate('subject', ['title' => '  Mathematik ', 'shortTitle' => 'MA', 'color' => '#1F6FEB', 'foo' => 'bar']);

        $this->assertSame(['title' => 'Mathematik', 'shortTitle' => 'MA', 'color' => '#1f6feb'], $values);
    }

    public function testCastsIntegersAndReferences(): void
    {
        $this->assertSame(['number' => 3, 'startTime' => '09:15', 'endTime' => '10:00'], $this->validate('period', ['number' => '3', 'startTime' => '09:15', 'endTime' => '10:00']));
        $this->assertSame(['title' => '2a', 'schoolYear' => 5], $this->validate('group', ['title' => '2a', 'schoolYear' => 5]));
    }

    public function testReportsMissingRequiredFields(): void
    {
        $this->assertSame(
            ['title' => 'required', 'startDate' => 'required', 'endDate' => 'required'],
            $this->getErrors('schoolYear', ['title' => '   ', 'endDate' => null]),
        );
    }

    public function testReportsInvalidValues(): void
    {
        $this->assertSame(['title' => 'tooLong'], $this->getErrors('subject', ['title' => str_repeat('ä', 65), 'shortTitle' => 'MA', 'color' => '#000000']));
        $this->assertSame(['color' => 'invalid'], $this->getErrors('subject', ['title' => 'Mathematik', 'shortTitle' => 'MA', 'color' => 'red']));
        $this->assertSame(['startDate' => 'invalid'], $this->getErrors('holiday', ['title' => 'Herbst', 'startDate' => '2026-02-30', 'endDate' => '2026-10-10']));
        $this->assertSame(['number' => 'outOfRange', 'startTime' => 'invalid'], $this->getErrors('period', ['number' => '21', 'startTime' => '24:00', 'endTime' => '10:00']));
        $this->assertSame(['number' => 'invalid'], $this->getErrors('period', ['number' => '1.5', 'startTime' => '08:00', 'endTime' => '10:00']));
    }

    public function testTheEndMustNotBeBeforeTheStart(): void
    {
        $this->assertSame(['endDate' => 'beforeStart'], $this->getErrors('schoolYear', ['title' => '2026/27', 'startDate' => '2026-08-17', 'endDate' => '2026-08-16']));
        $this->assertSame(['endTime' => 'notAfterStart'], $this->getErrors('period', ['number' => 1, 'startTime' => '08:00', 'endTime' => '08:00']));

        // A single day is a valid date range
        $this->assertSame('2026-12-08', $this->validate('holiday', ['title' => 'Feiertag', 'startDate' => '2026-12-08', 'endDate' => '2026-12-08'])['endDate']);
    }

    public function testReportsUnknownReferences(): void
    {
        $this->assertSame(['schoolYear' => 'unknownReference'], $this->getErrors('group', ['title' => '2a', 'schoolYear' => 6]));
    }

    public function testRejectsUnknownResources(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->validate('foo', []);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, int|string>
     */
    private function validate(string $resource, array $input): array
    {
        // Only the school year with the id 5 exists
        return (new MasterDataValidator())->validate($resource, $input, static fn (string $resource, int $id): bool => 'schoolYear' === $resource && 5 === $id);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, string>
     */
    private function getErrors(string $resource, array $input): array
    {
        try {
            $this->validate($resource, $input);
        } catch (ValidationException $exception) {
            return $exception->getErrors();
        }

        $this->fail('The data should be invalid.');
    }
}
