<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Auth;

/**
 * The name of the POST field the notify-mode form submits, and the name of the
 * preview form field that carries the currently stored choice.
 *
 * The confirmation page renders outside the theme, so the control cannot be a
 * WordPress form control with a settings API name. One constant, shared by the
 * handler that validates it and the endpoint that renders and reads it, keeps
 * the two from drifting apart: a rename that touched only one of them would
 * leave the form silently un-submittable, and a test that only exercised one
 * side would not notice.
 */
final class NotifyModeField
{
    /** The POST field the confirmation form submits. */
    public const POST_FIELD = 'adct_notify_mode';

    /**
     * The key the handler puts the stored mode under in
     * ActionTokenPreview::$formFields, so the form can preselect it.
     */
    public const FORM_FIELD = 'notify_mode';

    private function __construct()
    {
    }
}