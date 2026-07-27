<?php

namespace RideSync\Backend\Contracts;

/**
 * Marker interface for all domain service classes.
 *
 * Services encapsulate business logic and coordinate between repositories,
 * helpers, and external integrations. They should not directly output HTTP
 * responses; that responsibility belongs to controllers.
 */
interface ServiceContract
{
}
