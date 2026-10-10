<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\ClientNotice\Enum;

/**
 * What the studio did that a client would want to know.
 *
 * Each case is one line of the client's digest and of « Depuis votre
 * dernière visite » on their page, and names the tab of their space it
 * opens. A case is added, never renamed: the value is stored on every
 * notice.
 */
enum ClientNoticeTypeEnum: string
{
    /** The team wrote in a conversation open to the client. */
    case StudioMessage = 'studio_message';

    /** The team commented on a content the client sees. */
    case StudioComment = 'studio_comment';

    /** A content reached the client's step: their opinion is asked. */
    case AwaitingReview = 'awaiting_review';

    /** An answer was cleared because the content changed under it. */
    case ApprovalReset = 'approval_reset';

    /** A content's review deadline is tomorrow and it is still unanswered. */
    case ReviewDue = 'review_due';

    /** A file was shared with the client, on a content or in Files. */
    case FileShared = 'file_shared';

    /** A link, a text or a contact was pinned for the client. */
    case ResourceShared = 'resource_shared';

    /** A page or a presentation was made visible in the space. */
    case DeliverableShared = 'deliverable_shared';

    /** The tab of the client's page the line opens on. */
    public function view(): string
    {
        return match ($this) {
            self::StudioMessage => 'chat',
            self::StudioComment, self::AwaitingReview, self::ApprovalReset, self::ReviewDue => 'calendar',
            self::FileShared => 'files',
            self::ResourceShared => 'resources',
            self::DeliverableShared => 'documents',
        };
    }

    /** The key of the line, with `{count}` and `{names}`, read in the customer's language. */
    public function lineKey(): string
    {
        return sprintf('studio.client_notices.lines.%s', $this->value);
    }
}
