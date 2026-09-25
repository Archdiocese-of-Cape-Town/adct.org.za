<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Directory;

use ADCT\ParishIntake\Core\Approval\ApproverSettings;
use ADCT\ParishIntake\Core\Directory\EmailAddress;
use ADCT\ParishIntake\WordPress\Database\Repository\DeaneryApproverRepository;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

final class DeaneryApproverAssignmentService
{
    public function __construct(private DeaneryApproverRepository $approvers)
    {
    }

    public function assignExistingUser(
        int $deaneryId,
        int $wpUserId,
        ApproverSettings $settings,
        string $timestamp
    ): int {
        $this->assertPositiveIds($deaneryId, $wpUserId);

        if (! ($this->findUser($wpUserId) instanceof \WP_User)) {
            throw new DomainException('Choose an existing WordPress user.');
        }

        $assignmentId = $this->approvers->save($deaneryId, $wpUserId, $settings, $timestamp);
        $this->synchronizeApproverRole($wpUserId);

        return $assignmentId;
    }

    public function createUserAndAssign(
        int $deaneryId,
        string $userLogin,
        string $accountEmail,
        ApproverSettings $settings,
        string $timestamp
    ): int {
        if ($deaneryId < 1) {
            throw new InvalidArgumentException('A deanery ID must be positive.');
        }

        $userLogin = sanitize_user(trim($userLogin), true);

        if ($userLogin === '' || strlen($userLogin) > 60) {
            throw new InvalidArgumentException('Enter a WordPress username of no more than 60 characters.');
        }

        $accountEmail = EmailAddress::normalize($accountEmail);

        if (strlen($accountEmail) > 100) {
            throw new InvalidArgumentException('A WordPress account email must be no longer than 100 characters.');
        }

        if (username_exists($userLogin)) {
            throw new DomainException('That WordPress username is already in use.');
        }

        if (email_exists($accountEmail)) {
            throw new DomainException('That WordPress account email is already in use.');
        }

        if (get_role('deanery_approver') === null) {
            throw new RuntimeException('The deanery approver role is not installed.');
        }

        $userId = wp_insert_user([
            'user_login' => $userLogin,
            'user_pass' => wp_generate_password(32, true, true),
            'user_email' => $accountEmail,
            'display_name' => $userLogin,
            'role' => 'deanery_approver',
        ]);

        if (is_wp_error($userId)) {
            throw new DomainException($userId->get_error_message());
        }

        if (! is_int($userId) || $userId < 1) {
            throw new RuntimeException('The WordPress approver account could not be created.');
        }

        return $this->assignExistingUser($deaneryId, $userId, $settings, $timestamp);
    }

    public function updateAssignment(
        int $assignmentId,
        int $deaneryId,
        ApproverSettings $settings,
        string $timestamp
    ): void {
        $assignment = $this->findAssignment($assignmentId, $deaneryId);
        $wpUserId = (int) ($assignment['wp_user_id'] ?? 0);

        if ($settings->active && ! ($this->findUser($wpUserId) instanceof \WP_User)) {
            throw new DomainException('The assigned WordPress user could not be found.');
        }

        $this->approvers->update($assignmentId, [
            'email' => $settings->email,
            'label' => $settings->label,
            'notify_mode' => $settings->notifyMode,
            'reminders_enabled' => $settings->remindersEnabled ? 1 : 0,
            'active' => $settings->active ? 1 : 0,
            'updated_at' => $timestamp,
        ]);
        $this->synchronizeApproverRole($wpUserId);
    }

    public function deactivateAssignment(int $assignmentId, int $deaneryId, string $timestamp): void
    {
        $assignment = $this->findAssignment($assignmentId, $deaneryId);
        $wpUserId = (int) ($assignment['wp_user_id'] ?? 0);
        $this->approvers->update($assignmentId, [
            'active' => 0,
            'updated_at' => $timestamp,
        ]);
        $this->synchronizeApproverRole($wpUserId);
    }

    private function findAssignment(int $assignmentId, int $deaneryId): array
    {
        if ($assignmentId < 1 || $deaneryId < 1) {
            throw new InvalidArgumentException('Assignment and deanery IDs must be positive.');
        }

        $assignment = $this->approvers->findById($assignmentId);

        if ($assignment === null || (int) ($assignment['deanery_id'] ?? 0) !== $deaneryId) {
            throw new DomainException('The deanery approver assignment could not be found.');
        }

        return $assignment;
    }

    private function synchronizeApproverRole(int $wpUserId): void
    {
        $hasActiveAssignment = $this->approvers->countActiveForUser($wpUserId) > 0;
        $user = $this->findUser($wpUserId);

        if (! ($user instanceof \WP_User)) {
            if ($hasActiveAssignment) {
                throw new DomainException('The assigned WordPress user could not be found.');
            }

            return;
        }

        if ($hasActiveAssignment) {
            $user->add_role('deanery_approver');
        } else {
            $user->remove_role('deanery_approver');
        }
    }

    private function findUser(int $wpUserId): \WP_User|false
    {
        if ($wpUserId < 1) {
            return false;
        }

        $user = get_user_by('id', $wpUserId);

        return $user instanceof \WP_User ? $user : false;
    }

    private function assertPositiveIds(int $deaneryId, int $wpUserId): void
    {
        if ($deaneryId < 1 || $wpUserId < 1) {
            throw new InvalidArgumentException('Deanery and WordPress user IDs must be positive.');
        }
    }
}
