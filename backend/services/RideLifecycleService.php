<?php

namespace RideSync\Backend\Services;

use DomainException;
use mysqli;
use RideSync\Backend\Repositories\RideRepository;
use Throwable;

final class RideLifecycleService
{
    public function __construct(private mysqli $conn)
    {
    }

    public function transitionOwnerRide(int $rideId, int $userId, string $targetStatus): string
    {
        if (!in_array($targetStatus, ['active', 'completed'], true)) {
            throw new DomainException('Invalid ride status action.');
        }

        $repository = new RideRepository($this->conn);
        mysqli_begin_transaction($this->conn);

        try {
            $ride = $repository->findOwnedRideForUpdate($rideId, $userId);
            if (!$ride) {
                throw new DomainException('Ride not found or you do not own it.');
            }
            if ($ride['ride_status'] === 'cancelled') {
                throw new DomainException('Cancelled rides cannot be updated.');
            }

            $acceptedUsers = $repository->acceptedUserIds($rideId);
            $assignedDriverId = (int) ($ride['driver_id'] ?? 0);
            if (count($acceptedUsers) === 0 && $assignedDriverId <= 0) {
                throw new DomainException('Accept at least one rider or assign a driver before starting the trip.');
            }

            $currentStatus = (string) $ride['live_status'];
            if ($currentStatus === 'searching' && $assignedDriverId > 0) {
                $currentStatus = 'driver_assigned';
            } elseif ($currentStatus === 'searching') {
                $currentStatus = 'matched';
            }

            if ($targetStatus === 'active' && !in_array($currentStatus, ['matched', 'driver_assigned'], true)) {
                throw new DomainException('Only matched rides can be started.');
            }
            if ($targetStatus === 'completed' && $currentStatus !== 'active') {
                throw new DomainException('Start the trip before marking it completed.');
            }

            $message = $targetStatus === 'active'
                ? $this->startRide($rideId, $ride, $acceptedUsers, $assignedDriverId)
                : $this->completeRide($repository, $rideId, $ride, $acceptedUsers, $assignedDriverId);

            mysqli_commit($this->conn);
            return $message;
        } catch (Throwable $exception) {
            mysqli_rollback($this->conn);
            throw $exception;
        }
    }

    private function startRide(int $rideId, array $ride, array $acceptedUsers, int $assignedDriverId): string
    {
        ridesync_update_live_status($this->conn, $rideId, 'active', 'Trip started. Follow the shared route and keep passengers updated.');
        $this->notifyParticipants(
            $acceptedUsers,
            $assignedDriverId,
            'Ride started',
            'Your RideSync trip from ' . $ride['origin'] . ' to ' . $ride['destination'] . ' has started.'
        );

        return 'Trip marked as started.';
    }

    private function completeRide(RideRepository $repository, int $rideId, array $ride, array $acceptedUsers, int $assignedDriverId): string
    {
        $repository->closeRide($rideId);
        ridesync_update_live_status($this->conn, $rideId, 'completed', 'Trip completed. Thanks for sharing the ride.', null, 0);

        $participants = array_values(array_unique(array_merge([(int) $ride['user_id']], $acceptedUsers)));
        $fareBreakdown = calculateDynamicFareBreakdown(
            $ride['origin'],
            $ride['destination'],
            max(1, count($participants)),
            $ride['route_distance_km']
        );
        foreach ($participants as $participantId) {
            ridesync_wallet_record_fare_due(
                $this->conn,
                $participantId,
                $rideId,
                $assignedDriverId > 0 ? $assignedDriverId : null,
                $fareBreakdown['final_fare'],
                'Shared ride fare from ' . $ride['origin'] . ' to ' . $ride['destination'],
                'community_ride',
                $rideId
            );
        }

        if ($assignedDriverId > 0) {
            $distanceKm = ridesync_float_or_null($ride['route_distance_km'] ?? null);
            if ($distanceKm === null || $distanceKm <= 0) {
                // Use Haversine if GPS coordinates are available, else string-based fallback
                if (!empty($ride['origin_lat']) && !empty($ride['origin_lng'])
                    && !empty($ride['destination_lat']) && !empty($ride['destination_lng'])) {
                    $distanceKm = ridesync_haversine_distance(
                        $ride['origin_lat'], $ride['origin_lng'],
                        $ride['destination_lat'], $ride['destination_lng']
                    );
                } else {
                    $distanceKm = ridesync_estimate_route_distance($ride['origin'], $ride['destination']);
                }
            }
            ridesync_record_driver_trip(
                $this->conn,
                $assignedDriverId,
                $ride['origin'],
                $ride['destination'],
                ridesync_driver_fare_estimate($distanceKm),
                $distanceKm,
                'community_ride',
                $rideId
            );
        }

        $this->notifyParticipants(
            $acceptedUsers,
            $assignedDriverId,
            'Ride completed',
            'Your RideSync trip from ' . $ride['origin'] . ' to ' . $ride['destination'] . ' has been completed.'
        );

        return 'Trip marked as completed.';
    }

    private function notifyParticipants(array $acceptedUsers, int $assignedDriverId, string $title, string $message): void
    {
        foreach ($acceptedUsers as $acceptedUserId) {
            ridesync_create_notification($this->conn, (int) $acceptedUserId, null, $title, $message);
        }

        if ($assignedDriverId > 0) {
            ridesync_create_notification($this->conn, null, $assignedDriverId, $title, $message);
        }
    }
}
