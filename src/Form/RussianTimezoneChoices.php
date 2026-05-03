<?php

namespace App\Form;

final class RussianTimezoneChoices
{
    /**
     * @return array<string, string>
     */
    public static function choices(): array
    {
        return [
            '(UTC+02:00) Калининград, Балтийск' => 'Europe/Kaliningrad',
            '(UTC+03:00) Москва, Санкт-Петербург' => 'Europe/Moscow',
            '(UTC+04:00) Самара, Ульяновск' => 'Europe/Samara',
            '(UTC+05:00) Екатеринбург, Пермь' => 'Asia/Yekaterinburg',
            '(UTC+06:00) Омск, Тара' => 'Asia/Omsk',
            '(UTC+07:00) Красноярск, Новокузнецк' => 'Asia/Krasnoyarsk',
            '(UTC+08:00) Иркутск, Улан-Удэ' => 'Asia/Irkutsk',
            '(UTC+09:00) Якутск, Благовещенск' => 'Asia/Yakutsk',
            '(UTC+10:00) Владивосток, Хабаровск' => 'Asia/Vladivostok',
            '(UTC+11:00) Магадан, Среднеколымск' => 'Asia/Magadan',
            '(UTC+11:00) Южно-Сахалинск, Невельск' => 'Asia/Sakhalin',
            '(UTC+12:00) Петропавловск-Камчатский, Елизово' => 'Asia/Kamchatka',
            '(UTC+12:00) Анадырь, Певек' => 'Asia/Anadyr',
        ];
    }

    public static function labelFor(string $timezone): string
    {
        foreach (self::choices() as $label => $value) {
            if ($value === $timezone) {
                return $label;
            }
        }

        return $timezone;
    }
}
