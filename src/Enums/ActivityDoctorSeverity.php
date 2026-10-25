<?php

declare(strict_types=1);

namespace Nvl\Activity\Enums;

/**
 * Defines the severity of one package doctor check.
 *
 * @api
 */
enum ActivityDoctorSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';

    /**
     * Return the localized severity label.
     */
    public function getLabel(): string
    {
        return (string) trans("nvl-activity::activity/general.enums.doctor_severity.{$this->value}");
    }
}
