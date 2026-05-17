<?php

class RideSyncRideStateMachine
{
    public const SEARCHING = 'searching';
    public const MATCHED = 'matched';
    public const DRIVER_ASSIGNED = 'driver_assigned';
    public const ARRIVING = 'arriving';
    public const ACTIVE = 'active';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    public static function validLiveStatuses(): array
    {
        return [
            self::SEARCHING,
            self::MATCHED,
            self::DRIVER_ASSIGNED,
            self::ARRIVING,
            self::ACTIVE,
            self::COMPLETED,
            self::CANCELLED,
        ];
    }

    public static function terminalLiveStatuses(): array
    {
        return [self::COMPLETED, self::CANCELLED];
    }

    public static function isValidLiveStatus(string $status): bool
    {
        return in_array($status, self::validLiveStatuses(), true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        if (!self::isValidLiveStatus($from) || !self::isValidLiveStatus($to)) {
            return false;
        }

        if (in_array($from, self::terminalLiveStatuses(), true)) {
            return false;
        }

        $allowed = [
            self::SEARCHING => [self::MATCHED, self::DRIVER_ASSIGNED, self::CANCELLED],
            self::MATCHED => [self::DRIVER_ASSIGNED, self::ACTIVE, self::CANCELLED],
            self::DRIVER_ASSIGNED => [self::ARRIVING, self::ACTIVE, self::CANCELLED],
            self::ARRIVING => [self::ACTIVE, self::CANCELLED],
            self::ACTIVE => [self::COMPLETED, self::CANCELLED],
        ];

        return in_array($to, $allowed[$from] ?? [], true);
    }
}

?>
