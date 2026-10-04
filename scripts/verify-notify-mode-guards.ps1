$ErrorActionPreference = 'Stop'
$root = $PWD.Path
$handler = 'src/WordPress/Auth/NotifyModeChangeHandler.php'
$endpoint = 'src/WordPress/Auth/ActionTokenEndpoint.php'

$guards = @(
    @{ name = 'preview re-resolves the live user'; file = $handler
       from = @'
        if ($this->resolve($binding) === null) {
            return null;
        }

        $assignments = $this->assignments($binding->subjectId);
'@
       to = @'
        $assignments = $this->assignments($binding->subjectId);
'@
       filter = 'NotifyModeChange' },

    @{ name = 'save re-resolves the live user'; file = $handler
       from = @'
            if ($this->isForeign($binding) || $this->resolve($binding) === null) {
'@
       to = @'
            if (false) {
'@
       filter = 'NotifyModeChange' },

    @{ name = 'save refuses when no live assignment remains'; file = $handler
       from = @'
            if ($assignments === []) {
                throw new DomainException(
                    'You are no longer an approver, so this preference cannot be changed.'
                );
            }
'@
       to = ''
       filter = 'NotifyModeChange' },

    @{ name = 'save re-reads the live assignment set inside the transaction'; file = $handler
       from = @'
            $assignments = $this->assignments($binding->subjectId);

            if ($assignments === []) {
'@
       to = @'
            $assignments = [[
                'id' => 7, 'deanery_id' => 31, 'notify_mode' => Approver::NOTIFY_EACH,
            ], [
                'id' => 8, 'deanery_id' => 32, 'notify_mode' => Approver::NOTIFY_EACH,
            ]];

            if ($assignments === []) {
'@
       filter = 'NotifyModeChange' },

    @{ name = 'save consumes the token inside the transaction'; file = $handler
       from = @'
            if ($tokens->consume($secret, $binding)->status !== ActionTokenStatus::CONSUMED) {
                throw new DomainException('This link has already been used or expired.');
            }
'@
       to = ''
       filter = 'NotifyModeChange' },

    @{ name = 'save validates the submitted mode'; file = $handler
       from = @'
        if (! array_key_exists($mode, self::MODES)) {
            throw new DomainException(
                'Please choose either an email for each event or one email a day.'
            );
        }
'@
       to = ''
       filter = 'NotifyModeChange' },

    @{ name = 'save refuses a foreign binding'; file = $handler
       from = @'
    private function isForeign(ActionTokenBinding $binding): bool
    {
        return $binding->purpose !== $this->purpose()
            || $binding->subjectType !== self::SUBJECT_TYPE;
    }
'@
       to = @'
    private function isForeign(ActionTokenBinding $binding): bool
    {
        return false;
    }
'@
       filter = 'NotifyModeChange' },

    @{ name = 'resolve refuses a deactivated account'; file = $handler
       from = @'
        if ((int) $user->user_status !== 0) {
            return null;
        }
'@
       to = ''
       filter = 'NotifyModeChange' },

    @{ name = 'resolve refuses a mismatched subject id'; file = $handler
       from = @'
        if ((int) $user->ID !== $binding->subjectId) {
            return null;
        }
'@
       to = ''
       filter = 'NotifyModeChange' },

    @{ name = 'resolve refuses a reassigned account email'; file = $handler
       from = @'
        if (strtolower(trim((string) $user->user_email)) !== strtolower(trim($binding->email))) {
            return null;
        }
'@
       to = ''
       filter = 'NotifyModeChange' },

    @{ name = 'resolve requires the approve-deanery capability'; file = $handler
       from = @'
        if (! user_can($user, Capabilities::APPROVE_DEANERY)) {
            return null;
        }
'@
       to = ''
       filter = 'NotifyModeChange' },

    @{ name = 'perform refuses unconditionally (GET never acts)'; file = $handler
       from = @'
    public function perform(ActionTokenBinding $binding): ActionTokenOutcome
    {
        throw new LogicException(
            'Changing a notification mode requires a nonce-protected transactional POST.'
        );
    }
'@
       to = @'
    public function perform(ActionTokenBinding $binding): ActionTokenOutcome
    {
        return new ActionTokenOutcome('performed');
    }
'@
       filter = 'NotifyModeChange' },

    @{ name = 'the endpoint verifies the nonce before acting'; file = $endpoint
       from = @'
        if (! $this->verifyNonce($postAction, $postToken, $nonce)) {
            return new ActionTokenHttpResponse(
                403,
                $this->renderPage(
                    __('Request could not be verified', 'adct-parish-intake'),
                    __('Please reopen the link from the original email and try again.', 'adct-parish-intake')
                )
            );
        }
'@
       to = ''
       filter = 'NotifyModeChangeEndpoint' },

    @{ name = 'the endpoint binds the nonce to the token'; file = $endpoint
       from = @'
        return 'adct_pi_action_token_' . $action . '_' . hash('sha256', $token);
'@
       to = @'
        return 'adct_pi_action_token_' . $action;
'@
       filter = 'NotifyModeChangeEndpoint' },

    @{ name = 'the endpoint refuses a replayed token on POST'; file = $endpoint
       from = @'
            if ($inspection->status !== ActionTokenStatus::VALID) {
                return $this->statusResponse($inspection, $token);
            }
            try {
                $outcome = $handler->save($inspection->binding, $token, $this->tokens, $notifyMode);
'@
       to = @'
            try {
                $outcome = $handler->save($inspection->binding, $token, $this->tokens, $notifyMode);
'@
       filter = 'NotifyModeChangeEndpoint' },

    @{ name = 'the endpoint re-checks the preview before acting'; file = $endpoint
       from = @'
        if ($inspection->status === ActionTokenStatus::VALID && $handler->preview($inspection->binding) === null) {
            return $this->invalidResponse();
        }
'@
       to = ''
       filter = 'NotifyModeChangeEndpoint' },

    @{ name = 'the endpoint requires a post action of perform or renew'; file = $endpoint
       from = @'
        if (! in_array($postAction, ['perform', 'renew'], true)) {
'@
       to = @'
        if (false) {
'@
       filter = 'NotifyModeChangeEndpoint' }
)

$survived = @()
$notProven = @()

foreach ($guard in $guards) {
    $path = Join-Path $root $guard.file
    $original = [System.IO.File]::ReadAllText($path)

        # Sources in this repository may be LF or CRLF depending on which editor
        # wrote them last (core.autocrlf is true here). Normalise before matching so
        # a guard is never silently skipped over a line-ending difference.
        $search = $original.Replace("`r`n", "`n")
        $needle = $guard.from.Replace("`r`n", "`n")

        if (-not $search.Contains($needle)) {
            Write-Output "SKIP  $($guard.name): the guard text no longer matches the file."
            $notProven += $guard.name
            continue
        }

    $replacement = $guard.to.Replace("`r`n", "`n")
        $patched = $search.Replace($needle, $replacement)
        if ($original.Contains("`r`n")) {
            $patched = $patched.Replace("`n", "`r`n")
        }
        [System.IO.File]::WriteAllText($path, $patched)

    $output = docker run --rm -v "${PWD}:/app" -w /app php:8.2-cli vendor/bin/phpunit `
        --filter $guard.filter --no-coverage 2>&1 | Out-String

    [System.IO.File]::WriteAllText($path, $original)

    $line = ([regex]::Match($output, 'Tests: \d+, Assertions: \d+')).Value
    $failed = $output -match 'FAILURES!|ERRORS!|Fatal error'

    if ($failed) {
        Write-Output "PROVEN  $($guard.name)  ->  $line"
    } else {
        Write-Output "NOT PROVEN  $($guard.name)  ->  $line (suite still green with the guard removed)"
        $notProven += $guard.name
    }
}

Write-Output ""
Write-Output "guards checked: $($guards.Count); not proven: $($notProven.Count)"
foreach ($name in $notProven) { Write-Output "  - $name" }
