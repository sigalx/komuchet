<?php

namespace App\Twig;

use App\Form\RussianTimezoneChoices;
use App\Service\WorkspaceContext;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class WorkspaceDateExtension extends AbstractExtension
{
    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('workspace_datetime', $this->formatWorkspaceDateTime(...)),
            new TwigFilter('russian_timezone_label', $this->formatRussianTimezoneLabel(...)),
        ];
    }

    public function formatWorkspaceDateTime(?DateTimeInterface $value, string $format = 'd.m.Y H:i'): string
    {
        if (!$value instanceof DateTimeInterface) {
            return '—';
        }

        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone($this->resolveWorkspaceTimezone())
            ->format($format);
    }

    public function formatRussianTimezoneLabel(?string $timezone): string
    {
        if ($timezone === null || trim($timezone) === '') {
            return '—';
        }

        return RussianTimezoneChoices::labelFor($timezone);
    }

    private function resolveWorkspaceTimezone(): DateTimeZone
    {
        $timezoneName = $this->workspaceContext->getCurrentWorkspace()?->getTimezone() ?? 'Europe/Moscow';

        try {
            return new DateTimeZone($timezoneName);
        } catch (\Throwable) {
            return new DateTimeZone('Europe/Moscow');
        }
    }
}
