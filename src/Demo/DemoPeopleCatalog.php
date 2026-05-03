<?php

namespace App\Demo;

final class DemoPeopleCatalog
{
    public const DEFAULT_PERSON_COUNT = 500;
    public const MAX_PERSON_COUNT = 900;

    /**
     * @var list<array{male: string, female: string}>
     */
    private const SURNAMES = [
        ['male' => 'Смирнов', 'female' => 'Смирнова'],
        ['male' => 'Иванов', 'female' => 'Иванова'],
        ['male' => 'Кузнецов', 'female' => 'Кузнецова'],
        ['male' => 'Соколов', 'female' => 'Соколова'],
        ['male' => 'Попов', 'female' => 'Попова'],
        ['male' => 'Лебедев', 'female' => 'Лебедева'],
        ['male' => 'Козлов', 'female' => 'Козлова'],
        ['male' => 'Новиков', 'female' => 'Новикова'],
        ['male' => 'Морозов', 'female' => 'Морозова'],
        ['male' => 'Петров', 'female' => 'Петрова'],
        ['male' => 'Волков', 'female' => 'Волкова'],
        ['male' => 'Соловьев', 'female' => 'Соловьева'],
        ['male' => 'Васильев', 'female' => 'Васильева'],
        ['male' => 'Зайцев', 'female' => 'Зайцева'],
        ['male' => 'Павлов', 'female' => 'Павлова'],
        ['male' => 'Семенов', 'female' => 'Семенова'],
        ['male' => 'Голубев', 'female' => 'Голубева'],
        ['male' => 'Виноградов', 'female' => 'Виноградова'],
        ['male' => 'Богданов', 'female' => 'Богданова'],
        ['male' => 'Воробьев', 'female' => 'Воробьева'],
        ['male' => 'Федоров', 'female' => 'Федорова'],
        ['male' => 'Михайлов', 'female' => 'Михайлова'],
        ['male' => 'Беляев', 'female' => 'Беляева'],
        ['male' => 'Тарасов', 'female' => 'Тарасова'],
        ['male' => 'Белов', 'female' => 'Белова'],
        ['male' => 'Комаров', 'female' => 'Комарова'],
        ['male' => 'Орлов', 'female' => 'Орлова'],
        ['male' => 'Киселев', 'female' => 'Киселева'],
        ['male' => 'Макаров', 'female' => 'Макарова'],
        ['male' => 'Андреев', 'female' => 'Андреева'],
    ];

    /**
     * @var list<string>
     */
    private const MALE_FIRST_NAMES = [
        'Александр',
        'Алексей',
        'Андрей',
        'Антон',
        'Артем',
        'Виктор',
        'Владимир',
        'Дмитрий',
        'Евгений',
        'Иван',
        'Игорь',
        'Кирилл',
        'Максим',
        'Михаил',
        'Николай',
        'Олег',
        'Павел',
        'Роман',
        'Сергей',
        'Денис',
        'Илья',
        'Константин',
        'Юрий',
        'Виталий',
        'Василий',
        'Анатолий',
        'Григорий',
        'Аркадий',
        'Георгий',
        'Владислав',
    ];

    /**
     * @var list<string>
     */
    private const FEMALE_FIRST_NAMES = [
        'Анастасия',
        'Анна',
        'Мария',
        'Елена',
        'Ольга',
        'Наталья',
        'Екатерина',
        'Татьяна',
        'Ирина',
        'Светлана',
        'Юлия',
        'Дарья',
        'Виктория',
        'Ксения',
        'Александра',
        'Алина',
        'Валерия',
        'Полина',
        'София',
        'Надежда',
        'Людмила',
        'Галина',
        'Любовь',
        'Марина',
        'Елизавета',
        'Вероника',
        'Кристина',
        'Диана',
        'Алена',
        'Оксана',
    ];

    /**
     * @var list<string>
     */
    private const MALE_SECOND_NAMES = [
        'Александрович',
        'Алексеевич',
        'Андреевич',
        'Антонович',
        'Артемович',
        'Викторович',
        'Владимирович',
        'Дмитриевич',
        'Евгеньевич',
        'Иванович',
        'Игоревич',
        'Кириллович',
        'Максимович',
        'Михайлович',
        'Николаевич',
        'Олегович',
        'Павлович',
        'Романович',
        'Сергеевич',
        'Денисович',
        'Ильич',
        'Константинович',
        'Юрьевич',
        'Витальевич',
        'Васильевич',
        'Анатольевич',
        'Григорьевич',
        'Аркадьевич',
        'Георгиевич',
        'Владиславович',
    ];

    /**
     * @var list<string>
     */
    private const FEMALE_SECOND_NAMES = [
        'Александровна',
        'Алексеевна',
        'Андреевна',
        'Антоновна',
        'Артемовна',
        'Викторовна',
        'Владимировна',
        'Дмитриевна',
        'Евгеньевна',
        'Ивановна',
        'Игоревна',
        'Кирилловна',
        'Максимовна',
        'Михайловна',
        'Николаевна',
        'Олеговна',
        'Павловна',
        'Романовна',
        'Сергеевна',
        'Денисовна',
        'Ильинична',
        'Константиновна',
        'Юрьевна',
        'Витальевна',
        'Васильевна',
        'Анатольевна',
        'Григорьевна',
        'Аркадьевна',
        'Георгиевна',
        'Владиславовна',
    ];

    private readonly int $surnameOffset;
    private readonly int $firstNameOffset;
    private readonly int $secondNameOffset;
    private readonly int $genderOffset;

    public function __construct(
        private readonly string $seed = 'komuchet-demo',
    ) {
        $this->surnameOffset = $this->offset('surname');
        $this->firstNameOffset = $this->offset('first-name');
        $this->secondNameOffset = $this->offset('second-name');
        $this->genderOffset = $this->offset('gender') % 2;
    }

    /**
     * @return list<DemoPerson>
     */
    public function people(int $count = self::DEFAULT_PERSON_COUNT): array
    {
        if ($count < 1 || $count > self::MAX_PERSON_COUNT) {
            throw new \InvalidArgumentException(sprintf('Demo people count must be between 1 and %d.', self::MAX_PERSON_COUNT));
        }

        $people = [];

        for ($number = 1; $number <= $count; ++$number) {
            $people[] = $this->person($number);
        }

        return $people;
    }

    public function person(int $number): DemoPerson
    {
        $this->assertNumber($number);

        $zeroBasedNumber = $number - 1;
        $surnameIndex = $this->boundedIndex($zeroBasedNumber + $this->surnameOffset);
        $firstNameIndex = $this->boundedIndex(intdiv($zeroBasedNumber, 30) + 7 * $zeroBasedNumber + $this->firstNameOffset);
        $secondNameIndex = $this->boundedIndex(11 * intdiv($zeroBasedNumber, 30) + 13 * $zeroBasedNumber + $this->secondNameOffset);
        $gender = (($zeroBasedNumber + $this->genderOffset) % 2) === 0
            ? DemoPerson::GENDER_MALE
            : DemoPerson::GENDER_FEMALE;

        return $this->buildPerson(
            number: $number,
            gender: $gender,
            surnameIndex: $surnameIndex,
            firstNameIndex: $firstNameIndex,
            secondNameIndex: $secondNameIndex,
            email: sprintf('demo.subscriber.%03d@example.test', $number),
        );
    }

    public function family(int $number): DemoFamily
    {
        $this->assertNumber($number);

        $zeroBasedNumber = $number - 1;
        $surnameIndex = $this->boundedIndex($zeroBasedNumber + $this->surnameOffset);
        $ownerFirstNameIndex = $this->boundedIndex(5 * $zeroBasedNumber + $this->firstNameOffset);
        $ownerSecondNameIndex = $this->boundedIndex(7 * $zeroBasedNumber + $this->secondNameOffset);
        $spouseFirstNameIndex = $this->boundedIndex(11 * $zeroBasedNumber + $this->firstNameOffset + 3);
        $spouseSecondNameIndex = $this->boundedIndex(13 * $zeroBasedNumber + $this->secondNameOffset + 5);

        return new DemoFamily(
            number: $number,
            owner: $this->buildPerson(
                number: $number,
                gender: DemoPerson::GENDER_MALE,
                surnameIndex: $surnameIndex,
                firstNameIndex: $ownerFirstNameIndex,
                secondNameIndex: $ownerSecondNameIndex,
                email: sprintf('demo.family.%03d.owner@example.test', $number),
            ),
            spouse: $this->buildPerson(
                number: $number,
                gender: DemoPerson::GENDER_FEMALE,
                surnameIndex: $surnameIndex,
                firstNameIndex: $spouseFirstNameIndex,
                secondNameIndex: $spouseSecondNameIndex,
                email: sprintf('demo.family.%03d.spouse@example.test', $number),
            ),
        );
    }

    private function buildPerson(
        int $number,
        string $gender,
        int $surnameIndex,
        int $firstNameIndex,
        int $secondNameIndex,
        string $email,
    ): DemoPerson {
        $isMale = $gender === DemoPerson::GENDER_MALE;

        return new DemoPerson(
            number: $number,
            gender: $gender,
            lastName: self::SURNAMES[$surnameIndex][$gender],
            firstName: $isMale ? self::MALE_FIRST_NAMES[$firstNameIndex] : self::FEMALE_FIRST_NAMES[$firstNameIndex],
            secondName: $isMale ? self::MALE_SECOND_NAMES[$secondNameIndex] : self::FEMALE_SECOND_NAMES[$secondNameIndex],
            email: $email,
        );
    }

    private function assertNumber(int $number): void
    {
        if ($number < 1 || $number > self::MAX_PERSON_COUNT) {
            throw new \InvalidArgumentException(sprintf('Demo person number must be between 1 and %d.', self::MAX_PERSON_COUNT));
        }
    }

    private function boundedIndex(int $value): int
    {
        return $value % 30;
    }

    private function offset(string $scope): int
    {
        $hash = hash('xxh3', sprintf('%s:%s', $this->seed, $scope));

        return (int) (hexdec(substr($hash, 0, 8)) % 30);
    }
}
