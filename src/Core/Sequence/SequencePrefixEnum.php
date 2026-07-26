<?php

declare(strict_types=1);

namespace Aurora\Core\Sequence;

/**
 * Default prefixes for all sequential business numbers.
 * Each case maps to an ApplicationParameterEnum setting that can override
 * the default at runtime via /admin/settings → Séquences.
 */
enum SequencePrefixEnum: string
{
    case Post = 'ART';
    case Form = 'FRM';
    case User = 'USR';
    case Media = 'MED';
    case AccessRequest = 'ACR';
    case FormSubmission = 'SUB';
    case Comment = 'CMT';
    case AuditLog = 'LOG';
    case ResetPasswordRequest = 'RPR';
    case MediaFolder = 'MFD';
    case MenuItem = 'MNI';
    case FormField = 'FLD';
    case TaxonomyTerm = 'TRM';
    case GedDocument = 'DOC';
}
