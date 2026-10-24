<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Enum;

enum NotificationType: string
{
    case WindowOpen = 'window_open';
    case SetpointNotApplicable = 'setpoint_not_applicable';
    case DeviceUnresponsive = 'device_unresponsive';
    case SensorUnavailable = 'sensor_unavailable';
}
