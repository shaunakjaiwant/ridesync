<?php

namespace RideSync\Backend\Contracts;

/**
 * Marker interface for all repository classes.
 *
 * Repositories are responsible for data-access logic only. They should
 * accept and return plain arrays or value objects, never HTTP responses.
 * All queries must use prepared statements to prevent SQL injection.
 */
interface RepositoryContract
{
}
