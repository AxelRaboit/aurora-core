<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Pipeline\FollowUp\Message;

/** Rings the follow-ups that fell due. Empty: the handler reads the date itself. */
final readonly class SendDueFollowUpsMessage {}
