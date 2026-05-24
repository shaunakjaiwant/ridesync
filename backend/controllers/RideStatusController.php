<?php

namespace RideSync\Backend\Controllers;

use DomainException;
use mysqli;
use RideSync\Backend\Services\RideLifecycleService;
use Throwable;

final class RideStatusController
{
    public function handle(mysqli $conn): void
    {
        if (!isset($_SESSION['user_id'])) {
            header('Location: /ridesync/pages/login.php');
            exit();
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            header('Location: /ridesync/pages/dashboard.php');
            exit();
        }

        $rideId = (int) ($_POST['ride_id'] ?? 0);
        if ($rideId <= 0) {
            $_SESSION['error'] = 'Invalid ride.';
            header('Location: /ridesync/pages/dashboard.php');
            exit();
        }

        if (!ridesync_csrf_is_valid()) {
            $_SESSION['error'] = 'Invalid request. Please try again.';
            $this->redirectToRide($rideId);
        }

        try {
            $message = (new RideLifecycleService($conn))->transitionOwnerRide(
                $rideId,
                (int) $_SESSION['user_id'],
                (string) ($_POST['live_status'] ?? '')
            );
            $_SESSION['success'] = $message;
        } catch (DomainException $exception) {
            $_SESSION['error'] = $exception->getMessage();
        } catch (Throwable $exception) {
            if (function_exists('ridesync_log_exception')) {
                ridesync_log_exception($exception, ['ride_id' => $rideId]);
            }
            $_SESSION['error'] = 'Could not update ride status. Please try again.';
        }

        $this->redirectToRide($rideId);
    }

    private function redirectToRide(int $rideId): void
    {
        header('Location: /ridesync/pages/ride_detail.php?id=' . $rideId);
        exit();
    }
}
