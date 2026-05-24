<?php

namespace RideSync\Backend\Enums;

final class RealtimeEventType
{
    public const RIDE_LIVE_STATUS_UPDATED = 'ride.live_status.updated';
    public const NOTIFICATION_CREATED = 'notification.created';
    public const VERIFICATION_STAGE_UPDATED = 'verification.stage.updated';
    public const VERIFICATION_DECISION_GENERATED = 'verification.decision.generated';
}
