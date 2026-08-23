<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Message;

/**
 * Fired every minute: send whatever reminders are due.
 *
 * Carries nothing. The handler asks the database what is due, which is the only
 * answer that is right when the worker was down for an hour - a message carrying
 * "the reminders due at 14:03" would be a message about a minute that has passed.
 */
final class SendDuePlanningRemindersMessage {}
