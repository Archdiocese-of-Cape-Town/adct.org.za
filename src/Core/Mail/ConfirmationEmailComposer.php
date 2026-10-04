<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenPurpose;
use ADCT\ParishIntake\Core\Auth\ActionTokenService;
use ADCT\ParishIntake\Core\Directory\SenderTrust;
use ADCT\ParishIntake\Core\Ingestion\EmailAddressSafety;
use ADCT\ParishIntake\Core\Ingestion\InboundMailPolicy;
use ADCT\ParishIntake\Core\Ingestion\InboundMessageRecord;
use ADCT\ParishIntake\Core\Ports\ConfirmationActionLinkProviderInterface;
use JsonException;
use RuntimeException;

/**
 * Builds the confirmation email itself, for whichever route is sending it.
 *
 * Two routes reach this: the automatic preview the poll job sends for a freshly
 * parsed message, and a reviewer pressing "Resend confirmation" on the candidate
 * detail screen (issue #176). Both must produce the *same* email from the same
 * stored fields, or a resent preview would stop being a preview of what the
 * parish was sent the first time. So the recipient rules, the payload
 * fingerprint, the action links and the rendering live here once, and neither
 * route reimplements any of it.
 *
 * What deliberately does *not* live here is queue bookkeeping. The preview route
 * is idempotent on a stable group key and has to reconcile a concurrent enqueue;
 * the resend route wants the opposite — a fresh email every time, with fresh
 * single-use action links. That difference belongs to the callers, and is the
 * whole reason this class exists alongside them rather than inside them.
 *
 * Every call mints brand-new action tokens. A resent email therefore carries
 * links that cannot be replayed from the first one, and the tokens in an email
 * already sent stay bound to that email's own payload (ADR: E4.2 action links are
 * hashed, single-use and expiring).
 */
final class ConfirmationEmailComposer
{
    /**
     * Bumped only when the rendered email itself changes shape, so an existing
     * queue record can be told apart from one queued under older wording.
     */
    public const TEMPLATE_FINGERPRINT_VERSION = 'e4.2-confirmation-preview-v1';

    private InboundMailPolicy $inboundMailPolicy;
    private EmailAddressSafety $addressSafety;

    public function __construct(
        private readonly ActionTokenService $tokens,
        private readonly ConfirmationActionLinkProviderInterface $actionLinks,
        private readonly ConfirmationEmailRenderer $renderer,
        ?InboundMailPolicy $inboundMailPolicy = null,
        ?EmailAddressSafety $addressSafety = null
    ) {
        $this->inboundMailPolicy = $inboundMailPolicy ?? new InboundMailPolicy();
        $this->addressSafety = $addressSafety ?? new EmailAddressSafety();
    }

    /**
     * The recipient this batch may safely be sent to, or null when none may be.
     *
     * Null is the answer for an unknown sender with no safe address, an automated
     * or mailing-list reply, or a message the ingestion policy would not let us
     * write back to at all. Callers treat it as "do not send" rather than as an
     * error, because the commonest cause is a sender we were never able to verify.
     */
    public function recipient(ConfirmationEmailBatch $batch): ?string
    {
        $message = new InboundMessageRecord(
            $batch->sourceId,
            'confirmation:' . $batch->messageId,
            null,
            $batch->senderEmail,
            $batch->senderName,
            $batch->subject,
            $batch->receivedAt,
            null,
            isAutoReply: $batch->automatedOrList
        );

        if (! $this->inboundMailPolicy->canSendConfirmation($message)) {
            return null;
        }

        return $this->potentialRecipient($batch);
    }

    /**
     * The recipient as the batch implies it, ignoring the ingestion policy.
     *
     * The stricter {@see self::recipient()} is what decides whether a *new* email
     * may be queued. This one only answers "which address does this payload
     * belong to", for reconciling an email that was already queued under the same
     * group key — refusing there would strand a row nobody can explain.
     */
    public function potentialRecipient(ConfirmationEmailBatch $batch): ?string
    {
        if (! $this->addressSafety->isSafeConfirmationAddress($batch->senderEmail)) {
            return null;
        }

        $sender = ActionTokenBinding::normalizeEmailAddress((string) $batch->senderEmail);
        $replyTo = $batch->replyToEmail;

        if (
            $replyTo !== null
            && strcasecmp(trim($replyTo), $sender) !== 0
            && $batch->replyToTrust === SenderTrust::VERIFIED
            && $this->addressSafety->isSafeConfirmationAddress($replyTo)
        ) {
            return $replyTo;
        }

        return $sender;
    }

    /**
     * The reply headers tying this email back to the parish's original message.
     *
     * Null when the original notice carried no usable `Message-ID`, which makes
     * the email unthreaded rather than unsendable. A plain-text notice from a
     * parish office often has no such header at all, so refusing to send in that
     * case would take the preview away from exactly the senders who need it.
     */
    public function threadHeaders(ConfirmationEmailBatch $batch): ?EmailThreadHeaders
    {
        return EmailThreadHeaders::fromOriginalMessageId($batch->originalMessageId);
    }

    /**
     * A fingerprint of everything that decides what this email says and who it
     * goes to.
     *
     * Deliberately covers the recipient and the thread headers as well as the
     * candidate fields, so a payload that would produce a different email can
     * never be mistaken for one already sitting in the queue under this key.
     */
    public function payloadFingerprint(
        ConfirmationEmailBatch $batch,
        string $recipient,
        ?EmailThreadHeaders $threadHeaders
    ): string {
        $candidates = [];

        foreach ($batch->candidates as $candidate) {
            $candidates[] = [
                'id' => $candidate->id,
                'fields' => $this->canonicalize($candidate->fields),
                'recurrence' => $this->canonicalize($candidate->recurrence),
                'confidence' => number_format($candidate->confidence, 3, '.', ''),
                'notes' => $candidate->notes,
                'match_kind' => $candidate->matchKind,
                'match_title' => $candidate->matchTitle,
            ];
        }

        try {
            $payload = json_encode([
                'version' => self::TEMPLATE_FINGERPRINT_VERSION,
                'message_id' => $batch->messageId,
                'source_id' => $batch->sourceId,
                'recipient' => $recipient,
                'thread_headers' => $threadHeaders?->toJson(),
                'candidates' => $candidates,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new RuntimeException('The confirmation preview payload could not be fingerprinted.', 0, $failure);
        }

        return hash('sha256', $payload);
    }

    /**
     * Render the email this batch produces for this recipient.
     *
     * The action links are minted here, once per email, so a resent preview
     * cannot carry the links from the original: each token is single-use and
     * expires on its own lifetime, and the parish's confirmed reply has already
     * consumed the previous set.
     *
     * @throws \InvalidArgumentException when the batch has no candidates, which
     *         the renderer refuses because there is no event to show
     */
    public function render(ConfirmationEmailBatch $batch, string $recipient): ConfirmationEmailContent
    {
        return $this->renderer->render($batch, $this->createActionLinks($batch, $recipient));
    }

    private function createActionLinks(
        ConfirmationEmailBatch $batch,
        string $recipient
    ): ConfirmationEmailActionLinks {
        $candidateLinks = [];

        foreach ($batch->candidates as $candidate) {
            $candidateLinks[$candidate->id] = [
                'approve' => $this->issueUrl(
                    ActionTokenPurpose::CONFIRM,
                    'event_candidate',
                    $candidate->id,
                    $recipient
                ),
                'deny' => $this->issueUrl(
                    ActionTokenPurpose::DENY,
                    'event_candidate',
                    $candidate->id,
                    $recipient
                ),
                'edit' => $this->issueUrl(
                    ActionTokenPurpose::EDIT,
                    'event_candidate',
                    $candidate->id,
                    $recipient
                ),
            ];
        }

        return new ConfirmationEmailActionLinks(
            $this->issueUrl(
                ActionTokenPurpose::CONFIRM,
                'inbound_message',
                $batch->messageId,
                $recipient
            ),
            $candidateLinks
        );
    }

    private function issueUrl(
        ActionTokenPurpose $purpose,
        string $subjectType,
        int $subjectId,
        string $recipient
    ): string {
        $issued = $this->tokens->issue(new ActionTokenBinding(
            $purpose,
            $subjectType,
            $subjectId,
            $recipient
        ));

        return $this->actionLinks->urlForToken($issued->token());
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            if (
                $value === null
                || is_string($value)
                || is_int($value)
                || is_float($value)
                || is_bool($value)
            ) {
                return $value;
            }

            throw new RuntimeException('A confirmation preview field contains an unsupported value.');
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        $keys = array_keys($value);
        usort($keys, static fn (int|string $first, int|string $second): int =>
            strcmp((string) $first, (string) $second)
        );
        $canonical = [];

        foreach ($keys as $key) {
            $canonical[$key] = $this->canonicalize($value[$key]);
        }

        return $canonical;
    }
}
