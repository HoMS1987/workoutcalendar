<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/** Personal settings section "Workout Calendar". */
final class Section implements IIconSection
{
    public const ID = 'workoutcalendar';

    public function __construct(
        private readonly IL10N $l,
        private readonly IURLGenerator $urlGenerator,
    ) {
    }

    public function getID(): string
    {
        return self::ID;
    }

    public function getName(): string
    {
        return $this->l->t('Workout Calendar');
    }

    public function getPriority(): int
    {
        return 80;
    }

    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath('workoutcalendar', 'app-dark.svg');
    }
}
