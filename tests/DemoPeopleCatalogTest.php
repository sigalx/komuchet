<?php

namespace App\Tests;

use App\Demo\DemoPeopleCatalog;
use App\Demo\DemoPerson;
use PHPUnit\Framework\TestCase;

final class DemoPeopleCatalogTest extends TestCase
{
    public function testGeneratesFiveHundredDeterministicPeople(): void
    {
        $catalog = new DemoPeopleCatalog('test-seed');

        $people = $catalog->people();
        $samePeople = (new DemoPeopleCatalog('test-seed'))->people();

        self::assertCount(500, $people);
        self::assertSame(
            array_map(static fn (DemoPerson $person): string => $person->fullName(), $people),
            array_map(static fn (DemoPerson $person): string => $person->fullName(), $samePeople),
        );
        self::assertCount(500, array_unique(array_map(static fn (DemoPerson $person): string => $person->email, $people)));
        self::assertCount(500, array_unique(array_map(static fn (DemoPerson $person): string => $person->fullName(), $people)));
        self::assertSame('demo.subscriber.001@example.test', $people[0]->email);
        self::assertSame('demo.subscriber.500@example.test', $people[499]->email);
    }

    public function testDifferentSeedsChangeGeneratedNamesButNotStableEmails(): void
    {
        $first = (new DemoPeopleCatalog('seed-a'))->person(1);
        $second = (new DemoPeopleCatalog('seed-b'))->person(1);

        self::assertNotSame($first->fullName(), $second->fullName());
        self::assertSame('demo.subscriber.001@example.test', $first->email);
        self::assertSame('demo.subscriber.001@example.test', $second->email);
    }

    public function testGeneratedPeopleUseGenderMatchingNameParts(): void
    {
        $people = (new DemoPeopleCatalog('test-seed'))->people(60);

        foreach ($people as $person) {
            if ($person->isMale()) {
                self::assertFalse(str_ends_with($person->lastName, 'а'));
                self::assertStringEndsWith('ч', $person->secondName);
                continue;
            }

            self::assertTrue($person->isFemale());
            self::assertStringEndsWith('а', $person->lastName);
            self::assertStringEndsWith('на', $person->secondName);
        }
    }

    public function testBuildsFamilyWithMatchingSurnameForms(): void
    {
        $family = (new DemoPeopleCatalog('test-seed'))->family(7);

        self::assertTrue($family->owner->isMale());
        self::assertTrue($family->spouse->isFemale());
        self::assertStringEndsWith('а', $family->spouse->lastName);
        self::assertSame($family->owner->lastName.'а', $family->spouse->lastName);
        self::assertSame('demo.family.007.owner@example.test', $family->owner->email);
        self::assertSame('demo.family.007.spouse@example.test', $family->spouse->email);
    }

    public function testRejectsInvalidPersonNumbersAndCounts(): void
    {
        $catalog = new DemoPeopleCatalog();

        $this->expectException(\InvalidArgumentException::class);
        $catalog->person(0);
    }

    public function testRejectsTooLargePeopleCount(): void
    {
        $catalog = new DemoPeopleCatalog();

        $this->expectException(\InvalidArgumentException::class);
        $catalog->people(DemoPeopleCatalog::MAX_PERSON_COUNT + 1);
    }
}
