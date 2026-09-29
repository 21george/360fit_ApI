<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Studio class bookings with waitlist promotion (Feature 9).
 */
class StudioBookingController
{
    private const FRONT_DESK_ROLES = ['owner', 'admin', 'manager', 'front_desk'];

    // POST /client/classes/:id/book
    public function bookAsClient(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId]);
        if (!$client) Response::notFound('Client not found');

        $this->book($client['coach_id'], new ObjectId($params['id']), $clientId);
    }

    // POST /org/classes/:id/book
    public function bookAsStaff(array $params): void
    {
        if (!in_array($params['_role'], self::FRONT_DESK_ROLES, true)) {
            Response::error('Forbidden: insufficient role to book classes', 403);
        }
        $body = Request::body();
        $errors = Request::validate($body, ['client_id' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $orgId    = new ObjectId($params['_org_id']);
        $clientId = new ObjectId((string) $body['client_id']);
        $client   = Database::collection('clients')->findOne(['_id' => $clientId, 'coach_id' => $orgId]);
        if (!$client) Response::notFound('Client not found');

        $this->book($orgId, new ObjectId($params['id']), $clientId);
    }

    private function book(ObjectId $orgId, ObjectId $classId, ObjectId $clientId): void
    {
        $class = Database::collection('class_schedules')->findOne(['_id' => $classId, 'org_id' => $orgId]);
        if (!$class) Response::notFound('Class not found');

        $existing = Database::collection('class_bookings')->findOne([
            'class_schedule_id' => $classId,
            'client_id'         => $clientId,
            'status'            => ['$in' => ['confirmed', 'waitlisted']],
        ]);
        if ($existing) Response::error('Already booked for this class', 409);

        // Atomic capacity claim: serialize via findOneAndUpdate on class_schedules.
        // confirmed_count is initialized to 0 when the class is created.
        $claim = Database::collection('class_schedules')->findOneAndUpdate(
            [
                '_id' => $classId,
                '$or' => [
                    ['confirmed_count' => ['$exists' => false]],
                    ['confirmed_count' => ['$lt' => (int) $class['capacity']]],
                ],
            ],
            ['$inc' => ['confirmed_count' => 1]],
            ['returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
        );

        $now = new UTCDateTime();
        if ($claim && ($claim['confirmed_count'] ?? 0) <= (int) $class['capacity']) {
            $status = 'confirmed';
            $waitlistPosition = null;
        } else {
            $status = 'waitlisted';
            $waitlistPosition = 1 + Database::collection('class_bookings')->countDocuments([
                'class_schedule_id' => $classId,
                'status'            => 'waitlisted',
            ]);
        }

        $insert = Database::collection('class_bookings')->insertOne([
            'org_id'            => $orgId,
            'class_schedule_id' => $classId,
            'client_id'         => $clientId,
            'status'            => $status,
            'waitlist_position' => $waitlistPosition,
            'booked_at'         => $now,
            'cancelled_at'      => null,
        ]);

        Response::success([
            'id'                => (string) $insert->getInsertedId(),
            'status'            => $status,
            'waitlist_position' => $waitlistPosition,
        ], $status === 'confirmed' ? 'Booking confirmed' : 'Added to waitlist', 201);
    }

    // POST /client/bookings/:id/cancel
    public function cancelAsClient(array $params): void
    {
        $clientId = new ObjectId($params['_auth']['sub']);
        $booking  = Database::collection('class_bookings')->findOne(['_id' => new ObjectId($params['id']), 'client_id' => $clientId]);
        if (!$booking) Response::notFound('Booking not found');

        $this->cancel($booking);
    }

    // POST /org/bookings/:id/cancel
    public function cancelAsStaff(array $params): void
    {
        if (!in_array($params['_role'], self::FRONT_DESK_ROLES, true)) {
            Response::error('Forbidden: insufficient role to cancel bookings', 403);
        }
        $orgId   = new ObjectId($params['_org_id']);
        $booking = Database::collection('class_bookings')->findOne(['_id' => new ObjectId($params['id']), 'org_id' => $orgId]);
        if (!$booking) Response::notFound('Booking not found');

        $this->cancel($booking);
    }

    private function cancel(array $booking): void
    {
        if ($booking['status'] === 'cancelled') Response::error('Booking already cancelled', 409);

        $wasConfirmed = $booking['status'] === 'confirmed';
        $classId      = $booking['class_schedule_id'];

        Database::collection('class_bookings')->updateOne(
            ['_id' => $booking['_id']],
            ['$set' => ['status' => 'cancelled', 'cancelled_at' => new UTCDateTime(), 'waitlist_position' => null]]
        );

        if ($wasConfirmed) {
            $next = Database::collection('class_bookings')->findOne(
                ['class_schedule_id' => $classId, 'status' => 'waitlisted'],
                ['sort' => ['waitlist_position' => 1]]
            );
            if ($next) {
                Database::collection('class_bookings')->updateOne(
                    ['_id' => $next['_id']],
                    ['$set' => ['status' => 'confirmed', 'waitlist_position' => null]]
                );
                // Slot refilled by promotion — confirmed_count stays the same.
            } else {
                // No waitlist to fill the slot — decrement confirmed count.
                Database::collection('class_schedules')->updateOne(
                    ['_id' => $classId],
                    ['$inc' => ['confirmed_count' => -1]]
                );
            }
        }

        Response::success(null, 'Booking cancelled');
    }
}
